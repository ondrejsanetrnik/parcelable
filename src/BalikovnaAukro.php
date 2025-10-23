<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Entity;
use App\Objects\Exception;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;

class BalikovnaAukro extends Balikovna
{

    protected static function getConfig(): array
    {
        $dev = App::isProduction() ? 'AUKRO_' : 'TEST_';
        return [
            'BALIKOVNA_API_TOKEN' => config('parcelable.BALIKOVNA_' . $dev . 'API_TOKEN'),
            'BALIKOVNA_SECRET_KEY' => config('parcelable.BALIKOVNA_' . $dev . 'SECRET_KEY'),
            'BALIKOVNA_BASE_URL' => config('parcelable.BALIKOVNA_' . $dev . 'BASE_URL'),
            'BALIKOVNA_FORM_ID' => config('parcelable.BALIKOVNA_' . $dev . 'FORM_ID'),
            'BALIKOVNA_CUSTOMER_ID' => config('parcelable.BALIKOVNA_' . $dev . 'CUSTOMER_ID'),
            'BALIKOVNA_LOCATION_NUMBER' => config('parcelable.BALIKOVNA_' . $dev . 'LOCATION_NUMBER'),
            'BALIKOVNA_POST_CODE' => config('parcelable.BALIKOVNA_' . $dev . 'POST_CODE'),
            'BALIKOVNA_ID_CCK' => config('parcelable.BALIKOVNA_' . $dev . 'ID_CCK'),
        ];
    }
}

?>
