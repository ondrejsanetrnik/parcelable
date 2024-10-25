<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Entity;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;

class Gls //ROZPRAS
{
    public const STATUS_MAP = [
        'Doručeno'                       => 'Doručena',
        'Delivered To Neighbour'         => 'Doručena',
        'Ostatní data přijata'           => 'Čeká na vyzvednutí kurýrem',
        'COD data přijata'               => 'Čeká na vyzvednutí kurýrem',
        'P&S/P&R na vyzvednutí'          => 'Čeká na vyzvednutí kurýrem',
        'P&S/P&R vytisknut'              => 'Čeká na vyzvednutí kurýrem',
        'Není balík P&S/P&R'             => 'Čeká na vyzvednutí kurýrem',
        'P&S/P&R vymazán'                => 'Čeká na vyzvednutí kurýrem',
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

        'returned'  => 'Vrácena obchodu',
        'cancelled' => 'Stornována',
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
    public static function printLabels($username, $password, $url, $method, $parcelsJson): CoreResponse
    {
        $request = '{"Username":"' . $username . '","Password":' . $password . ',"ParcelList":' . $parcelsJson . '}';
        $response = self::getResponse($url, $method, $request);

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

    /**
     * Generates the JSON string from a given resource
     *
     * @param Entity $entity
     * @param string $type = ''
     * @return string
     */
    public static function generateJson(
        Entity $entity,
        string $type,
    ): string
    {
        $type = $type ?: $entity->defaultParcelType;

        $config = config('parcelable.GLS_CLIENTS')[$entity->eshop];

        if ($entity->payment == 'Dobírka') $cod = str_replace(',', '.', $entity->dueInCurrencyAndCountry);

        $isClaim = $type == 'claim';
        //MAYDO ROZPRAS do array a json_encode
        $parcelsJson = '[{
            "ClientNumber": "' . $config['id'] . '",
            "ClientReference": "' . $entity->id . '",
            "CODAmount": "' . ($cod ?? 0) . '",
            "CODReference": "' . $entity->id . '",
            "Content": "' . $entity->id . ' ' . $entity->name . ' ' . $entity->phone . '",
            "Count": ' . ($entity->parcel_count ?: '1') . ',
            "' . ($isClaim ? 'PickupAddress' : 'DeliveryAddress') . '": {
              "City": "' . ($entity->parcelshopCity ?? $entity->city) . '",
              "ContactEmail": "' . $entity->email . '",
              "ContactName": "' . $entity->name . '",
              "ContactPhone": "' . $entity->phone . '",
              "CountryIsoCode": "' . $entity->country . '",
              "HouseNumber": "",
              "Name": "' . ($entity->glsPacket ?? $entity->name) . '",
              "Street": "' . ($entity->parcelshopStreet ?? $entity->street) . '",
              "ZipCode": "' . ($entity->parcelshopZip ?? $entity->postal_code) . '",
              "HouseNumberInfo": ""
            },
            "' . ($isClaim ? 'DeliveryAddress' : 'PickupAddress') . '": {
              "City": "Liberec",
              "ContactEmail": "' . $config['email'] . '",
              "ContactName": "' . $config['contactName'] . '",
              "ContactPhone": "' . config('parcelable.GLS_PHONE') . '",
              "CountryIsoCode": "CZ",
              "HouseNumber": "' . config('parcelable.GLS_HOUSE_NUMBER') . '",
              "Name": "' . $config['name'] . '",
              "Street": "' . config('parcelable.GLS_STREET') . '",
              "ZipCode": "46001",
              "HouseNumberInfo": "' . config('parcelable.GLS_SENDER_INFO') . '"
            },
            "PickupDate": "\/Date(' . ($entity->pickupTimestamp ?? now()->timestamp) . ')\/",
            "ServiceList":[' .
            (!$entity->glsPacketa ? '
                {
                  "Code":"FDS",
                  "FDSParameter":{
                      "Value":"' . ($isClaim ? $config['email'] : $entity->email) . '"
                  }
                }' : '') .
            ($isClaim ? ',{"Code":"PSS"}' : '') .
            ($entity->glsPacketa ? '
                {
                "Code":"PSD",
                "PSDParameter":{
                    "StringValue":"' . $entity->glsPacketa . '"
                }
                }' : '') . '
            ]
          }]';


        return $parcelsJson;
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
        $response = self::printLabels(config('parcelable.GLS_USERNAME'), self::hashPassword(), self::URL, 'PrintLabels', $json);

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
    public static function getParcelStatus(int $parcelNumber): CoreResponse
    {
        $request = '{"Username":"' . config('parcelable.GLS_USERNAME') . '","Password":' . self::hashPassword() . ',"ParcelNumber":' . $parcelNumber . ',"ReturnPOD":false,"LanguageIsoCode":"CS"}';
        $response = self::getResponse(self::URL, 'GetParcelStatuses', $request);

        if ($response->success) {
            foreach ($response->data->ParcelStatusList as $statusObject) {
                if (!array_key_exists($statusObject->StatusDescription, self::STATUS_MAP))
                    dump($statusObject);
            }

            $status = self::STATUS_MAP[$response->data->ParcelStatusList[0]?->StatusDescription] ?? 'V přepravě';

            if (
                collect($response->data->ParcelStatusList)->where('StatusDescription', 'Zpětné zaslání odesílateli')->count() &&
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
}
