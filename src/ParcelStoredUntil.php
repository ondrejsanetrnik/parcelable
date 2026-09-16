<?php

namespace Ondrejsanetrnik\Parcelable;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Last pickup day for "Poslední šance na vyzvednutí".
 * Packeta supplies the date; GLS / DPD / Allegro do not, so we derive it from the first
 * ready-for-pickup scan plus the carrier's published hold (CZ shop vs box).
 */
final class ParcelStoredUntil
{
    public const PICKUP_STATUS = 'Připravena k vyzvednutí';

    public const GLS_SHOP_WEEKDAYS = 5;

    public const GLS_BOX_DAYS = 3;

    public const DPD_SHOP_DAYS = 7;

    public const DPD_LOCKER_HOURS = 48;

    public const ALLEGRO_POINT_DAYS = 7;

    private const BASELINKER_WAITING_AT_POINT = 8;

    private const TIMEZONE = 'Europe/Prague';

    private const GLS_BOX_CODES = ['54'];

    private const GLS_SHOP_CODES = ['55', '56'];

    private const GLS_BOX_DESCRIPTIONS = [
        'ParcelLocker deposit',
        'Připraveno v ParcelBoxu',
    ];

    private const GLS_SHOP_DESCRIPTIONS = [
        'Doručení do ParcelShopu',
        'Uskladněno v ParcelShopu',
        'Připraveno v ParcelShopu',
        'Uskladněno na výdejním místě',
    ];

    private const DPD_LOCKER_DESCRIPTIONS = [
        'Locker ready to pickup',
    ];

    private const DPD_SHOP_DESCRIPTIONS = [
        'Delivered to pickup point',
    ];

    public static function dateString(?CarbonInterface $deadline): ?string
    {
        return $deadline?->timezone(self::TIMEZONE)->toDateString();
    }

    /**
     * @param iterable<int, object> $statuses Newest-first GLS ParcelStatusList
     */
    public static function fromGlsStatusList(iterable $statuses): ?string
    {
        $pickup = self::earliestPickupScan($statuses, function (object $status): ?array {
            $kind = self::glsPickupKind(
                (string)($status->StatusDescription ?? ''),
                (string)($status->StatusCode ?? ''),
            );
            if ($kind === null) {
                return null;
            }

            $at = ParcelTrackingEvents::parseGlsDate($status->StatusDate ?? null);
            if ($at === null) {
                return null;
            }

            return ['at' => Carbon::parse($at), 'kind' => $kind];
        });

        if ($pickup === null) {
            return null;
        }

        $deadline = $pickup['kind'] === 'box'
            ? self::addCalendarDays($pickup['at'], self::GLS_BOX_DAYS)
            : self::addWeekdays($pickup['at'], self::GLS_SHOP_WEEKDAYS);

        return self::dateString($deadline);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    public static function fromDpdParcelEvents(array $events): ?string
    {
        $pickup = self::earliestPickupScan($events, function (mixed $event): ?array {
            if (!is_array($event)) {
                return null;
            }

            $kind = self::dpdPickupKind(
                rtrim(trim((string)($event['status']['description'] ?? '')), '.'),
            );
            if ($kind === null) {
                return null;
            }

            try {
                $at = Carbon::parse((string)($event['createdAt'] ?? ''));
            } catch (\Throwable) {
                return null;
            }

            return ['at' => $at, 'kind' => $kind];
        });

        if ($pickup === null) {
            return null;
        }

        $deadline = $pickup['kind'] === 'locker'
            ? self::addHours($pickup['at'], self::DPD_LOCKER_HOURS)
            : self::addCalendarDays($pickup['at'], self::DPD_SHOP_DAYS);

        return self::dateString($deadline);
    }

    /**
     * @param list<array<string, mixed>> $history Baselinker packages_history rows
     */
    public static function fromBaselinkerHistory(array $history): ?string
    {
        $earliest = null;

        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((int)($row['tracking_status'] ?? 0) !== self::BASELINKER_WAITING_AT_POINT) {
                continue;
            }

            $timestamp = $row['tracking_status_date'] ?? null;
            if (!is_numeric($timestamp)) {
                continue;
            }

            $at = Carbon::createFromTimestamp((int)$timestamp, self::TIMEZONE);
            if ($earliest === null || $at->lt($earliest)) {
                $earliest = $at;
            }
        }

        if ($earliest === null) {
            return null;
        }

        return self::dateString(self::addCalendarDays($earliest, self::ALLEGRO_POINT_DAYS));
    }

    public static function fromPacketaStoredUntil(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return self::dateString($value);
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        try {
            return self::dateString(Carbon::parse($value, self::TIMEZONE));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function addCalendarDays(CarbonInterface $arrivedAt, int $days): CarbonInterface
    {
        return $arrivedAt->copy()->timezone(self::TIMEZONE)->startOfDay()->addDays($days);
    }

    private static function addWeekdays(CarbonInterface $arrivedAt, int $days): CarbonInterface
    {
        return $arrivedAt->copy()->timezone(self::TIMEZONE)->startOfDay()->addWeekdays($days);
    }

    private static function addHours(CarbonInterface $arrivedAt, int $hours): CarbonInterface
    {
        return $arrivedAt->copy()->timezone(self::TIMEZONE)->addHours($hours);
    }

    /**
     * @param iterable<int, mixed> $items
     * @param callable(mixed): ?array{at: CarbonInterface, kind: string} $extract
     * @return array{at: CarbonInterface, kind: string}|null
     */
    private static function earliestPickupScan(iterable $items, callable $extract): ?array
    {
        $pickup = null;

        foreach ($items as $item) {
            $scan = $extract($item);
            if ($scan === null) {
                continue;
            }

            if ($pickup === null || $scan['at']->lt($pickup['at'])) {
                $pickup = $scan;
            }
        }

        return $pickup;
    }

    private static function glsPickupKind(string $description, string $code): ?string
    {
        $code = ltrim($code, '0');
        if (in_array($code, self::GLS_BOX_CODES, true)) {
            return 'box';
        }
        if (in_array($code, self::GLS_SHOP_CODES, true)) {
            return 'shop';
        }
        if (in_array($description, self::GLS_BOX_DESCRIPTIONS, true)) {
            return 'box';
        }
        if (in_array($description, self::GLS_SHOP_DESCRIPTIONS, true)) {
            return 'shop';
        }

        return null;
    }

    private static function dpdPickupKind(string $description): ?string
    {
        if (in_array($description, self::DPD_LOCKER_DESCRIPTIONS, true)) {
            return 'locker';
        }
        if (in_array($description, self::DPD_SHOP_DESCRIPTIONS, true)) {
            return 'shop';
        }

        return null;
    }
}
