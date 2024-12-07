<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Entity;
use App\Objects\Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;

class Balikovna
{

    /**
     * url of napi https://www.ceskaposta.cz/napi/b2b
     */


    /**
     * Function to send POST request by default to the specified endpoint with the given data.
     *
     * @param string $endpoint The API endpoint.
     * @param array $data The data to send in the request.
     * @return array The decoded JSON response.
     * @throws Exception If there is a CURL error.
     */
    public static function getResponse($endpoint, $data = null, $method = 'POST')
    {
        $faultArrays = [
            'GetParcelStatusErrors',
            'PrintLabelsErrorList',
        ];
        $faults = collect();
        $response = new CoreResponse();
        $apiToken = config('parcelable.BALIKOVNA_API_TOKEN');
        $secretKey = config('parcelable.BALIKOVNA_SECRET_KEY');
        $baseUrl = config('parcelable.BALIKOVNA_BASE_URL');

        if (\App::isLocal()) {
            $baseUrl = 'https://b2b-test.postaonline.cz:444/restservices/ZSKService/v1/';
        }

        $url = $baseUrl . $endpoint;

        // Step 1: Prepare the data (payload)
        $payload = $data ? json_encode($data) : null; // Prepare POST data

        // Step 2: Generate the timestamp and nonce
        $timestamp = strtotime(now());
        $nonce = uniqid('', true);

        if ($method === 'POST' && $payload) {
            // For POST requests, calculate the SHA256 hash of the payload
            $contentSha256 = hash('sha256', $payload);
            // For POST requests, the signature includes the body hash, timestamp, and nonce
            $signatureData = $contentSha256 . ';' . $timestamp . ';' . $nonce;
        } else {
            // For GET requests, there is no body, so the hash is for an empty string
            $contentSha256 = ''; // Empty string for GET requests (no body)
            // For GET requests, the signature includes only the timestamp and nonce
            $signatureData = ';' . $timestamp . ';' . $nonce;
        }

        // Step 3: Generate the HMAC SHA256 signature
        $signature = hash_hmac('sha256', $signatureData, $secretKey, true);
        $base64Signature = base64_encode($signature);

        // Log the timestamp for debugging purposes
        Log::info('timestamp ' . $timestamp);

        // Step 4: Set the headers for the request
        $headers = [
            'Content-Type: application/json;charset=UTF-8',
            'API-Token: ' . $apiToken,
            'Authorization-Timestamp: ' . $timestamp,
            'Authorization-Content-SHA256: ' . $contentSha256, // For POST requests
            'Authorization: CP-HMAC-SHA256 nonce="' . $nonce . '" signature="' . $base64Signature . '"',
        ];

        // Step 4: Initialize the cURL request
        $ch = curl_init($url);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        } else if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, 1);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Disable SSL verification for test environment
//        if (\App::isLocal()) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
//        }

        // Step 5: Execute the request
        $balikovnaResponse = curl_exec($ch);

        # CURL fault
        if ($balikovnaResponse === false) return $response->fail('curl_error:"' . curl_error($ch) . '";curl_errno:' . curl_errno($ch));

        curl_close($ch);
        $balikovnaObject = json_decode($balikovnaResponse);

        # Compile faults from all endpoints into one collection
        foreach ($faultArrays as $key) {
            $potentialFaults = collect($balikovnaObject->$key ?? []);
            $faults = $faults->merge($potentialFaults);
        }

        if ($faults->count()) {
            $response->fail($faults->implode('ErrorDescription', ', '));
        } else {
            $response->success($balikovnaObject);
        }

        return $response;

    }

    /**
     * Function to get parcel status.
     *
     * @param array $parcelIds List of up to 10 parcel IDs.
     * @param string $language Language for the displayed events.
     * @return array The response from the API.
     */

    public static function getParcelStatus(array $parcelIds, string $language = 'CZ'): CoreResponse
    {
        // Ensure the number of parcel IDs does not exceed 10
        if (count($parcelIds) > 10) {
            return (new CoreResponse())->fail('Cannot request status for more than 10 parcels at a time.');
        }

        // Prepare the request data
        $requestData = [
            'parcelIds' => $parcelIds, // "parcelIds" used in the request
            'language'  => $language,
        ];

        // Get the response from the API
        $response = self::getResponse('parcelStatus', $requestData);

        // Process the response if successful
        if ($response->success) {
            // Prepare the response data with the latest status and storedUntil
            $processedData = collect($response->data->detail)->map(function($parcelDetail) {
                // Extract the latest parcel status based on date
                $latestStatus = collect($parcelDetail->parcelStatuses)
                    ->sortByDesc('date') // Sort the statuses by date in descending order
                    ->first();

                // Return an object with the updated status and storedUntil
                return (object)[
                    'status' => $latestStatus->text ?? '', // Assign the latest status text
                    'storedUntil' => $parcelDetail->timeDeposit ?? null, // Use timeDeposit or null
                ];
            });

            // Set the processed data to the response object
            $response->data->status = $processedData->pluck('status')->first();
            $response->data->storedUntil = $processedData->pluck('storedUntil')->first();
        }

        // Return the processed response
        return $response;
    }

    public static function getParcelHistory(string $parcelID): CoreResponse
    {
        // Prepare the request data
        $idContract = config('parcelable.BALIKOVNA_ID_CCK');

        // Build the endpoint URL with the parcelID
        $endpoint = "parcelDataHistory/parcelID/{$parcelID}";

        // Prepare the query parameters
        $params = [
            'idContract' => $idContract,
            'parcelID'   => $parcelID,
        ];

        // Send the request and return the response
        return self::getResponse($endpoint, $params, 'GET');
    }

    public static function createFrom(Entity $entity, string $type = '')
    {
        $type = $type ?: $entity->default_parcel_type;

        // Generate the request data
        $data = self::generateJson($entity);
        $encodedLabels = [];
        $parcelCodes = [];

        // Check if the entity is not a Balikovna on address and has more than one parcel
        if ($entity->parcel_count > 1 && !$entity->is_balikovna_on_address) {
            for ($i = 0; $i < $entity->parcel_count; $i++) {
                // Optionally modify the data for each parcel if needed
                $parcelData = self::generateJson($entity);

                // Adjust amount based on parcel count if needed
                $parcelParams['amount'] = $entity->total / $entity->parcel_count; // Dobírka service

                // Send the request and get the response
                $response = self::getResponse('parcelService', json_decode($parcelData));

                // Check if the response is successful and contains the label file
                if ($response->success && isset($response->data->responseHeader->responsePrintParams->file)) {
                    // Get the base64 encoded label
                    $encodedLabel = $response->data->responseHeader->responsePrintParams->file;
                    // Get the parcel code
                    $parcelCode = $response->data->responseHeader->resultParcelData[0]->parcelCode;

                    // Collect encoded labels and parcel codes
                    $encodedLabels[] = $encodedLabel;
                    $parcelCodes[] = $parcelCode;
                } else {
                    dd($response);
                    $response->fail(collect($response->data?->responseHeader?->resultParcelData[0]?->parcelStateResponse)->implode('responseText', ', '));
                    return $response;
                }
            }
            // Merge all encoded labels into one PDF if needed
            foreach ($encodedLabels as $index => $encodedLabel) {
                $fileName = $parcelCodes[$index];
                self::saveLabelAsPdf($encodedLabel, $fileName);
            }

            // Return the parcel codes and the response
            $protoParcels = array_map(fn($parcelCode) => (object)['id' => $parcelCode], $parcelCodes);
            return $response->success($protoParcels);

        } else {
            // Handle single parcel or Balikovna on address
            if ($entity->parcel_count > 1 && $entity->is_balikovna_on_address == 1) {
                $data = self::addParcelToJson($data, $entity);
            }
            // Send the request and get the response
            $response = self::getResponse('parcelService', json_decode($data));
            // Check if the response is successful and contains the label file
            if ($response->success && isset($response->data->responseHeader->responsePrintParams->file)) {
                // Get the base64 encoded label
                $encodedLabel = $response->data->responseHeader->responsePrintParams->file;
                // Get the parcel code
                $parcelCode = $response->data->responseHeader->resultParcelData[0]->parcelCode;
                // Save the label as a PDF using the parcel code as the filename
                self::saveLabelAsPdf($encodedLabel, $parcelCode);

                // Return the parcel code and the response
                $protoParcel = (object)[
                    'id' => $parcelCode,
                ];
                return $response->success([$protoParcel]);
            } else {
                dd($response);
                $response->fail(collect($response->data->responseHeader?->resultParcelData[0]?->parcelStateResponse)->implode('responseText', ', '));
            }

            return $response;
        }
    }

    private static function saveLabelAsPdf(string $encodedLabel, string $fileName)
    {
        // Decode the base64 encoded label
        $decodedLabel = base64_decode($encodedLabel);

        // Determine the disk to use based on the environment
        $disk = 'private';

        // Debugging: Log the disk and file name being used
        Log::info("Saving label to disk: {$disk}, file name: {$fileName}.pdf");

        // Save the decoded label to a file
        $saved = Storage::disk($disk)->put('labels/' . $fileName . '.pdf', $decodedLabel);

        // Check if the file was successfully saved
        if (!$saved) {
            Log::error("Failed to save label as PDF: {$fileName}.pdf");
            throw new \Exception("Failed to save label as PDF: {$fileName}.pdf");
        } else {
            Log::info("Label saved successfully as: labels/{$fileName}.pdf");
        }
    }

    public static function determineParcelSize(Entity $entity, int $parcelCount = 1): string
    {
        // Calculate the dimension for determining the size category
        $dimension = $entity->width / 2; // Assuming books lie differently, mostly fall into S size
        $dimension /= $parcelCount; // Adjust for the number of parcels

        // Values in mm, according to the post office
        if ($dimension <= 350) {
            return 'S';
        } elseif ($dimension <= 500) {
            return 'M';
        } elseif ($dimension <= 1000) {
            return 'L';
        } else {
            return 'XL';
        }
    }

    public static function generateJson(Entity $entity, int $formID = null, int $position = 1): string
    {
        $customerID = config('parcelable.BALIKOVNA_CUSTOMER_ID');
        $postCode = config('parcelable.BALIKOVNA_POST_CODE');
        $locationNumber = config('parcelable.BALIKOVNA_LOCATION_NUMBER');
        if (!$formID) {
            $formID = config('parcelable.BALIKOVNA_FORM_ID');
        }

        // Prepare parcelAddress depending on balikovna condition
        $parcelAddress = self::prepareParcelAddress($entity);

        // Prepare parcelParams
        $parcelParams = [
            'weight'           => strval(round(min($entity->width / 20, 49), 3)), //asi bych nastavil max limit
            'prefixParcelCode' => $entity->is_balikovna_on_address == 1 ? 'DR' : 'NB', // Prefix for parcel code
            'recordID'         => strval($entity->id), // internal ID
            'insuredValue'     => $entity->total * 2, // insurance, double the price of goods
            'note'             => $entity->private_note ?? '', // internal note for the parcel
            'notePrint'        => $entity->note ?? '', // for the label
        ];

//        if ($entity->parcel_count > 1) {
//            $parcelParams['sequenceParcel'] = 1; // sequence number of the parcel, must for service 70 (multi)
//            $parcelParams['quantityParcel'] = $entity->parcel_count; // quantity of parcels, must for service 70 (multi)
//        }

        $parcelServices = [
            self::determineParcelSize($entity), //nejdřív sen nevěděl co to je, nutné jen u balíkovny na adresu
            //             {#6612
            //                 +"responseCode": 261,
            //                +"responseText": "MISSING_SIZE_CATEGORY",
            //              },
        ];

        if ($entity->payment == 'Dobírka') {
            $parcelParams['amount'] = $entity->is_balikovna_on_address ? $entity->total : ($entity->total / $entity->parcel_count); // Dobírka service
            $parcelParams['currency'] = $entity->currency; // Dobírka currency
            $parcelParams['vsVoucher'] = strval($entity->id); // Variabilní symbol for service 41
            $parcelServices[] = '41'; // Add 'Dobírka' service
        }

        // Build and return the final data structure
        $array = [
            'parcelServiceHeader' => [
                'parcelServiceHeaderCom' => [
                    'transmissionDate' => now()->format('Y-m-d'),
                    'customerID'       => $customerID, // Using the passed parameter
                    'postCode'         => $postCode,   // Using the passed parameter
                    'locationNumber'   => $locationNumber, // Using the passed parameter
                ],
                'printParams'            => [
                    'idForm'          => $formID, // Using the passed parameter
                    'shiftHorizontal' => 0,
                    'shiftVertical'   => 0,
                ],
                'position'               => $position, // Using the passed parameter
            ],
            'parcelServiceData'   => [
                'parcelParams'   => $parcelParams,
                'parcelServices' => $parcelServices,
                'parcelAddress'  => $parcelAddress,
            ],
        ];

        // If multi-part parcel, add multipart data

//        if ($entity->parcel_count > 1) {
//            dump($entity->parcel_count);
//
//            foreach (range(1, $entity->parcel_count) as $i) {
//                $array['multipartParcelData'][] = [
//                    'addParcelData'         => [
//                        'recordID'         => $entity->id . '/' . $i,
//                        // Unique record ID for this parcel
//                        'prefixParcelCode' => $entity->is_balikovna_on_address == 1 ? 'DR' : 'NB',
//                        // Prefix based on address
//                        'weight'           => strval(min($entity->width / 20 / $entity->parcel_count, 49)),
//                        // Set weight for the new parcel
//                        'sequenceParcel'   => $i,
//                        // Sequence number of this parcel
//                        'quantityParcel'   => $entity->parcel_count,
//                        // Total number of parcels in this multi-part shipment
//                    ],
//                    'addParcelDataServices' => [
//                        '70', // Service code for multi-part parcel
//                        self::determineParcelSize($entity, $entity->parcel_count), // Size of this parcel
//                    ],
//                ];
//            }
//        }

        //'multipartParcelData' => [],  // Adjust multipart data if necessary
        //příklad, chápu to jako "podat další" // musí mít service 70, první zásilka je jako hlavní
        // pak jen dopřidat data dal3ích zásilek do jsonu + sequence parcel a quantity parcel
//            {"addParcelData":{"recordID":"2","prefixParcelCode":"DR","weight":"1.20","sequenceParcel":2,"quantityParcel":4},"addParcelDataServices":["70","M"]},{"addParcelData":{"recordID":"3","prefixParcelCode":"DR","weight":"2.20","sequenceParcel":3,"quantityParcel":4},"addParcelDataServices":["70","M"]},{"addParcelData":{"recordID":"4","prefixParcelCode":"DR","weight":"3.20","sequenceParcel":4,"quantityParcel":4},"addParcelDataServices":["70","M"]}
        //            Vícekusá zásilka - služba 70
        //první zásilka je hlavní zásilka
        //zásilky ve vícekusu musí následovat v jednom requestu po sobě
        //u každé zásilky musí být uvedena služba 70
        //u každé zásilky se uvede element quantityParcel, který značí celkový počet zásilek vícekusu
        //u každé zásilky se uvede element sequenceParcel, který značí pořadí zásilky, to je například u první zásilky 1 ze tří, u druhé zásilky 2 ze tří, u třetí 3 ze tří. Vypisuje se pouze hodnota, čili 1 – 5.
        //u každé zásilky uvést její hmotnost a velikost
        //udanou cenu a dobírku vypsat pouze u hlavní zásilky
        //maximální počet zásilek ve vícekusu je 5
//        ];

        return json_encode($array, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Adds a new parcel to an existing parcel JSON structure, recalculates weight and size,
     * and updates the multi-part parcel data. It handles both the main parcel and additional parcels,
     * adjusting quantity and sequence numbers accordingly.
     *
     * @param string $json The original JSON string representing the parcel data.
     * @param Entity $entity The entity representing the parcel details (e.g., weight, size).
     * @return string        The updated JSON string with the new parcel added.
     */
    public static function addParcelToJson(string $json, Entity $entity): string
    {
        // Decode the original JSON into an associative array for manipulation
        $data = json_decode($json, true);

        // Calculate the total number of parcels from entity
        $totalParcels = $entity->parcel_count;

        // Calculate the total weight for the parcels
        $baseWeight = $entity->width / 20;
        $weightPerParcel = strval(round(min($baseWeight / $totalParcels, 49), 3));  // Split weight equally

        // Determine the size for the parcel based on recalculated dimensions
        $parcelSize = self::determineParcelSize($entity, $totalParcels);

        // Update the main parcel's weight and size in the JSON data (first parcel)
        $data['parcelServiceData']['parcelParams']['weight'] = $weightPerParcel;
        $data['parcelServiceData']['parcelServices'][0] = $parcelSize; // Update the first element as parcel size

        // If there are multiple parcels, add '70' to the first parcel as well
        if ($totalParcels > 1) {
            // Add '70' to the first parcel's services if we have multiple parcels
            $data['parcelServiceData']['parcelServices'][] = '70';
        }

        // Prepare the parcel data for each additional parcel
        $data['multipartParcelData'] = [];
        for ($i = 2; $i <= $totalParcels; $i++) {
            $data['multipartParcelData'][] = [
                'addParcelData'         => [
                    'recordID'         => $entity->id . '/' . $i,
                    // Unique record ID for this parcel
                    'prefixParcelCode' => $entity->is_balikovna_on_address == 1 ? 'DR' : 'NB',
                    // Prefix based on address
                    'weight'           => $weightPerParcel,
                    // Set weight for the parcel
                    'sequenceParcel'   => $i,
                    // Sequence number of this parcel
                    'quantityParcel'   => $totalParcels,
                    // Total number of parcels in this multi-part shipment
                ],
                'addParcelDataServices' => [
                    '70', // Service code for multi-part parcel
                    $parcelSize, // Size of this parcel
                ],
            ];
        }

        // Update the main parcel data with the total quantity of parcels
        $data['parcelServiceData']['parcelParams']['quantityParcel'] = $totalParcels;
        $data['parcelServiceData']['parcelParams']['sequenceParcel'] = 1; // The first parcel in the sequence

        // If this is the first multipart parcel, ensure that the '70' service is added for multipart shipments
        if (empty($data['parcelServiceData']['parcelServices'])) {
            $data['parcelServiceData']['parcelServices'][] = '70';
        }

        // Return the updated JSON string with all the parcels added
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private static function prepareParcelAddress(Entity $entity)
    {
        $phone = strval($entity->phone);
        if (substr($phone, 0, 3) === '420' && strlen($phone) === 12) {
            $phone = substr($phone, 3); // Remove '420' prefix
        }
        // If balikovna is on the address, use the order address directly
        if ($entity?->is_balikovna_on_address == 1) {
            return [
                'firstName'      => $entity->firstName,
                'surname'        => $entity->lastName,
                'company'        => $entity->billing_company ?? '',
                // neptaj se na ičo ? nikde
                'aditionAddress' => $entity->private_note ?? '',
                // Doplňující informace k názvu adresát - Informace budou vytištěny na štítku
                'address'        => [
                    'street'     => $entity->street,
                    'city'       => $entity->city,
                    'zipCode'    => $entity->postal_code,
                    'isoCountry' => $entity->country,
                ],
                'mobilNumber'    => $phone,
                'phoneNumber'    => $phone,
                'emailAddress'   => $entity->email,
                'subject'        => !empty($entity->billing_company) ? 'P' : 'F',
                // neptaj se na ičo ? nikde
            ];
        } else {
            // Parse Balikovna address from JSON

            return [
                'recordID'       => strval($entity->id),
                'firstName'      => $entity->firstName,
                'surname'        => $entity->lastName,
                'company'        => $entity->billing_company ?? '',
                'aditionAddress' => $entity?->private_note ?? '',
                // Doplňující informace k názvu adresát - Informace budou vytištěny na štítku
                'address'        => [
                    'street'  => "BALÍKOVNA", // According to the documentation, the address is just 'BALÍKOVNA'
                    'city'    => $entity->balikovna_name,
                    'zipCode' => $entity->balikovna_zip,
                ],
                'mobilNumber'    => $phone,
                'phoneNumber'    => $phone,
                'emailAddress'   => $entity->email,
                'subject'        => !empty($entity->billing_company) ? 'P' : 'F',
                // Assuming 'P' is for company, 'F' is for physical person
            ];
        }
    }

    /**
     * Function to send a parcel printing request.
     *
     * @param Entity $entity The entity containing the printing data.
     * @param int $formID ID of the form for label printing.
     * @param int $shiftHorizontal Horizontal shift value in mm.
     * @param int $shiftVertical Vertical shift value in mm.
     * @param int $position Position value on A4.
     * @return CoreResponse The response from the API.
     */
    public static function parcelPrinting(string $parcelCode, int $formID = null, int $shiftHorizontal = 0, int $shiftVertical = 0, int $position = 1): CoreResponse
        //https://www.ceskaposta.cz/napi/b2b#parcelPrinting
    {
        // Prepare the request data
        $customerID = config('parcelable.BALIKOVNA_CUSTOMER_ID');
        $contractNumber = config('parcelable.BALIKOVNA_ID_CCK');
        if (!$formID) {
            $formID = config('parcelable.BALIKOVNA_FORM_ID');
        }

        $data = [
            'printingHeader' => [
                'customerID'      => $customerID,
                'contractNumber'  => $contractNumber,
                'idForm'          => $formID,
                'shiftHorizontal' => $shiftHorizontal,
                'shiftVertical'   => $shiftVertical,
                'position'        => $position,
            ],
            'printingData'   => [
                $parcelCode,
                //                'NB0600004030U' //ok v dokumentaci to nepíšou ale je to normalně "parcelCode" (čarovej kod zasilky)
            ],
            //v odpovědi printingDataResult	 	Data štítku v base64 kódování
        ];

        // Send the request and return the response
        return self::getResponse('parcelPrinting', $data);
    }
}


?>
