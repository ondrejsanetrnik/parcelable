<?php

namespace Ondrejsanetrnik\Parcelable;

use Carbon\Carbon;

/**
 * Normalizes carrier tracking history into one MCP-friendly shape.
 */
final class ParcelTrackingEvents
{
    public const MAX = 30;

    /**
     * @param list<array{at: ?string, description: string, place: ?string, code: ?string}> $events
     * @return list<array{at: ?string, description: string, place: ?string, code: ?string}>
     */
    public static function limit(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $description = trim((string)($event['description'] ?? ''));
            if ($description === '') {
                continue;
            }

            $out[] = [
                'at'          => $event['at'] ?? null,
                'description' => $description,
                'place'       => self::nullableString($event['place'] ?? null),
                'code'        => self::nullableString($event['code'] ?? null),
            ];

            if (count($out) >= self::MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param iterable<int, object> $statuses Newest-first GLS ParcelStatusList
     * @return list<array{at: ?string, description: string, place: ?string, code: ?string}>
     */
    public static function fromGlsStatusList(iterable $statuses): array
    {
        $events = [];
        foreach ($statuses as $status) {
            $events[] = [
                'at'          => self::parseGlsDate($status->StatusDate ?? null),
                'description' => (string)($status->StatusDescription ?? ''),
                'place'       => self::nullableString($status->DepotCity ?? null),
                'code'        => self::nullableString($status->StatusCode ?? null),
            ];
        }

        return self::limit($events);
    }

    /**
     * @param iterable<int, object> $records Chronological Packeta records
     * @return list<array{at: ?string, description: string, place: ?string, code: ?string}>
     */
    public static function fromPacketaRecords(iterable $records): array
    {
        $rows = [];
        foreach ($records as $record) {
            $text = trim((string)($record->statusText ?? ''));
            if ($text === '') {
                $text = (string)($record->codeText ?? '');
            }
            $text = preg_replace('/<br\s*\/?>/i', ' ', $text) ?? $text;
            $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

            $at = isset($record->dateTime) ? (string)$record->dateTime : null;
            if ($at !== null && $at !== '' && !str_contains($at, '+') && !str_ends_with($at, 'Z')) {
                try {
                    $at = Carbon::parse($at, config('app.timezone'))->toIso8601String();
                } catch (\Throwable) {
                    # keep raw
                }
            }

            $rows[] = [
                'at'          => $at !== '' ? $at : null,
                'description' => $text,
                'place'       => null,
                'code'        => self::nullableString($record->codeText ?? $record->statusCode ?? null),
            ];
        }

        return self::limit(array_reverse($rows));
    }

    /**
     * @param list<array<string, mixed>> $events Newest-preferred DPD parcelEvents
     * @return list<array{at: ?string, description: string, place: ?string, code: ?string}>
     */
    public static function fromDpdParcelEvents(array $events): array
    {
        usort($events, fn($a, $b) => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')));

        $rows = [];
        foreach ($events as $event) {
            $location = is_array($event['location'] ?? null) ? $event['location'] : [];
            $depot = is_array($location['depot'] ?? null) ? $location['depot'] : [];
            $place = $depot['name'] ?? $location['city'] ?? $location['address'] ?? null;

            $status = is_array($event['status'] ?? null) ? $event['status'] : [];
            $rows[] = [
                'at'          => self::nullableString($event['createdAt'] ?? null),
                'description' => (string)($status['description'] ?? ''),
                'place'       => self::nullableString(is_string($place) ? $place : null),
                'code'        => self::nullableString($status['statusCode'] ?? null),
            ];
        }

        return self::limit($rows);
    }

    /**
     * @param iterable<int, object|array<string, mixed>> $statuses Balíkovna parcelStatuses
     * @return list<array{at: ?string, description: string, place: ?string, code: ?string}>
     */
    public static function fromBalikovnaStatuses(iterable $statuses): array
    {
        $rows = [];
        foreach ($statuses as $status) {
            $status = (object)$status;
            $rows[] = [
                'at'          => self::nullableString($status->date ?? $status->dateTime ?? null),
                'description' => (string)($status->text ?? $status->name ?? ''),
                'place'       => self::nullableString($status->postOffice ?? $status->place ?? null),
                'code'        => self::nullableString($status->id ?? $status->code ?? null),
            ];
        }

        return self::limit(array_reverse($rows));
    }

    public static function parseGlsDate(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        if (preg_match('/\/Date\((\d+)/', $raw, $matches) !== 1) {
            return null;
        }

        return Carbon::createFromTimestampMs((int)$matches[1])->toIso8601String();
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string)$value);

        return $string === '' || $string === '-' ? null : $string;
    }
}
