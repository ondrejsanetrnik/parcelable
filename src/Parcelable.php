<?php

namespace Ondrejsanetrnik\Parcelable;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Ondrejsanetrnik\Core\CoreResponse;

trait Parcelable
{
    /**
     * @throws Exception
     */
    public function __construct()
    {
        if (!$this instanceof ParcelableContract) throw new Exception("Classes using Parcelable must implement ParcelableContract.");
    }

    public function parcels(): MorphMany
    {
        return $this->morphMany(Parcel::class, 'parcelable')->orderByDesc('id');
    }

    public function getParcelAttribute()
    {
        return $this->parcels->first();
    }

    /**
     * Creates a new parcel
     *
     * @param string $type = ''
     * @return CoreResponse
     */
    public function createParcel(string $type = ''): CoreResponse
    {
        $type = $type ?: $this->default_parcel_type;
        $response = Parcel::createFrom($this, $type);

        if ($response->success) {
            foreach ($response->data as $parcel) {
                $this->parcels()->save($parcel);

                $method = method_exists($this, 'createParcelableEvent') ? 'createParcelableEvent' : 'createEvent';
                $this->$method(
                    [
                        'type'  => 'packetSend',
                        'data'  => $parcel->tracking_number,
                        'title' => 'Zásilka vytvořena u <b>' . $this->carrier_name . '</b> pod číslem <b>' . $parcel->tracking_number . '</b>',
                    ]
                );
            }
        }

        return $response;
    }

    public function getPacketaIdAttribute()
    {
        return $this->packeta;
    }

    public function getCarrierNameAttribute(): ?string
    {
        return match ($this->delivery) {
            'PACKETA', 'Packeta', 'Zásilkovna' => 'Zásilkovna',
            'GlsParcelShop', 'Zaslat na adresu', 'GLS' => 'GLS',
            default => null,
        };
    }

    public function getCarrierClassAttribute(): string
    {
        $carrierName = $this->carrier_name;

        if (!$carrierName) {
            dump($this);
            abort(501, 'Carrier name is not set for ' . $this->model_name . ' ' . $this->id);
        }

        return Parcel::CARRIER_CLASS[$this->carrier_name];
    }

    public function getTrackingNumberAttribute()
    {
        return $this->parcel?->tracking_number;
    }

    public function getPacketaPasswordAttribute()
    {
        return $this->parcel?->password;
    }

    public function getParcelStatusAttribute()
    {
        return $this->parcel?->status;
    }

    public function getStoredUntilAttribute()
    {
        return $this->parcel?->stored_until;
    }

    /**
     * @return void
     */
    public function convertTrackingNumberToParcel(): void
    {
        $trackingNumber = $this->getRawOriginal('tracking_number');

        if (
            $trackingNumber &&
            strlen($trackingNumber) < 16 &&
            $this->carrier_name
        ) {
            $parcel = Parcel::firstOrCreate([
                'tracking_number' => $trackingNumber,
                'carrier'         => $this->carrier_name,
            ], [
                'status'       => $this->getRawOriginal('parcel_status'),
                'type'         => $this->model_name == 'order' ? 'parcel' : 'claim',
                'name'         => $this->name,
                'stored_until' => $this->getRawOriginal('stored_until'),
                'cod'          => $this->payment == 'Dobírka' ? $this->total : null,
            ]);

            $parcel->unsetEventDispatcher();

            $this->parcels()->save($parcel);
        }
    }

    /**
     * @return int
     */
    public function getHomeDeliveryAddressIdAttribute(): int
    {
        return match ($this->country) {
            'SK' => 131,
            default => 106,
        };
    }

    public function getIsExternalPickupPointAttribute(): bool
    {
        return $this->packeta && $this->carrier_id;
    }

    public function getCarrierPickupPointAttribute(): ?string
    {
        return $this->is_external_pickup_point ? $this->packeta_id : null;
    }

    public function getAddressIdAttribute(): ?int
    {
        if ($this->delivery == 'Zásilkovna') {
            if ($this->is_zasilkovna_on_address) return $this->home_delivery_address_id;
            elseif ($this->is_external_pickup_point) return $this->carrier_id;
            else return $this->packeta_id;
        } else return null;
    }

    /**
     * Replaces old calls for parcel_status property with a scope
     *
     * @param Builder $query
     * @param string $status
     * @return Builder
     */
    public function scopeWhereParcelStatus(Builder $query, string $status): Builder
    {
        return $query->whereHas('parcels', fn($q) => $q->where('status', $status));
    }

    /**
     * @return int[]|null
     */
    public function getSizeForExternalCarrierAttribute(): ?array
    {
        $itemCount = $this->items?->count();
        return match ($this->biggest_format) {
            'BIG' => [
                'length' => 400,
                'width'  => 300,
                'height' => 175 + $itemCount * 25,
            ],
            'LP' => [
                'length' => 398,
                'width'  => 329,
                'height' => 30 + $itemCount * 10,
            ],
            'CD' => [
                'length' => 196,
                'width'  => 142,
                'height' => 2 + $itemCount * 10,
            ],
            default => null,
        };
    }

    public function getFdsAvailableAttribute(): bool
    {
        return in_array($this->country, [
            'CZ',
            'SK',
            'HU',
            'RO',
            'SI',
            'HR',
            'DE',
            'AT',
            'PL',
            'BE',
            'LU',
            'NL',
            'DK',
            'ES',
            'FR',
            'GR',
            'PT',
        ]);
    }

    public function getDefaultParcelTypeAttribute(): string
    {
        return 'parcel';
    }

    public function getParcelCountAttribute(): int
    {
        return $this->getRawOriginal('parcel_count') ?? 1;
    }
}
