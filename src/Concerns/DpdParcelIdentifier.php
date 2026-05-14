<?php

namespace Ondrejsanetrnik\Parcelable\Concerns;

trait DpdParcelIdentifier
{
    /**
     * Normalizes raw scanner output (DPD label spec: Code 128 must not begin with "%" in data, but scanners often add it).
     */
    public static function normalizeDpdBarcodeString(string $barcode): string
    {
        return ltrim(rtrim($barcode, '°'), '%');
    }

    /**
     * Distinct 14-digit parcel numbers to try when matching {@see Parcel} tracking in the database.
     * DPD uses multiple numeric payload lengths on labels (see DPD Parcel Label Specification — Code 128).
     *
     * @return list<string>
     */
    public static function parcelTrackingSearchValues(string $barcode): array
    {
        $n = self::normalizeDpdBarcodeString($barcode);
        $candidates = [];

        # 28-digit (and 27): postcode (7) + tracking (14) + service + country [/ check]
        if (preg_match('/^\d{27,28}$/', $n)) {
            $candidates[] = substr($n, 7, 14);
        }

        # 25-digit: identification (1) + postcode (7) + tracking (14) + service (3)
        if (preg_match('/^\d{25}$/', $n)) {
            $candidates[] = substr($n, 8, 14);
            # Fallback when some devices omit the leading identification digit from the numeric read
            $candidates[] = substr($n, 7, 14);
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Extracts the tracking number from DPD barcode (numeric label payload), or returns plain input if unknown.
     */
    public static function trackingNumberFromBarcode(string $barcode): string
    {
        $candidates = self::parcelTrackingSearchValues($barcode);

        if ($candidates !== []) {
            return $candidates[0];
        }

        return $barcode;
    }

    public static function isBarcode(?string $barcode = null): bool
    {
        if (!$barcode) {
            return false;
        }

        return self::parcelTrackingSearchValues($barcode) !== [];
    }
}
