<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Helpers\Api;
use App\Models\Entity;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;

trait BaselinkerDeliverable
{
    /**
     * Max length for text on label. DPD/Baselinker API returns TEXT_ON_LABEL_TOO_LONG when exceeded.
     */
    protected const TEXT_ON_LABEL_MAX_LENGTH = 35;

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
        //TODO: Implement cost calculation logic

        return 60.0; // Example fixed cost
    }

    public static function getFieldsFor(ParcelableContract $parcelable): array
    {
        $packageDescription = $parcelable->text_for_parcel ?? '';
        if (mb_strlen($packageDescription) > self::TEXT_ON_LABEL_MAX_LENGTH) {
            $packageDescription = mb_substr($packageDescription, 0, self::TEXT_ON_LABEL_MAX_LENGTH);
        }

        $referenceNumber = $parcelable->id . ' ' . $parcelable->baselinker_id;
        if (mb_strlen($referenceNumber) > self::TEXT_ON_LABEL_MAX_LENGTH) {
            $referenceNumber = mb_substr($referenceNumber, 0, self::TEXT_ON_LABEL_MAX_LENGTH);
        }

        $collection = collect([
//            'courier'             => AllegroOneCourierIds::COURIER_IDS[$parcelable->carrier_id ?: 'Allegro Kurier One'] ?? 'detect',
            'courier'             => 'detect',
            'services_additional' => null,
            'package_type'        => 'PACKAGE',
            'cod'                 => number_format($parcelable->cod_for_parcel, 2, '.', ''),
            'insurance'           => number_format(ceil($parcelable->value_for_parcel), 2, '.', ''),
            'package_description' => $packageDescription,
            'reference_number'    => $referenceNumber,
            'currency_insurance'  => $parcelable->currency,
        ]);

        return $collection->filter()->map(fn($v, $k) => ['id' => $k, 'value' => $v])->values()->toArray();
    }

    public static function getPackagesFor(ParcelableContract $parcelable): array
    {
        $packaging = $parcelable->recommended_packaging;

        $textOnLabel = $parcelable->id . ' ' . $parcelable->baselinker_id;
        if (mb_strlen($textOnLabel) > self::TEXT_ON_LABEL_MAX_LENGTH) {
            $textOnLabel = mb_substr($textOnLabel, 0, self::TEXT_ON_LABEL_MAX_LENGTH);
        }

        # TODO implement logic for multiple packages
        return array_filter([
            [
                'weight'      => $parcelable->weight,
                'size_width'  => $packaging->getWidth(),
                'size_height' => $packaging->getHeight(),
                'size_length' => $packaging->getLength(),
                'textOnLabel' => $textOnLabel,
            ],
        ]);
    }
}
