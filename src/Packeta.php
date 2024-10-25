<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Entity;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;
use SoapClient;
use SoapFault;

/**
 * @method static packetStatus(int $parcelNumber)
 * @method static createPacketClaimWithPassword(array $array)
 * @method static packetLabelPdf($id, string $string, int $int)
 * @method static createPacket(array $array)
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
        'delivered'              => 'Doručena',
        'posted back'            => 'Na cestě zpátky',
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
    public static function getParcelStatus(int $parcelNumber): CoreResponse
    {
        $response = self::packetStatus($parcelNumber);

        if ($response->success) $response->data->status = self::STATUS_MAP[$response->data->codeText];

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
        $type = $type ?: $entity->defaultParcelType;
        $parcelCount = $entity->parcel_count ?? 1;
        $protoParcels = [];

        foreach (range(1, $parcelCount) as $i) {
            switch ($type) {
                case 'parcel':
//                    if ($entity->has_mismatched_currency) {
//                        $currency = $entity->country == 'CZ' ? 'CZK' : 'EUR';
//                        $codInWrongCurrency = $entity->cod_in_currency / $parcelCount;
//                        $totalInWrongCurrency = $entity->total_in_currency;
//                        $cod = $entity->currency == 'CZK' ? czkToEur($codInWrongCurrency) : eurToCzk($codInWrongCurrency);
//                        $total = $entity->currency == 'CZK' ? czkToEur($totalInWrongCurrency) : eurToCzk($totalInWrongCurrency);
//                    }

                    # Post a parcel
                    $response = self::createPacket([
                        'number'             => $entity->id,
                        'name'               => $entity->first_name,
                        'surname'            => $entity->last_name,
                        'email'              => $entity->email,
                        'phone'              => $entity->phone,
                        'street'             => $entity->street,
                        'houseNumber'        => $entity->houseNumber,
                        'city'               => $entity->city,
                        'zip'                => substr_replace($entity->postal_code ?? '', ' ', 3, 0),
                        'addressId'          => $entity->address_id,
                        'carrierPickupPoint' => $entity->carrier_pickup_point,
                        'currency'           => $entity->national_currency,
                        'size'               => $entity->size_for_external_carrier,
                        'cod'                => $entity
                            ->cod_object
                            ->divide($parcelCount)
                            ->convertTo($entity->national_currency)
                            ->roundByCurrency()
                            ->float,
                        'value'              =>
                            min(price(19990)->convertTo($entity->national_currency)->float, $entity
                                ->total_object
                                ->convertTo($entity->national_currency)
                                ->roundByCurrency()
                                ->float ?: 100),
                        'weight'             => min(10, $entity->weight / 0.5 ?: 1),
                        'eshop'              => $entity->eshop,
                    ]);

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
                        'value'          => $entity->total_object->float ?: 100,
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
        $response = self::packetLabelPdf($id, 'A6 on A6', 0);
        Storage::disk('private')->put('labels/' . $id . '.pdf', $response->data);
    }
}
