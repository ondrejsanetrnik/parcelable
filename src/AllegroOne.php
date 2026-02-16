<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Helpers\Api;
use App\Models\Entity;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;

class AllegroOne
{
    use BaselinkerDeliverable;
    public const COURIER_CODE= 'allegrokurier';

    public const STATUSES = [
        0  => 'Čeká na vyzvednutí kurýrem', # Unknown
        1  => 'Čeká na vyzvednutí kurýrem', # Courier label created
        2  => 'V přepravě', # Shipped
        3  => 'Na cestě zpátky', # Not delivered
        4  => 'Doručována', # Out for delivery
        5  => 'Doručena', # Delivered
        6  => 'Vrácena obchodu', # Return
        7  => 'V přepravě', # Aviso
        8  => 'Připravena k vyzvednutí', # Waiting at point
        9  => 'V přepravě', # Lost
        10 => 'Stornována', # Canceled
        11 => 'V přepravě', # On the way
        12 => 'V přepravě', # Exception (sorting error, other event, complaint)
        13 => 'V přepravě', # Transferred abroad
    ];

    /**
     * Extracts the tracking number (the number without the order letter)
     *
     * @param string $barcode
     * @return string
     */
    public static function trackingNumberFromBarcode(string $barcode): string
    {
        if (str_starts_with($barcode, 'A00') && strlen($barcode) == 10) return $barcode; # A002MH8633 - new format?
        if (str_starts_with($barcode, '4RA') && strlen($barcode) == 11) return $barcode; # 4RA00179561 - new format 2??

        $rx = '~\b(?P<prefix>[A-Z0-9]{3})(?P<flag>[A-Z])(?P<number>\d{8})(?:\*(?P<piece>\d{3})(?P<total>\d{3}))?\b~';

        if (preg_match($rx, $barcode, $m)) {
            $barcode = $m[0];                             # "4RAM00168524*001001"
            $tracking = $m['prefix'] . $m['number'];      # "4RA00168524" (4th letter removed, suffix dropped)

            return $tracking;
        } else return '';
    }

    public static function isBarcode(?string $barcode = null): bool
    {
        if (!$barcode) return false;

        return boolval(self::trackingNumberFromBarcode($barcode));
    }
}
