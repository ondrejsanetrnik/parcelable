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

    # GeoAPI internal CustomerID is short; DPD customer number (DSW) is much longer.
    private const CUSTOMER_INTERNAL_ID_MAX_LENGTH = 6;

    # GeoAPI references: up to 4 strings, max 35 chars each (shipment + parcel level).
    private const REFERENCE_MAX_LENGTH = 35;

    # Baselinker Alza Trade delivery_method values (synced to orders.carrier_id).
    private const ALZA_CARRIER_HOME = 'DPD-DPD';

    private const ALZA_CARRIER_BOX = 'DPD-DPDALZABOX';

    private const ALZA_CARRIER_SUPPLIER = 'DPD-Supplier';

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

        $account = self::accountFor($entity);
        if (!$account['customer_id']) {
            return $coreResponse->fail('Chybí DPD účet pro eshop ' . ($entity->eshop ?? '?') . ' (config parcelable.DPD_ACCOUNTS).');
        }

        try {
            $payload = [self::buildShipmentPayload($entity)];
        } catch (\RuntimeException $e) {
            return $coreResponse->fail($e->getMessage());
        }

        $response = self::http($account)->post(self::baseUrl() . '/shipments', $payload);

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

            $labelResponse = self::http($account)->withHeaders([
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

    private static function buildShipmentPayload(Entity $entity): array
    {
        $account = self::accountFor($entity);
        $parcelCount = max(1, (int)($entity->parcel_count ?: 1));
        $weightGrams = max(1, (int)round($entity->weight * 1000));
        $weightPerParcel = (int)max(1, round($weightGrams / $parcelCount));
        $references = self::orderReferences($entity);

        $parcels = [];
        for ($i = 1; $i <= $parcelCount; $i++) {
            $parcel = [
                'weightGrams' => $weightPerParcel,
                'references'  => self::parcelReferences($references, $i, $parcelCount),
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

        $shipmentReferences = [
            'ref1' => self::limitReference($references['primary']),
        ];
        if ($references['secondary'] !== null) {
            $shipmentReferences['ref2'] = self::limitReference($references['secondary']);
        }

        $payload = [
            'customer'     => self::buildCustomerIdent($account),
            'shipmentType' => 'Standard',
            'references'   => $shipmentReferences,
            'sender'       => [
                'it4emId' => $account['sender_it4em_id'],
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
     * Alza marketplace orders use external_id (Baselinker external_order_id) on the label;
     * our internal order id is kept as a secondary reference when both fit.
     *
     * @return array{primary: string, secondary: string|null}
     */
    private static function orderReferences(Entity $entity): array
    {
        $internalId = (string)$entity->id;
        $externalId = trim((string)($entity->external_id ?? ''));

        if (CarrierClassResolver::isAlzaSource($entity) && $externalId !== '') {
            return [
                'primary'   => $externalId,
                'secondary' => $internalId,
            ];
        }

        return [
            'primary'   => $internalId,
            'secondary' => null,
        ];
    }

    /**
     * @param array{primary: string, secondary: string|null} $references
     * @return array<string, string>
     */
    private static function parcelReferences(array $references, int $parcelIndex, int $parcelCount): array
    {
        $suffix = $parcelCount > 1 ? '-' . $parcelIndex : '';
        $primary = self::limitReference($references['primary'] . $suffix);

        $parcelReferences = [
            'ref1' => $primary,
            # DPD label PDF/ZPL uses ref3/ref4 for printed REF fields.
            'ref3' => $primary,
        ];

        if ($references['secondary'] !== null) {
            $parcelReferences['ref4'] = self::limitReference($references['secondary'] . $suffix);
        }

        return $parcelReferences;
    }

    private static function limitReference(string $reference): string
    {
        return Str::limit($reference, self::REFERENCE_MAX_LENGTH, '');
    }

    /**
     * GeoAPI service elements → IT4EM product on label (Alza Trade contract):
     * 327/329 home (notification, optional COD), 337 box (pickupPoint + notification), 101 supplier (empty services).
     *
     * @return array<string, mixed>|object
     */
    private static function buildServices(Entity $entity): array|object
    {
        if (CarrierClassResolver::isAlzaSource($entity)) {
            return self::buildAlzaTradeServices($entity);
        }

        $services = self::codService($entity);

        $pudo = self::defaultPickupPoint($entity);
        if ($pudo !== '') {
            $services['pickupPoint'] = $pudo;
        }

        # GeoAPI rejects PickupPoint without Notification (InvalidServiceCombination).
        if ($pudo !== '' || config('parcelable.DPD_NOTIFICATION', false)) {
            $services['notification'] = true;
        }

        return $services === [] ? (object)[] : $services;
    }

    /**
     * Alza Trade: carrier_id from Baselinker delivery_method (see GET /shipping-services examples).
     */
    private static function buildAlzaTradeServices(Entity $entity): array|object
    {
        $services = self::codService($entity);
        $method = trim((string)($entity->carrier_id ?? ''));

        if ($method === '' || !str_starts_with($method, 'DPD-')) {
            $method = self::inferAlzaCarrierId($entity, $method);
        }

        if ($method === self::ALZA_CARRIER_SUPPLIER) {
            # 101 — GeoAPI example uses empty services (no notification, no pickupPoint).
            return $services === [] ? (object)[] : $services;
        }

        if ($method === self::ALZA_CARRIER_BOX || str_contains($method, 'ALZABOX') || str_contains($method, 'DPDALZABOX')) {
            $pudo = self::requirePickupPointId($entity, $method ?: self::ALZA_CARRIER_BOX);
            if ($pudo !== '') {
                $services['pickupPoint'] = $pudo;
            }
            $services['notification'] = true;

            return $services;
        }

        # DPD-DPD (and fallback): 327/329 — notification only, never packeta on home delivery.
        $services['notification'] = true;

        return $services;
    }

    private static function inferAlzaCarrierId(Entity $entity, string $method): string
    {
        if ($method !== '') {
            return $method;
        }

        $packeta = trim((string)($entity->packeta ?? ''));
        if ($packeta !== '' && str_starts_with(strtoupper($packeta), 'CZ')) {
            return self::ALZA_CARRIER_BOX;
        }

        if ($packeta !== '' && str_starts_with(strtoupper($packeta), 'AL')) {
            return self::ALZA_CARRIER_SUPPLIER;
        }

        return self::ALZA_CARRIER_HOME;
    }

    /**
     * @return array<string, mixed>
     */
    private static function codService(Entity $entity): array
    {
        if (!$entity->is_cod) {
            return [];
        }

        $currency = $entity->national_currency ?: 'CZK';
        $parcelCount = max(1, (int)($entity->parcel_count ?: 1));
        $amountCents = (int)round((float)$entity->cod_for_parcel * $parcelCount * 100);

        return [
            'cashOnDelivery' => [
                'amountCents' => $amountCents,
                'currency'    => $currency,
                'payment'     => 'Cash',
            ],
        ];
    }

    private static function defaultPickupPoint(Entity $entity): string
    {
        if (($entity->delivery ?? '') !== 'DPD Pickup') {
            return '';
        }

        return trim((string)($entity->packeta ?? ''));
    }

    private static function requirePickupPointId(Entity $entity, string $context): string
    {
        $pudo = trim((string)($entity->packeta ?? ''));
        if ($pudo === '') {
            throw new \RuntimeException(
                'DPD Alza Trade box: chybí pickup point ID (packeta) pro objednávku ' . $entity->id . ' [' . $context . ']'
            );
        }

        return $pudo;
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

    public static function getParcelStatus(int|string $parcelNumber, Entity $entity): CoreResponse
    {
        $response = new CoreResponse();

        $parcelNumber = is_string($parcelNumber) ? preg_replace('/\s+/', '', $parcelNumber) : (string)$parcelNumber;

        $http = self::http(self::accountFor($entity))->get(self::baseUrl() . '/parcels/' . rawurlencode($parcelNumber) . '/tracking');

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

    /**
     * GeoAPI CustomerIdent: either internal CustomerID (`id`) or DPD customer number (`dsw`).
     *
     * @param array{customer_id: string} $account
     * @return array{dsw: string}|array{id: int}
     */
    private static function buildCustomerIdent(array $account): array
    {
        $customerId = trim((string)($account['customer_id'] ?? ''));
        if ($customerId === '') {
            return [];
        }

        if (strlen($customerId) > self::CUSTOMER_INTERNAL_ID_MAX_LENGTH) {
            return ['dsw' => $customerId];
        }

        return ['id' => (int)$customerId];
    }

    /**
     * @return array{api_key: string, customer_id: string, sender_it4em_id: int}
     */
    private static function accountFor(Entity $entity): array
    {
        $raw = config('parcelable.DPD_ACCOUNTS')[(string)$entity->eshop] ?? [];
        $test = (bool)config('parcelable.DPD_USE_TEST_ENV');

        return [
            'api_key'         => (string)($test ? ($raw['api_key_test'] ?? '') : ($raw['api_key'] ?? '')),
            'customer_id'     => (string)($raw['customer_id'] ?? ''),
            'sender_it4em_id' => (int)($raw['sender_it4em_id'] ?? 0),
        ];
    }

    private static function baseUrl(): string
    {
        return rtrim(config('parcelable.DPD_USE_TEST_ENV') ? config('parcelable.DPD_GEOAPI_BASE_TEST') : config('parcelable.DPD_GEOAPI_BASE'), '/');
    }

    private static function http(array $account): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'x-api-key'    => $account['api_key'],
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
