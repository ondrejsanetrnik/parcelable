<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Helpers\Api;
use App\Models\Entity;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;

class AllegroOne
{

    public const STATUSES = [
        0  => 'Čeká na vyzvednutí kurýrem', # Unknown
        1  => 'Čeká na vyzvednutí kurýrem', # Courier label created
        2  => 'V přepravě', # Shipped
        3  => 'Na cestě zpátky', # Not delivered
        4  => 'Doručována', # Out for delivery
        5  => 'Doručena', # Delivered
        6  => 'Vrácena obchodu', # Return
        7  => 'V přepravě', # Aviso
        8  => 'Připravena k vyzvednutí', # Waiting at point
        9  => 'V přepravě', # Lost
        10 => 'Stornována', # Canceled
        11 => 'V přepravě', # On the way
        12 => 'V přepravě', # Exception (sorting error, other event, complaint)
        13 => 'V přepravě', # Transferred abroad
    ];

    /**
     * Creates a proto parcel object from given entity
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
        $coreResponse = new CoreResponse();

        if (!$entity->baselinker_id) return $coreResponse->fail('Objednávka nemá Baselinker ID nutné pro vytvoření zásilky.');

        $baselinker = Api::baselinker();

        $response = $baselinker->courierShipments()->createPackage($entity->baselinker_id, 'allegrokurier', $entity->allegro_one_fields, $entity->allegro_one_packages, 15703);

        $trackingNumber = $response->getParameter('package_number');

        if (self::isBarcode($trackingNumber)) $trackingNumber = self::trackingNumberFromBarcode($trackingNumber);

        $protoParcels = [
            (object)[
                'external_id' => $response->getParameter('package_id'),
                'id'          => $trackingNumber,
            ],
        ];

        $response = $baselinker->courierShipments()->getLabel('allegrokurier', [
            'package_id'     => $protoParcels[0]->external_id,
            'package_number' => $protoParcels[0]->id,
        ]);

        $labelBase64 = $response->getParameter('label');

        $pdf = base64_decode($labelBase64, true);

        if ($pdf === false) return $coreResponse->fail('Chyba při dekódování PDF štítku z Baselinkeru.');

        $labelName = str_replace('*', '-', $protoParcels[0]->id) . '.pdf';

        Storage::disk('private')->put('labels/' . $labelName, $pdf);

        return $coreResponse->success($protoParcels);
    }


    /**
     * Checks the parcel status in Allegro API
     *
     * @param int|string $parcelNumber
     * @return CoreResponse
     */
    public static function getParcelStatus(int|string $parcelNumber): CoreResponse
    {
        $response = new CoreResponse();

        $response->message = 'Zásilky z Allegro one jsou kontrolovány hromadně přes Baselinker API. Pro kontrolu stavu zásilky použijte call UpdateAllegroOneParcelStatuses.';

        return $response->success();
    }

    public static function getCostFor(ParcelableContract $parcelable): float
    {
        //TODO: Implement cost calculation logic

        return 60.0; // Example fixed cost
    }

    public static function getFieldsFor(ParcelableContract $parcelable): array
    {
        $collection = collect([
            'courier'             => AllegroOneCourierIds::COURIER_IDS[$parcelable->carrier_id ?: 'Allegro Kurier One'],
            'services_additional' => null,
            'package_type'        => 'PACKAGE',
            'cod'                 => number_format($parcelable->cod_for_parcel, 2, '.', ''),
            'insurance'           => number_format($parcelable->value_for_parcel, 2, '.', ''),
            'package_description' => $parcelable->text_for_parcel,
            'reference_number'    => $parcelable->id . ' ' . $parcelable->baselinker_id,
            'currency_insurance'  => $parcelable->currency,
        ]);

        return $collection->filter()->map(fn($v, $k) => ['id' => $k, 'value' => $v])->values()->toArray();
    }

    public static function getPackagesFor(ParcelableContract $parcelable): array
    {
        $packaging = $parcelable->recommended_packaging;

        //TODO implement logic for multiple packages
        return array_filter([
            [
                'weight'      => $parcelable->weight,
                'size_width'  => $packaging->getWidth(),
                'size_height' => $packaging->getHeight(),
                'size_length' => $packaging->getLength(),
            ],
        ]);
    }

    /**
     * Extracts the tracking number (the number without the order letter)
     *
     * @param string $barcode
     * @return string
     */
    public static function trackingNumberFromBarcode(string $barcode): string
    {
        if (str_starts_with($barcode, 'A00') && strlen($barcode) == 10) return $barcode; # A002MH8633 - new format?
        if (str_starts_with($barcode, '4RA') && strlen($barcode) == 11) return $barcode; # 4RA00179561 - new format 2??

        $rx = '~\b(?P<prefix>[A-Z0-9]{3})(?P<flag>[A-Z])(?P<number>\d{8})(?:\*(?P<piece>\d{3})(?P<total>\d{3}))?\b~';

        if (preg_match($rx, $barcode, $m)) {
            $barcode = $m[0];                             # "4RAM00168524*001001"
            $tracking = $m['prefix'] . $m['number'];      # "4RA00168524" (4th letter removed, suffix dropped)

            return $tracking;
        } else return '';
    }

    public static function isBarcode(string $barcode): bool
    {
        return boolval(self::trackingNumberFromBarcode($barcode));
    }
}
