<?php

namespace Ondrejsanetrnik\Parcelable;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Ondrejsanetrnik\Core\CoreResponse;

trait Parcelable
{
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
        $type = $type ?: $this->defaultParcelType;
        $response = Parcel::createFrom($this, $type);

        if ($response->success) {
            foreach ($response->data as $parcel) {
                $this->parcels()->save($parcel);
                $this->createEvent(
                    [
                        'type'  => 'packetSend',
                        'data'  => $parcel->tracking_number,
                        'title' => 'Zásilka vytvořena u <b>' . $this->carrierName . '</b> pod číslem <b>' . $parcel->tracking_number . '</b>',
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

    public function getCarrierNameAttribute()
    {
        switch ($this->delivery) {
            case 'PACKETA':
            case 'Packeta':
            case 'Zásilkovna':
                return 'Zásilkovna';
            case 'GlsParcelShop':
            case 'Zaslat na adresu':
            case 'GLS':
                return 'GLS';
        }
    }

    public function getCarrierClassAttribute(): string
    {
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

    /**
     * @return void
     */
    public function convertTrackingNumberToParcel(): void
    {
        $trackingNumber = $this->getRawOriginal('tracking_number');

        if (
            $trackingNumber &&
            strlen($trackingNumber) < 16 &&
            $this->carrierName
        ) {
            $parcel = Parcel::firstOrCreate([
                'tracking_number' => $trackingNumber,
                'carrier'         => $this->carrierName,
            ], [
                'status'       => $this->getRawOriginal('parcel_status'),
                'type'         => $this->modelName == 'order' ? 'parcel' : 'claim',
                'name'         => $this->name,
                'stored_until' => $this->getRawOriginal('stored_until'),
                'cod'          => $this->payment == 'Dobírka' ? $this->total : null,
            ]);

            $parcel->unsetEventDispatcher();

            $this->parcels()->save($parcel);
        }
    }
}
