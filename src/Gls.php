<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Entity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;

class Gls
{
    public const STATUS_MAP = [
        'Doručeno'                       => 'Doručena',
        'Delivered To Neighbour'         => 'Doručena',
        'Ostatní data přijata'           => 'Čeká na vyzvednutí kurýrem',
        'COD data přijata'               => 'Čeká na vyzvednutí kurýrem',
        'P&S/P&R na vyzvednutí'          => 'Čeká na vyzvednutí kurýrem',
        'P&S/P&R vytisknut'              => 'Čeká na vyzvednutí kurýrem',
        'Není balík P&S/P&R'             => 'Čeká na vyzvednutí kurýrem',
        'Balík není připraven'           => 'Čeká na vyzvednutí kurýrem',
        'Vyzvednuto'                     => 'Přijata k přepravě',
        'Registrace'                     => 'V přepravě',
        'Depo vstup'                     => 'V přepravě',
        'HUB Inbound'                    => 'V přepravě',
        'HUB Outbound'                   => 'V přepravě',
        'HUB Storage'                    => 'V přepravě',
        'Nesprávné PSČ'                  => 'V přepravě',
        'Nesprávné nasměrování na depo'  => 'V přepravě',
        'Small Parcel'                   => 'V přepravě',
        'Depo sklad'                     => 'V přepravě',
        'Dovolená'                       => 'V přepravě',
        'Depot Re-delivery'              => 'V přepravě',
        'Chybné nasortování na depo'     => 'V přepravě',
        'Překročena kapacita trasy'      => 'V přepravě',
        'Not Systemlike Parcel'          => 'V přepravě',
        'Změna adresy doručení'          => 'V přepravě',
        'Damaged'                        => 'V přepravě',
        'Nesprávná adresa (neúplná)'     => 'V přepravě',
        'Nesprávná trasa'                => 'V přepravě',
        'Rollkarte Check'                => 'V přepravě',
        'Dle nového požadavku'           => 'V přepravě',
        'Bez údajů'                      => 'V přepravě',
        'Change Of Delivery Address'     => 'V přepravě',
        'Na doručení'                    => 'Doručována',
        'Pevne urceny den doruceni'      => 'Doručována',
        'adresát nezastižen - oznámení'  => 'Doručována',
        'Nedostatek peněz'               => 'Doručována',
        'ParcelLocker deposit'           => 'Připravena k vyzvednutí',
        'Doručení do ParcelShopu'        => 'Připravena k vyzvednutí',
        'Uskladněno v ParcelShopu'       => 'Připravena k vyzvednutí',
        'Zaslání do HUB'                 => 'Na cestě zpátky',
        'Zpětné zaslání odesílateli'     => 'Na cestě zpátky',
        'Odmítnutí převzetí balíku'      => 'Na cestě zpátky',
        'ParcelShop return'              => 'Na cestě zpátky',
        'Odmítnutí (neobjednaná služba)' => 'Na cestě zpátky',
        'P&S/P&R vymazán'                => 'Stornována',

        'returned' => 'Vrácena obchodu',
    ];

    public const URL = 'https://api.mygls.cz/ParcelService.svc/json/';

    private static function hashPassword()
    {
        return '[' . implode(',', unpack('C*', hash('sha512', config('parcelable.GLS_PASSWORD'), true))) . ']';
    }

    #--GLS--
    public static function getResponse($url, $method, $request): CoreResponse
    {
        $faultArrays = [
            'GetParcelStatusErrors',
            'PrintLabelsErrorList',
        ];
        $faults = collect();

        $response = new CoreResponse();
        //Service calling:
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_URL, $url . $method);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, 600);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $request);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($request),
        ]);

        $glsResponse = curl_exec($curl);

        # CURL fault
        if ($glsResponse === false) return $response->fail('curl_error:"' . curl_error($curl) . '";curl_errno:' . curl_errno($curl));

        curl_close($curl);

        $glsObject = json_decode($glsResponse);

        # Compile faults from all endpoints into one collection
        foreach ($faultArrays as $key) {
            $potentialFaults = collect($glsObject->$key ?? []);
            $faults = $faults->merge($potentialFaults);
        }

        if ($faults->count()) {
            $response->fail($faults->implode('ErrorDescription', ', '));
        } else {
            $response->success($glsObject);
        }

        return $response;
    }

    #GLS INHERITED FUNCTION
    public static function printLabels($parcelsJson): CoreResponse
    {
        $request = '{"Username":"' . config('parcelable.GLS_USERNAME') . '","Password":' . self::hashPassword() . ',"ParcelList":' . $parcelsJson . '}';
        $response = self::getResponse(self::URL, 'PrintLabels', $request);

        if ($response->success) {
            $pdf = implode(array_map('chr', $response->data->Labels));
            $trackingNumbers = [];

            foreach ($response->data->PrintLabelsInfoList as $protoParcel) {
                $trackingNumber = $protoParcel->ParcelNumber;

                # Add the number to the return array
                $trackingNumbers[] = $trackingNumber;

                # Save the label
                Storage::disk('private')->put('labels/' . $trackingNumber . '.pdf', $pdf);
            }

            $response->setData($trackingNumbers);
        }

        return $response;
    }

    public static function getPrintedLabels(array $ids)
    {
        $request = '{"Username":"' . config('parcelable.GLS_USERNAME') . '","Password":' . self::hashPassword() . ',"ParcelIdList":' . json_encode($ids) . ',"PrintPosition":1,"ShowPrintDialog":0}';
        $response = self::getResponse(self::URL, 'GetPrintedLabels', $request);


        //MAYDO WTF their API doesnt hanlde their own parcel IDs? {"GetPrintedLabelsErrorList":[{"ErrorCode":"1000","ErrorDescription":"There was an error deserializing the object of type GLS.MyGLS.ServiceData.APIDTOs.LabelOperations.GetPrintedLabelsRequest. The value '5007047633' cannot be parsed as the type 'Int32'."}]}
        dd($response);
    }

    /**
     * Generates the JSON string from a given resource
     *
     * @param Entity $entity
     * @param string $type = ''
     * @return string
     */
    public static function generateJson(
        Entity $entity,
        string $type = '',
    ): string
    {
        $type = $type ?: $entity->default_parcel_type;

        $config = config('parcelable.GLS_CLIENTS')[$entity->eshop];

        if ($entity->payment == 'Dobírka') $cod = str_replace(',', '.', $entity->dueInCurrencyAndCountry);

        $isClaim = $type == 'claim';

        $serviceList = [];

        if ($entity->gls_packeta) {
            $serviceList[] = [
                'Code'         => 'PSD',
                'PSDParameter' => [
                    'StringValue' => $entity->gls_packeta,
                ],
            ];
        } elseif ($entity->fds_available) {
            $serviceList[] = [
                'Code'         => 'FDS',
                'FDSParameter' => [
                    'Value' => $isClaim ? $config['email'] : $entity->email,
                ],
            ];
        }

        if ($isClaim) $serviceList[] = ['Code' => 'PSS'];

        $parcelArray = [
            'ClientNumber'                                 => $config['id'],
            'ClientReference'                              => $entity->id,
            'CODAmount'                                    => $cod ?? 0,
            'CODReference'                                 => $entity->id,
            'Content'                                      => $entity->id . ' ' . $entity->name . ' ' . $entity->phone,
            'Count'                                        => $entity->parcel_count ?: 1,
            $isClaim ? 'PickupAddress' : 'DeliveryAddress' => [
                'City'            => $entity->parcelshop_city ?? $entity->city,
                'ContactEmail'    => $entity->email,
                'ContactName'     => $entity->name,
                'ContactPhone'    => $entity->phone,
                'CountryIsoCode'  => $entity->country,
                'HouseNumber'     => '',
                'Name'            => $entity->gls_packet ?? $entity->name,
                'Street'          => $entity->parcelshop_street ?? $entity->street,
                'ZipCode'         => $entity->parcelshop_zip ?? $entity->postal_code,
                'HouseNumberInfo' => '',
            ],
            $isClaim ? 'DeliveryAddress' : 'PickupAddress' => [
                'City'            => 'Liberec',
                'ContactEmail'    => $config['email'],
                'ContactName'     => $config['contactName'],
                'ContactPhone'    => config('parcelable.GLS_PHONE'),
                'CountryIsoCode'  => 'CZ',
                'HouseNumber'     => config('parcelable.GLS_HOUSE_NUMBER'),
                'Name'            => $config['name'],
                'Street'          => config('parcelable.GLS_STREET'),
                'ZipCode'         => '46001',
                'HouseNumberInfo' => config('parcelable.GLS_SENDER_INFO'),
            ],
            'PickupDate'                                   => '/Date(' . ($entity->pickup_timestamp ?? now()->timestamp) . ')/',
            'ServiceList'                                  => $serviceList,
        ];

        return json_encode([$parcelArray], JSON_UNESCAPED_UNICODE);
    }


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
        $json = self::generateJson($entity, $type);

        # Print Labels function also saves the label to the folder
        $response = self::printLabels($json);

        if ($response->success) {
            # Everything allright
            $protoParcels = [];
            $trackingNumbers = $response->data;

            if (
                count($trackingNumbers) == 1 &&
                $entity->parcel_count > 1
            ) {
                # Tracking numbers are missing - GLS does not send all of the numbers in case of claim. Those are sequential, so we make them up
                foreach (range(1, $entity->parcel_count - 1) as $i) {
                    $trackingNumbers[] = intval($trackingNumbers[0]) + $i;
                }
            }

            foreach ($trackingNumbers as $trackingNumber) {
                $protoParcel = (object)[
                    'id' => $trackingNumber,
                ];
                $protoParcels[] = $protoParcel;
            }

            $response->setData($protoParcels);
        }

        return $response;
    }


    /**
     * Checks the parcel status in GLS API
     *
     * @param int $parcelNumber
     * @return CoreResponse
     *
     */
    public static function getParcelStatus(int|string $parcelNumber): CoreResponse
    {
        if (!is_int($parcelNumber)) {
            $parcelNumber = intval(ltrim($parcelNumber, 'Zz'));
        }
        //intval(ltrim($parcelNumber, 'Zz'))
        $request = '{"Username":"' . config('parcelable.GLS_USERNAME') . '","Password":' . self::hashPassword() . ',"ParcelNumber":' . $parcelNumber . ',"ReturnPOD":false,"LanguageIsoCode":"CS"}';
        $response = self::getResponse(self::URL, 'GetParcelStatuses', $request);

        if ($response->success) {

            if (!$response->data) {
                Log::channel('separated')->warning('GLS parcel status has empty data!', [
                    'response' => json_encode($response),
                ]);
            }

            $statuses = collect($response->data->ParcelStatusList);

            foreach ($statuses as $statusObject) {
                if (!array_key_exists($statusObject->StatusDescription, self::STATUS_MAP))
                    Log::warning('GLS status not recognized: ' . $statusObject->StatusDescription);
            }

            $currentStatusDescription = $statuses[0]?->StatusDescription;
            $statusMap = self::STATUS_MAP;

            if ($statuses->where('StatusDescription', 'P&S/P&R na vyzvednutí')->count()) {
                # The parcel is a return, adresát nezastižen - oznámení means the parcel was still not yet picked up
                $statusMap['adresát nezastižen - oznámení'] = 'Čeká na vyzvednutí kurýrem';
            }

            $status = $statusMap[$currentStatusDescription] ?? 'V přepravě';

            if (
                $statuses->where('StatusDescription', 'Zpětné zaslání odesílateli')->count() &&
                $status == 'Doručena'
            ) {
                # The parcel was refused and is on its way back, the delivered now means returned to sender -.-
                $status = 'Vrácena obchodu';
            }

            $statusObject = (object)[
                'status' => $status,
            ];

            $response->setData($statusObject);
        }

        return $response;
    }

    public static function getCostFor(ParcelableContract $parcelable): float
    {
        $weight = $parcelable->weight * config('parcelable.GLS_WEIGHT_MULTIPLIER');

        $zone = match ($parcelable->country) {
            'CZ' => 0,
            'SK', 'PL' => 1,
            'DE', 'AT', 'HU' => 2,
            'NL', 'LU', 'BE', 'DK', 'HR' => 3,
            'EE', 'LT', 'LV', 'RO', 'SI' => 4,
            'FR', 'BG', 'IT', 'SE' => 5,
            'ES', 'PT' => 6,
            'FI', 'GB', 'IE', 'CH', 'NO', 'UK', 'LI' => 7,
            default => 8, # GR
        };

        $tollSurcharge = $parcelable->country == 'CZ' ? config('parcelable.GLS_TOLL_SURCHARGE_DOMESTIC') : config('parcelable.GLS_TOLL_SURCHARGE_FOREIGN');
        $codCosts = $parcelable->country == 'CZ' ? config('parcelable.GLS_COD_COSTS_DOMESTIC') : config('parcelable.GLS_COD_COSTS_FOREIGN');
        $codCosts += $parcelable->price_czk * config('parcelable.GLS_COD_CARD_PAYMENT');

        $codSurcharge = $parcelable->is_cod ? $codCosts : 0;


        $selectedCountryCosts = collect(config('parcelable.GLS_PRICE_LIST.' . $zone));

        # Take the first cost that is greater than or equal to the weight
        $baseCost = $selectedCountryCosts->first(fn($cost, $weightLimit) => $weight <= $weightLimit);

        return round($baseCost + (config('parcelable.GLS_DIESEL_SURCHARGE') * $baseCost) + ceil($weight) * $tollSurcharge + $codSurcharge, 2);
    }
}
