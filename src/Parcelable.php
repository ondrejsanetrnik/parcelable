<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Obstacle;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Ondrejsanetrnik\Core\CoreResponse;
use Ondrejsanetrnik\Parcelable\enums\CarrierId;

trait Parcelable
{
    /**
     * @throws Exception
     */
    public function __construct(...$args)
    {
        if (!$this instanceof ParcelableContract) throw new Exception('Classes using Parcelable must implement ParcelableContract.');

        parent::__construct(...$args);
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
            'PACKETA', 'Packeta', 'Zásilkovna', 'Zásilkovna na adresu' => 'Zásilkovna',
            'GlsParcelShop', 'GLS ParcelShop', 'Zaslat na adresu', 'GLS' => 'GLS',
            'Balíkovna', 'BalikovnaNaAdresu' => 'Balíkovna',
            'Allegro One' => 'Allegro One',
            'DPD' => 'DPD',
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

        if ($carrierName == 'Balíkovna' && $this->aukro_id)
            return 'Ondrejsanetrnik\Parcelable\BalikovnaAukro';

        return Parcel::CARRIER_CLASS[$carrierName];
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
            'SK' => CarrierId::SK_PACKETA_HD->value,
            'CZ' => CarrierId::CZ_PACKETA_HD->value,
            'AT' => CarrierId::AT_AUSTRIAN_POST_HD->value,
            'HU' => CarrierId::HU_HUNGARIAN_POST_HD->value,
            'DE' => CarrierId::DE_HERMES_HD->value,
            default => abort(500, 'Home delivery not supported in ' . $this->country),
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

    public function getAddressIdAttribute(): ?string
    {
        if ($this->carrier_name == 'Zásilkovna') {
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
        if (!$this->email) return false;

        if (in_array($this->country, [
            'CZ',
            'SK',
            'HU',
            'RO',
            'SI',
            'HR',
            'DE',
            'AT',
            'BE',
            'LU',
            'NL',
            'DK',
            'ES',
            'FR',
            'PT',
        ])) {
            return true;
        } elseif (in_array($this->country, [
            'PL',
            'GR',
        ])) {
            return $this->payment != 'Dobírka';
        } else return false;
    }

    public function getDefaultParcelTypeAttribute(): string
    {
        return 'parcel';
    }

    public function getParcelCountAttribute(): int
    {
        return $this->getRawOriginal('parcel_count') ?? 1;
    }

    public function getPacketaParcelAttributesAttribute(): array
    {
        return [
            'number'             => $this->id,
            'name'               => $this->first_name,
            'surname'            => $this->last_name,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'street'             => $this->street,
            'houseNumber'        => $this->house_number,
            'city'               => $this->city,
            'zip'                => in_array($this->country, [
                'CZ',
                'SK',
            ]) ? substr_replace($this->postal_code ?? '', ' ', 3, 0) : $this->postal_code,
            'addressId'          => $this->address_id,
            'carrierPickupPoint' => $this->carrier_pickup_point,
            'currency'           => $this->national_currency,
            'size'               => $this->size_for_external_carrier,
            'cod'                => $this->cod_for_parcel,
            'value'              => $this->value_for_parcel,
            'weight'             => min(10, $this->weight / 0.5 ?: 1),
            'eshop'              => $this->eshop,
        ];
    }

    public function validateParcel(): bool
    {
        switch ($this->carrier_name) {
            case 'Zásilkovna':
                $response = Packeta::packetAttributesValid($this->packeta_parcel_attributes);

                if (!$response->success && $response->message !== 'Bad Gateway') {
                    Obstacle::firstOrCreate([
                        'type'     => 'parcel',
                        'message'  => $response->message,
                        'order_id' => $this->id,
                    ], [
                        'state' => 'danger',
                    ]);
                }

                $this->update(['parcel_validated_at' => now()]);

                return $response->success;
            default:
                abort(501, 'Carrier ' . $this->carrier_name . ' not supported for validation');
        }
    }

    public function getShippingCostAttribute()
    {
        return $this->is_delivered_by_shipping ? $this->carrier_class::getCostFor($this) : 0;
    }

    public function getAllegroOneFieldsAttribute(): array
    {
        return AllegroOne::getFieldsFor($this);
    }

    public function getAllegroOnePackagesAttribute(): array
    {
        return AllegroOne::getPackagesFor($this);
    }

    public function getCarrierIdInferredAttribute(): ?int
    {
        $hdId = $this->delivery === 'Zásilkovna na adresu' ? $this->home_delivery_address_id : null;

        return (int)$this->carrier_id ?: $hdId;
    }
}
