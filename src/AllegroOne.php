<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Entity;
use Baselinker\Baselinker;
use Illuminate\Support\Facades\App;
use Ondrejsanetrnik\Core\CoreResponse;

class AllegroOne
{

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
        $response = new CoreResponse();

        if (App::isProduction())
            return $response->fail('Podávání zásilek přes Allegro One zatím není podporováno - odlož balíček a pokračuj na další.'); // TODO: Implement createFrom method

        if (!$entity->baselinker_id) return $response->fail('Objednávka nemá Baselinker ID nutné pro vytvoření zásilky.');

        $baselinker = new Baselinker(['token' => config('env.BASELINKER_TOKEN')]);

//        $response = $baselinker->courierShipments()->getCourierFields('one_by_allegro');
        $response = $baselinker->courierShipments()->getCourierFields('allegrokurier');
        dump($response->toArray());
//        $response = $baselinker->courierShipments()->getCouriersList();
//        $response = $baselinker->courierShipments()->getCourierAccounts('allegrokurier');
        $response = $baselinker->courierShipments()->createPackage($entity->baselinker_id, 'allegrokurier', $entity->allegro_one_fields, $entity->allegro_one_packages, 15703);

        dd($response->toArray());

        return $response;
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

        return $response->fail('Not implemented yet.'); // TODO: Implement getParcelStatus method
    }

    public static function getCostFor(ParcelableContract $parcelable): float
    {
        //TODO: Implement cost calculation logic

        return 60.0; // Example fixed cost
    }

    public static function getFieldsFor(ParcelableContract $parcelable): array
    {
        $collection = collect([
//            'service'             => 'STANDARD',
'courier'              => 'allegrokurier',
'inpost_dispatch_type' => 'dispatch_order',
'services_additional'  => null,
'package_type'         => 'PACKAGE',
'cod'                  => $parcelable->cod_for_parcel,
'insurance'            => $parcelable->value_for_parcel,
'package_description'  => $parcelable->text_for_parcel,
'reference_number'     => $parcelable->id . ' ' . $parcelable->baselinker_id,
//            'reference_number_2'  => $parcelable->baselinker_id,
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
