<?php

namespace Ondrejsanetrnik\Parcelable\Concerns;

trait DpdParcelIdentifier
{
    # DPD numeric destination country on barcode.
    private const BARCODE_COUNTRY_CZ = '203';

    private const BARCODE_COUNTRY_SK = '703';

    /**
     * Extracts the tracking number from DPD barcode (IPPPPPPPTTTTTTTTTTTTTTSSSCCC, 28 digits)
     * or returns a plain 14-digit tracking number as-is
     */
    public static function trackingNumberFromBarcode(string $barcode): string
    {
        # DPD barcode format: 27 or 28 digits (I+postcode+tracking+service+country)
        # Some scanners wrap values with "%" prefix or "°" suffix.
        $normalizedBarcode = ltrim(rtrim($barcode, '°'), '%');

        if (preg_match('/^\d{27,28}$/', $normalizedBarcode)) {
            return substr($normalizedBarcode, 7, 14);
        }

        return $barcode;
    }

    public static function isBarcode(?string $barcode = null): bool
    {
        if (!$barcode) {
            return false;
        }

        return self::trackingNumberFromBarcode($barcode) !== $barcode;
    }

    /**
     * Builds DPD Code 128 payload for Baselinker: % + PSČ(7) + tracking(14) + služba(3) + země(3).
     * GeoAPI returns only parcelNumbers.main (14 digits); full form matches the physical label barcode.
     */
    public static function fullBarcodeForBaselinker(object $entity, string $trackingNumber): string
    {
        $normalized = ltrim(rtrim($trackingNumber, '°'), '%');

        if (preg_match('/^\d{27}$/', $normalized)) {
            return '%' . $normalized;
        }

        $main = preg_replace('/\D/', '', self::trackingNumberFromBarcode($trackingNumber));
        if (strlen($main) !== 14) {
            return $trackingNumber;
        }

        $postcode = self::postcodeFieldForBarcode($entity->postal_code ?? null);
        $service = str_pad((string)self::it4emServiceCodeForBaselinker($entity), 3, '0', STR_PAD_LEFT);
        $country = self::destinationCountryCodeForBarcode($entity->country ?? 'CZ');

        return '%' . $postcode . $main . $service . $country;
    }

    private static function postcodeFieldForBarcode(?string $postalCode): string
    {
        $digits = preg_replace('/\D/', '', (string)$postalCode);
        if ($digits === '') {
            $digits = '0';
        }

        return str_pad(substr($digits, -7), 7, '0', STR_PAD_LEFT);
    }

    private static function destinationCountryCodeForBarcode(?string $country): string
    {
        return match (strtoupper(trim((string)$country))) {
            'CZ'    => self::BARCODE_COUNTRY_CZ,
            'SK'    => self::BARCODE_COUNTRY_SK,
            default => self::BARCODE_COUNTRY_CZ,
        };
    }
}
