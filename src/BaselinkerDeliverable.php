<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Helpers\Api;
use App\Models\Entity;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;

trait BaselinkerDeliverable
{
    /**
     * Creates a proto parcel object from given entity
     *
     * @param Entity $entity
     * @param string $type = ''
     * @return CoreResponse
     */
    public static function createFrom(
        Entity $entity,
        string $type = ''
    ): CoreResponse {
        $coreResponse = new CoreResponse();

        if (!$entity->baselinker_id) return $coreResponse->fail('Objednávka nemá Baselinker ID nutné pro vytvoření zásilky.');

        $baselinker = Api::baselinker();

        $courierCode = static::COURIER_CODE;

        try {
            $response = $baselinker->courierShipments()->createPackage($entity->baselinker_id, $courierCode, $entity->allegro_one_fields, $entity->allegro_one_packages, 15703);
        } catch (\Throwable $e) {
            return $coreResponse->fail('Chyba při komunikaci s Baselinker API: ' . $e->getMessage());
        }

        $trackingNumber = $response->getParameter('package_number');

        if (self::isBarcode($trackingNumber)) $trackingNumber = self::trackingNumberFromBarcode($trackingNumber);

        $protoParcels = [
            (object)[
                'external_id' => $response->getParameter('package_id'),
                'id'          => $trackingNumber,
            ],
        ];

        $response = $baselinker->courierShipments()->getLabel($courierCode, [
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

        $response->message = 'Zásilky z Baselinkeru jsou kontrolovány hromadně přes Baselinker API. Pro kontrolu stavu zásilky použijte call UpdateBaselinkerParcelStatuses.';

        return $response->success();
    }

    public static function getCostFor(ParcelableContract $parcelable): float
    {
        // TODO: Implement cost calculation logic

        return 60.0; // Example fixed cost
    }

    /**
     * Fields for createPackage per Base support: courier, insurance, package_type, cod (with decimal).
     * Optional extras (reference_number, package_description) omitted to avoid label length errors.
     */
    public static function getFieldsFor(ParcelableContract $parcelable): array
    {
        $fields = [
            ['id' => 'courier', 'value' => 'detect'],
            ['id' => 'insurance', 'value' => number_format(ceil($parcelable->value_for_parcel), 2, '.', '')],
            ['id' => 'package_type', 'value' => 'PACKAGE'],
        ];

        $cod = number_format($parcelable->cod_for_parcel, 2, '.', '');
        if ((float)$cod > 0) {
            $fields[] = ['id' => 'cod', 'value' => $cod];
        }

        return $fields;
    }

    /**
     * Package dimensions per Base sample: length, height, width, weight (no textOnLabel).
     */
    public static function getPackagesFor(ParcelableContract $parcelable): array
    {
        $packaging = $parcelable->recommended_packaging;

        return [
            [
                'length' => $packaging->getLength(),
                'height' => $packaging->getHeight(),
                'width'  => $packaging->getWidth(),
                'weight' => $parcelable->weight,
            ],
        ];
    }
}
