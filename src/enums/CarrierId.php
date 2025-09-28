<?php

namespace Ondrejsanetrnik\Parcelable\enums;

/**
 * https://pickup-point.api.packeta.com/v5/d3b8401799d472fd/carrier/json?lang=cs
 */
enum CarrierId: int
{

    case PL_INPOST = 3060;
    case PL_POCZTA_POLSKA = 14052;
    case HU_FOXPOST_BOX = 32970;
    case DE_HERMES_PP = 6828;
    case NL_DHL_PP = 8001;
    case HU_HUNGARIAN_POST_PP = 4539;
    case HU_HUNGARIAN_POST_BOX = 29760;
    case ES_MRW_PP = 4654;
    case LV_OMNIVA_BOX = 5064;
    case GR_BOXNOW_BOX = 20409;
    case RO_SAMEDAY_BOX = 7455;
    case LT_OMNIVA_BOX = 5066;
    case BE_BELGIAN_POST_PP = 7910;
    case FR_COLLISIMO_PP = 4307;
    case LT_LITHUANIAN_POST_BOX = 18809;
    case EE_OMNIVA_BOX = 5062;
    case SE_POST_NORD_PP = 4826;

    public static function getAllowedIdsForDirectLabelPrinting(): array
    {
        return [
            self::PL_INPOST->value,
            self::PL_POCZTA_POLSKA->value,
            self::HU_FOXPOST_BOX->value,
            self::DE_HERMES_PP->value,
        ];
    }
}

