<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Entity;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;
use SoapClient;
use SoapFault;

/**
 * @method static packetStatus(int $parcelNumber)
 * @method static createPacketClaimWithPassword(array $array)
 * @method static packetLabelPdf($id, string $string, int $int)
 * @method static createPacket(array $array)
 * @method static packetAttributesValid(array $array)
 */
class Packeta
{
    public const STATUS_MAP = [
        'received data'          => 'Čeká na vyzvednutí kurýrem',
        'arrived'                => 'Přijata k přepravě',
        'reverse packet arrived' => 'Přijata k přepravě',
        'collected'              => 'Přijata k přepravě',
        'prepared for departure' => 'V přepravě',
        'departed'               => 'V přepravě',
        'ready for pickup'       => 'Připravena k vyzvednutí',
        'handed to carrier'      => 'Doručována',
        'delivery attempt'       => 'Doručována',
        'zbox delivery attempt'  => 'Doručována',
        'delivered'              => 'Doručena',
        'posted back'            => 'Na cestě zpátky',
        'rejected by recipient'  => 'Na cestě zpátky',
        'returned'               => 'Vrácena obchodu',
        'cancelled'              => 'Stornována',
    ];

    public static function __callStatic(string $method, array $parameters): CoreResponse
    {
        $response = new CoreResponse();
        $client = new SoapClient('http://www.zasilkovna.cz/api/soap-php-bugfix.wsdl');

        try {
            return $response->success($client->$method(
                config('parcelable.PACKETA_API_PASSWORD'),
                $parameters[0],
                $parameters[1] ?? null,
                $parameters[2] ?? null,
            ));
        } catch (SoapFault $e) {
            $message = $e->getMessage();
            if (isset($e->detail->PacketAttributesFault)) {
                $arrayOrFault = $e->detail->PacketAttributesFault->attributes->fault;
                if (is_array($arrayOrFault)) {
                    $message = $arrayOrFault[0]->fault;
                } else {
                    $message = $arrayOrFault->fault;
                }
            }

            return $response->fail($message ?? 'Prosím, řekněte mi o co jde. Díky, Ondra');
        }
    }

    /**
     * Checks the parcel status in Packeta API
     *
     * @param string|int $parcelNumber
     * @return CoreResponse
     * @throws Exception
     */
    public static function getParcelStatus(string|int $parcelNumber): CoreResponse
    {
        if (!is_int($parcelNumber)) {
            $parcelNumber = intval(ltrim($parcelNumber, 'Zz'));
        }

        $response = self::packetStatus($parcelNumber);

        if ($response->success) {
            $status = self::STATUS_MAP[$response->data->codeText] ?? null;

            if ($status === null) {
                Log::channel('separated')->warning('Packeta status not found', [
                    'code'         => $response->data->codeText,
                    'parcelNumber' => $parcelNumber,
                ]);
            }

            $response->data->status = $status;
        }

        return $response;
    }

    /**
     * Creates the parcel in Packeta API
     *
     * @param Entity $entity
     * @param string $type = ''
     * @return CoreResponse
     */
    public static function createFrom(
        Entity $entity,
        string $type = ''
    ): CoreResponse
    {
        $type = $type ?: $entity->default_parcel_type;
        $protoParcels = [];

        foreach (range(1, $entity->parcel_count) as $i) {
            switch ($type) {
                case 'parcel':
                    # Post a parcel
                    $response = self::createPacket($entity->packeta_parcel_attributes);

                    if ($response->success) {
                        # Get and save the label
                        $protoParcel = $response->data;
                        self::getLabel($protoParcel->id);
                        $protoParcels[] = $protoParcel;
                    }
                    break;

                case 'claim':
                    # Make a claim
                    $response = self::createPacketClaimWithPassword([
                        'number'         => $entity->model_identifier,
                        'email'          => $entity->email,
                        'phone'          => $entity->phone,
                        'value'          => $entity->value_for_parcel,
                        'currency'       => $entity->currency,
                        'eshop'          => $entity->eshop,
                        'consignCountry' => 'CZ',
                        # Apparently Packeta doesnt care about this being hardcoded for all countries
                    ]);

                    if ($response->success) {
                        $protoParcel = $response->data;
                        $protoParcel->type = 'claim';
                        $protoParcels[] = $protoParcel;
                    }
                    break;

                default:
                    $response = new CoreResponse();
                    $response->fail('Unknown type of entity for parcel creation');
                    break;
            }
        }

        # Nest the object in an array to match the format returned bu GLS createFrom method
        $response->setData($protoParcels);

        return $response;
    }

    public static function getLabel(int $id): void
    {
        $response = self::packetLabelPdf($id, config('parcelable.PACKETA_LABEL_FORMAT'), 0);
        Storage::disk('private')->put('labels/' . $id . '.pdf', $response->data);
    }


    /**
     * Currently roughly precise only for CZ, SK, PL and HU. Progressive price of COD and insurance is mostly skipped.
     *
     * https://pickup-point.api.packeta.com/v5/d3b8401799d472fd/carrier/json?lang=cs
     *
     * @param ParcelableContract $parcelable
     * @return float
     */
    public static function getCostFor(ParcelableContract $parcelable): float
    {
        $weight = $parcelable->weight * 1.85;

        $baseCosts = [
            'CZ' => [
                5  => 42,
                99 => 62,
            ],
            'SK' => [
                5  => 62,
                99 => 72,
            ],
            'PL' => [
                null  => [
                    5  => 65,
                    99 => 128,
                ],
                3060  => [ # InPost Paczkomaty
                           5  => 145,
                           10 => 175,
                           99 => 185,
                ],
                14052 => [ # Polská Pošta PP
                           10 => 110,
                           99 => 170,
                ],
            ],
            'HU' => [
                null  => [
                    5  => 85,
                    99 => 160,
                ],
                32970 => [ # FoxPost PP
                           5  => 85,
                           99 => 160,
                ],
                4539  => [ # Maďarská Pošta Box
                           2  => 130,
                           5  => 150,
                           10 => 170,
                           15 => 270,
                ],
                29760 => [ # Maďarská Pošta PP
                           2  => 130,
                           5  => 150,
                           10 => 170,
                           15 => 270,
                           20 => 399,
                ],
            ],
            'DE' => [
                5  => 145,
                99 => 165,
            ],
        ];

        $codSurcharges = [
            14052 => 8,
            32970 => 21,
            29760 => 30,
            4539  => 30,
            null  => 15,
        ];

        $tollSurcharge = $weight > 5 ? 4.8 : 2.1;
        $codSurcharge = $parcelable->is_cod ? $codSurcharges[$parcelable->carrier_id] ?? $codSurcharges[null] : 0;

        $countryArray = $baseCosts[$parcelable->country] ?? $baseCosts['DE'];

        if (is_array(reset($countryArray))) {
            # Array is nested, descend deeper and select the particular courier company
            $countryArray = $countryArray[$parcelable->carrier_id] ?? $countryArray[null];
        }

        $selectedCountryCosts = collect($countryArray);

        # Take the first cost that is greater than or equal to the weight
        $baseCost = $selectedCountryCosts->first(fn($cost, $weightLimit) => $weight <= $weightLimit);
        $dieselSurcharge = $baseCost * 0.05; # 5% diesel surcharge, why do they state 6% in the docs?

        if ($parcelable->carrier_id) $tollSurcharge = $dieselSurcharge = 0; # No toll surcharge for external carriers

        return round($baseCost + $dieselSurcharge + $tollSurcharge + $codSurcharge, 2);
    }
}
