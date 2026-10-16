<?php

namespace Ondrejsanetrnik\Parcelable;

use Illuminate\Support\Facades\Cache;

/**
 * Optional cache bag of unmapped carrier statuses.
 * Host apps may send a digest; this package never Slacks or calls app classes.
 */
class UnmappedCarrierStatusRecorder
{
    public const CACHE_KEY = 'parcelable:unmapped-carrier-statuses';

    private const TTL_DAYS = 21;

    public static function record(string $carrier, string $code, int|string $sampleParcel): void
    {
        try {
            $code = $code !== '' ? $code : '(empty)';
            $now = now()->toDateTimeString();
            $key = $carrier . '::' . $code;

            $entries = self::all();
            $existing = $entries[$key] ?? [
                'carrier'       => $carrier,
                'code'          => $code,
                'count'         => 0,
                'sample_parcel' => (string)$sampleParcel,
                'first_seen'    => $now,
            ];
            $existing['count'] = (int)$existing['count'] + 1;
            $existing['last_seen'] = $now;
            $entries[$key] = $existing;

            Cache::put(self::CACHE_KEY, $entries, now()->addDays(self::TTL_DAYS));
        } catch (\Throwable) {
            # Tracking must not fail because the host cache is unavailable.
        }
    }

    /**
     * @return array<string, array{carrier: string, code: string, count: int, sample_parcel: string, first_seen: string, last_seen: string}>
     */
    public static function all(): array
    {
        $entries = Cache::get(self::CACHE_KEY, []);

        return is_array($entries) ? $entries : [];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
