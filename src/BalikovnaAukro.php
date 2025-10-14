<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Entity;
use App\Objects\Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;

class BalikovnaAukro extends Balikovna
{

    protected static function getConfig(): array
    {
        return [
            'BALIKOVNA_API_TOKEN' => config('parcelable.BALIKOVNA_AUKRO_API_TOKEN'),
            'BALIKOVNA_SECRET_KEY' => config('parcelable.BALIKOVNA_AUKRO_SECRET_KEY'),
            'BALIKOVNA_BASE_URL' => config('parcelable.BALIKOVNA_AUKRO_BASE_URL'),
            'BALIKOVNA_FORM_ID' => config('parcelable.BALIKOVNA_AUKRO_FORM_ID'),
            'BALIKOVNA_CUSTOMER_ID' => config('parcelable.BALIKOVNA_AUKRO_CUSTOMER_ID'),
            'BALIKOVNA_LOCATION_NUMBER' => config('parcelable.BALIKOVNA_AUKRO_LOCATION_NUMBER'),
            'BALIKOVNA_POST_CODE' => config('parcelable.BALIKOVNA_AUKRO_POST_CODE'),
            'BALIKOVNA_ID_CCK' => config('parcelable.BALIKOVNA_AUKRO_ID_CCK'),
        ];
    }
}

?>
