<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Entity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ondrejsanetrnik\Core\CoreResponse;

/**
 * DPD přes vlastní smlouvu (DPD GeoAPI). Odesílatel = registrovaná adresa (it4emId).
 * Výdejní místo: až doplníte widget, ukládejte PUDO ID do {@see Entity::$packeta}.
 */
class Dpd
{
    use Concerns\DpdParcelIdentifier;

    public const STATUS_MAP = [
        'Parcel is delivered to recipient'              => 'Doručena',
        'Delivered'                                     => 'Doručena',
        'Parcel picked up by delivery driver'           => 'Doručována',
        'Parcel has been given additional information'  => 'V přepravě',
        'Accepted on delivery Depot'                    => 'V přepravě',
        'Parcel is scanned on hub'                      => 'V přepravě',
        'Parcel accepted on dispatch depot from driver' => 'Přijata k přepravě',
        'Parcel accepted on dispatch depot'             => 'Přijata k přepravě',
        'Předáno příjemci'                              => 'Doručena',
        'Předáno do rukou'                              => 'Doručena',
    ];

    public static function getCostFor(ParcelableContract $parcelable): float
    {
        return 60.0;
    }

    public static function createFrom(
        Entity $entity,
        string $type = ''
    ): CoreResponse {
        $coreResponse = new CoreResponse();

        $type = $type ?: $entity->default_parcel_type;
        if ($type === 'claim') return $coreResponse->fail('Reklamační zásilka DPD GeoAPI zatím není podporována.');

        $customerId = config('parcelable.DPD_CUSTOMER_ID');
        if (!$customerId) return $coreResponse->fail('Chybí konfigurace DPD_CUSTOMER_ID (viz GET /me v GeoAPI).');

        $payload = [self::buildShipmentPayload($entity)];

        $response = self::http()->post(self::baseUrl() . '/shipments', $payload);

        if ($response->failed()) {
            return $coreResponse->fail(self::formatHttpError('Vytvoření zásilky DPD', $response));
        }

        $data = $response->json();
        if (!is_array($data) || !isset($data[0]['parcels'][0])) {
            return $coreResponse->fail('Neočekávaná odpověď DPD při vytváření zásilky.');
        }

        $shipment = $data[0];
        $protoParcels = [];

        foreach ($shipment['parcels'] as $parcel) {
            $mainNr = $parcel['parcelNumbers']['main'] ?? null;
            if (!$mainNr) {
                return $coreResponse->fail('DPD nevrátilo číslo zásilky (parcelNumbers.main).');
            }

            $labelResponse = self::http()->withHeaders([
                'Accept' => 'application/pdf',
            ])->post(self::baseUrl() . '/parcels/labels', [
                'printType'       => 'PDF',
                'printProperties' => [
                    'pageSize'      => config('parcelable.DPD_LABEL_PAGE_SIZE', 'A6'),
                    'labelsPerPage' => (int)config('parcelable.DPD_LABELS_PER_PAGE', 1),
                ],
                'parcels' => [
                    ['parcelNumber' => $mainNr],
                ],
            ]);

            if ($labelResponse->failed()) {
                return $coreResponse->fail(self::formatHttpError('Tisk štítku DPD', $labelResponse));
            }

            $pdf = $labelResponse->body();
            if ($pdf === '' || str_starts_with(ltrim($pdf), '{')) {
                return $coreResponse->fail('Chyba při stažení PDF štítku DPD.');
            }

            $labelName = str_replace('*', '-', $mainNr) . '.pdf';
            Storage::disk('private')->put('labels/' . $labelName, $pdf);

            $protoParcels[] = (object)[
                'id' => $mainNr,
            ];
        }

        return $coreResponse->success($protoParcels);
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildShipmentPayload(Entity $entity): array
    {
        $parcelCount = max(1, (int)($entity->parcel_count ?: 1));
        $weightGrams = max(1, (int)round($entity->weight * 1000));
        $weightPerParcel = (int)max(1, round($weightGrams / $parcelCount));

        $parcels = [];
        for ($i = 1; $i <= $parcelCount; $i++) {
            $ref = (string)$entity->id . ($parcelCount > 1 ? '-' . $i : '');
            $parcel = [
                'weightGrams' => $weightPerParcel,
                'references'  => [
                    'ref1' => Str::limit($ref, 35, ''),
                    # DPD label PDF/ZPL používá pro tisk REF polí ref3/ref4.
                    'ref3' => Str::limit($ref, 35, ''),
                ],
            ];
            $valueCzk = (float)($entity->value_for_parcel ?? 0);
            # GeoAPI přijímá additional insurance jen v CZK
            if ($valueCzk > 0 && self::normalizeCountry($entity->country) === 'CZ') {
                $parcel['additionalServices']['insurance'] = [
                    'amountCents' => (int)round($valueCzk * 100),
                    'currency'    => 'CZK',
                ];
            }
            $parcels[] = $parcel;
        }

        $receiverName = Str::limit(trim($entity->name ?: 'Zákazník'), 35, '');
        $contactPerson = Str::limit(trim(Str::before($entity->name, ' ') ?: $receiverName), 35, '');
        if (mb_strlen($contactPerson) < 2) {
            $contactPerson = Str::limit(trim($receiverName ?: 'Zákazník'), 35, '');
        }

        $payload = [
            'customer'     => ['id' => (int)config('parcelable.DPD_CUSTOMER_ID')],
            'shipmentType' => 'Standard',
            'references'   => [
                'ref1' => (string)$entity->id,
            ],
            'sender' => [
                'it4emId' => (int)config('parcelable.DPD_SENDER_IT4EM_ID'),
            ],
            'receiver' => [
                'info' => [
                    'name1'   => $receiverName,
                    'contact' => array_filter([
                        'person' => $contactPerson,
                        'phone'  => self::normalizePhone($entity->phone),
                        'email'  => $entity->email ?: null,
                    ]),
                ],
                'address' => [
                    'street'     => Str::limit(trim($entity->street ?: '—'), 255, ''),
                    'postalCode' => preg_replace('/\s+/', '', (string)($entity->postal_code ?? '')),
                    'city'       => Str::limit(trim($entity->city ?: '—'), 255, ''),
                    'country'    => ['isoAlpha2' => self::normalizeCountry($entity->country)],
                ],
            ],
            'parcels'  => $parcels,
            'services' => self::buildServices($entity),
        ];

        return $payload;
    }

    /**
     * @return array<string, mixed>|object
     */
    private static function buildServices(Entity $entity): array|object
    {
        $services = [];

        if ($entity->is_cod) {
            $currency = $entity->national_currency ?: 'CZK';
            $parcelCount = max(1, (int)($entity->parcel_count ?: 1));
            $amountCents = (int)round((float)$entity->cod_for_parcel * $parcelCount * 100);
            $services['cashOnDelivery'] = [
                'amountCents' => $amountCents,
                'currency'    => $currency,
                'payment'     => 'Cash',
            ];
        }

        $pudo = trim((string)($entity->packeta ?? ''));
        if ($pudo !== '') {
            $services['pickupPoint'] = $pudo;
        }

        if (config('parcelable.DPD_NOTIFICATION', false)) {
            $services['notification'] = true;
        }

        return $services === [] ? (object)[] : $services;
    }

    private static function normalizePhone(?string $phone): string
    {
        $p = preg_replace('/\s+/', '', (string)$phone);

        return $p !== '' ? Str::limit($p, 31, '') : '000000000';
    }

    private static function normalizeCountry(?string $country): string
    {
        $c = strtoupper(trim((string)$country));

        return strlen($c) === 2 ? $c : 'CZ';
    }

    public static function getParcelStatus(int|string $parcelNumber): CoreResponse
    {
        $response = new CoreResponse();

        $parcelNumber = is_string($parcelNumber) ? preg_replace('/\s+/', '', $parcelNumber) : (string)$parcelNumber;

        $http = self::http()->get(self::baseUrl() . '/parcels/' . rawurlencode($parcelNumber) . '/tracking');

        if ($http->failed()) {
            return $response->fail(self::formatHttpError('Stav zásilky DPD', $http));
        }

        $json = $http->json();
        $events = $json['parcelEvents'] ?? null;
        if (!is_array($events) || $events === []) {
            Log::channel('separated')->warning('DPD tracking has no parcelEvents', [
                'parcelNumber' => $parcelNumber,
            ]);

            return $response->fail('DPD nevrátilo žádné události sledování.');
        }

        usort($events, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
        $latest = $events[0];
        $desc = $latest['status']['description'] ?? '';
        $code = (string)($latest['status']['statusCode'] ?? '');

        $mapped = self::STATUS_MAP[$desc] ?? null;
        if ($mapped === null && $code === '13') {
            $mapped = 'Doručena';
        }
        if ($mapped === null) {
            Log::channel('separated')->warning('DPD status not mapped', [
                'description'  => $desc,
                'statusCode'   => $code,
                'parcelNumber' => $parcelNumber,
            ]);
            $mapped = 'V přepravě';
        }

        $statusObject = (object)[
            'status' => $mapped,
        ];

        return $response->success($statusObject);
    }

    private static function baseUrl(): string
    {
        return rtrim(config('parcelable.DPD_USE_TEST_ENV') ? config('parcelable.DPD_GEOAPI_BASE_TEST') : config('parcelable.DPD_GEOAPI_BASE'), '/');
    }

    private static function apiKey(): string
    {
        if (config('parcelable.DPD_USE_TEST_ENV')) {
            return (string)config('parcelable.DPD_API_KEY_TEST');
        }

        return (string)config('parcelable.DPD_API_KEY');
    }

    private static function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'x-api-key'    => self::apiKey(),
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ])->timeout(120);
    }

    private static function formatHttpError(string $context, \Illuminate\Http\Client\Response $response): string
    {
        $body = $response->json();
        if (is_array($body)) {
            $message = (string)($body['message'] ?? 'Unknown error');
            $details = $body['description'] ?? null;
            $detailsText = is_null($details) ? '' : ' | details: ' . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $code = isset($body['code']) ? '[' . $body['code'] . '] ' : '';

            return $context . ': HTTP ' . $response->status() . ' — ' . $code . $message . $detailsText;
        }

        return $context . ': HTTP ' . $response->status() . ' — ' . Str::limit($response->body(), 2000, '…');
    }
}
