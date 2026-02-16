<?php

namespace Ondrejsanetrnik\Parcelable;

class Dpd
{
    use BaselinkerDeliverable;

    public const COURIER_CODE = 'allegrokurier';
//    public const COURIER_CODE = 'dpd'; # use DPD if we want to send via our own contract

    /**
     * Extracts the tracking number from DPD barcode (IPPPPPPPTTTTTTTTTTTTTTSSSCCC, 28 digits)
     * or returns a plain 14-digit tracking number as-is
     */
    public static function trackingNumberFromBarcode(string $barcode): string
    {
        # DPD barcode format: 27 or 28 digits (I+postcode+tracking+service+country)
        if (preg_match('/^\d{27,28}$/', $barcode)) return substr($barcode, 7, 14);

        return $barcode;
    }

    public static function isBarcode(?string $barcode = null): bool
    {
        if (!$barcode) return false;

        return boolval(self::trackingNumberFromBarcode($barcode) !== $barcode);
    }
}
