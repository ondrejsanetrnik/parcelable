<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Models\Entity;
use App\Objects\Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Ondrejsanetrnik\Core\CoreResponse;

class BalikovnaAukro extends Balikovna
{
    /**
     * @param $endpoint
     * @param $data
     * @param $method
     * @param $config
     * @return array|CoreResponse
     */
    public static function getResponse($endpoint, $data = null, $method = 'POST', $config = [])
    {
        $config = $config ?: [
            'BALIKOVNA_API_TOKEN' => config('parcelable.BALIKOVNA_AUKRO_API_TOKEN'),
            'BALIKOVNA_SECRET_KEY' => config('parcelable.BALIKOVNA_AUKRO_SECRET_KEY'),
            'BALIKOVNA_BASE_URL' => config('parcelable.BALIKOVNA_AUKRO_BASE_URL')
        ];

        return parent::getResponse($endpoint, $data, $method, $config);
    }
}

?>
