<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Entity;
use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;

class Parcel extends Entity
{
    use Filterable;

    public const URL_PREFIX = 'balicek';

    public array $localiseable = ['status'];

    public const TABLEABLED = [
        'id',
        'created_at',
        'updated_at',
        'type',
        'carrier',
        'tracking_number',
        'parcelable_id',
        'parcelable_type',
        'password',
        'status',
        'name',
        'stored_until',
    ];

    public const ON_THE_WAY_STATUSES = [
        'Čeká na vyzvednutí kurýrem',
        'Přijata k přepravě',
        'V přepravě',
        'Připravena k vyzvednutí',
        'Doručována',
        'Na cestě zpátky',
    ];

    public const MODEL_NAME_CZECH = 'Balíček';

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'stored_until' => 'date',
    ];

    public const CARRIER_CLASS = [
        'GLS'         => 'Ondrejsanetrnik\Parcelable\Gls',
        'Zásilkovna'  => 'Ondrejsanetrnik\Parcelable\Packeta',
        'Balíkovna'   => 'Ondrejsanetrnik\Parcelable\Balikovna',
        'Allegro One' => 'Ondrejsanetrnik\Parcelable\AllegroOne',
    ];

    /**
     * @param string|null $trackingNumber
     * @return Parcel|null
     */
    public static function findTrackingNumber(?string $trackingNumber): ?Parcel
    {
        if (!$trackingNumber) return null;

        return self::where('tracking_number', 'LIKE', '%' . purifyGLS($trackingNumber) . '%')->first();
    }

    public function parcelable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTrackingUrlAttribute(): string
    {
        $language = $this->parcelable?->language ?? App::getLocale();

        return match ($this->carrier) {
            'GLS' => match ($language) {
                    'cs' => 'https://gls-group.eu/CZ/cs/sledovani-zasilek',
                    'de' => 'https://www.gls-pakete.de/sendungsverfolgung',
                    default => 'https://gls-group.eu/CZ/en/parcel-tracking',
                } . '?match=' . $this->tracking_number,
            'Balíkovna' => 'https://www.balikovna.cz/cs/sledovat-balik/-/balik/' . $this->tracking_number,
            'Allegro One' => 'http://trace.wedo.cz/index.php?action=eSearch&orderNumber=' . $this->tracking_number_short,
            default => 'https://tracking.packeta.com/' . $language . '/?id=' . $this->tracking_number,
        };
    }

    public function getLabelUrlAttribute(): string
    {
        return route('label', $this->id);
    }

    public function getCarrierClassAttribute(): string
    {
        return self::CARRIER_CLASS[$this->carrier];
    }

    public function getLabelNamePdfAttribute(): string
    {
        return str_replace('*', '-', $this->tracking_number) . '.pdf';
    }

    public function getLabelPathAttribute(): string
    {
        return Storage::disk('private')->path('labels/' . $this->label_name_pdf);
    }

    /**
     * Returns only the characters up to the first asterisk
     *
     * @return string
     */
    public function getTrackingNumberShortAttribute(): string
    {
        return explode('*', $this->tracking_number)[0];
    }

    /**
     * Creates a parcel from a given entity
     *
     * @param Entity $entity
     * @param string $type = ''
     * @return CoreResponse
     *
     */
    public static function createFrom(
        Entity $entity,
        string $type = ''
    ): CoreResponse
    {
        //$order má is_zasilkovna_on_address set to true, delivery ji zůstává 'Zásilkovna'
        //přepínat a upravovat to jde  😁
        $type = $type ?: $entity->default_parcel_type;
        $response = $entity->carrierClass::createFrom($entity, $type);
        if ($response->success) {
            $parcels = [];
            # Everything allright
            foreach ($response->data as $protoParcel) {
                $parcel = Parcel::create([
                    'tracking_number' => $protoParcel->id,
                    'carrier'         => $entity->carrierName,
                    'name'            => $entity->name,
                    'cod'             => ceil($entity->cod_in_currency / ($entity->parcel_count ?: 1)),
                    'password'        => $protoParcel->password ?? null,
                    'external_id'     => $protoParcel->external_id ?? null,
                    'type'            => $type,
                    'status'          => 'Čeká na vyzvednutí kurýrem',
                ]);
                $parcels[] = $parcel;
            }

            $response->setData($parcels);
        }

        return $response;
    }

    /**
     * Updates current status
     *
     * @return CoreResponse
     */
    public function updateStatus(): CoreResponse
    {
        $response = $this->carrierClass::getParcelStatus($this->tracking_number);

        # Persist if successful
        if ($response->success && $response->data) $this->update([
            'status'       => $response->data->status,
            'stored_until' => $response->data->storedUntil ?? null,
        ]);

        return $response;
    }
}
