<?php

namespace Ondrejsanetrnik\Parcelable;

/**
 * Výběr konkrétní carrier třídy (včetně variant pro Aukro/Baselinker).
 */
final class CarrierClassResolver
{
    /**
     * @return class-string
     */
    public static function resolve(string $carrierName, ?object $context = null): string
    {
        if ($carrierName === 'Balíkovna' && ($context?->aukro_id ?? null)) {
            return BalikovnaAukro::class;
        }

        if (
            $carrierName === 'DPD'
            && ($context?->baselinker_id ?? null)
            && ($context?->source ?? null) !== 'alza'
        ) {
            return DpdAllegro::class;
        }

        return Parcel::CARRIER_CLASS[$carrierName];
    }
}
