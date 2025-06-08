<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Entity;
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
     * @param int $parcelNumber
     * @return CoreResponse
     */
    public static function getParcelStatus(string|int $parcelNumber): CoreResponse
    {
        if (!is_int($parcelNumber)) {
            $parcelNumber = intval(ltrim($parcelNumber, 'Zz'));
        }

        $response = self::packetStatus($parcelNumber);

        if ($response->success) $response->data->status = self::STATUS_MAP[$response->data->codeText] ?? null;

        if ($response->data->status === null) {
            Log::warning('Packeta status not found', [
                'code'         => $response->data->codeText,
                'parcelNumber' => $parcelNumber,
            ]);
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


    public static function getCostFor(ParcelableContract $parcelable): float
    {
        $weight = $parcelable->weight * 1.85;

        $baseCosts = [ # TODO really rough, needs to be improved
                       'CZ' => [
                           5  => 42,
                           99 => 62,
                       ],
                       'SK' => [
                           5  => 62,
                           99 => 72,
                       ],
                       'PL' => [
                           5  => 145,
                           99 => 165,
                       ],
                       'DE' => [
                           5  => 145,
                           99 => 165,
                       ],
        ];

        $dieselSurcharge = $weight > 5 ? 3.1 : 2.1;
        $tollSurcharge = $weight > 5 ? 4.8 : 2.1;
        $codSurcharge = $parcelable->is_cod ? 15 : 0;

        $selectedCountryCosts = collect($baseCosts[$parcelable->country] ?? $baseCosts['DE']);

        # Take the first cost that is greater than or equal to the weight
        $baseCost = $selectedCountryCosts->first(fn($cost, $weightLimit) => $weight <= $weightLimit);

        return round($baseCost + $dieselSurcharge + $tollSurcharge + $codSurcharge, 2);
    }
}
