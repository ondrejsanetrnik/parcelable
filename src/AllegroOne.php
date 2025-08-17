<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Helpers\Api;
use App\Models\Entity;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;

class AllegroOne
{

    public const COURIER_IDS = [
        'Allegro Kurier One'               => '0856a050-3310-44eb-8ad6-0a3151c1da58',
        'Allegro Automaty Paczkowe One'    => '3acc38bc-1db9-4238-b7f7-11c76ab6e905',
        'Allegro Odbiór w Punkcie One pob' => '1cfe0620-9616-4d36-9222-a3f8ed84a513',
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

        # pokud je 4. znak písmeno (O, S, I, ...), smazat ho
        if (strlen($trackingNumber) > 3 && ctype_alpha($trackingNumber[3])) $trackingNumber = substr($trackingNumber, 0, 3) . substr($trackingNumber, 4);

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

        return $response->success(); // TODO: Implement getParcelStatus method once there are histories in Allegro API
    }

    public static function getCostFor(ParcelableContract $parcelable): float
    {
        //TODO: Implement cost calculation logic

        return 60.0; // Example fixed cost
    }

    public static function getFieldsFor(ParcelableContract $parcelable): array
    {
        $collection = collect([
            'courier'             => self::COURIER_IDS[$parcelable->carrier_id ?: 'Allegro Kurier One'],
            'services_additional' => null,
            'package_type'        => 'PACKAGE',
            'cod'                 => $parcelable->cod_for_parcel,
            'insurance'           => $parcelable->value_for_parcel,
            'package_description' => $parcelable->text_for_parcel,
            'reference_number'    => $parcelable->id . ' ' . $parcelable->baselinker_id,
            'currency_insurance'  => 'CZK',
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
}
