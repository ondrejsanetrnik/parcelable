<?php

namespace Ondrejsanetrnik\Parcelable\enums;

enum CarrierId: int
{
    case INPOST = 3060;
    case POCZTA_POLSKA = 14052;

    public static function getAllowedIdsForDirectLabelPrinting(): array
    {
        return [
            self::INPOST->value,
        ];
    }
}

