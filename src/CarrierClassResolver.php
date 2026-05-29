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

        # Baselinker + DPD: vlastní GeoAPI (CZ) vs kurýr Allegro; Alza vždy {@see Dpd} přes výchozí mapování.
        if ($carrierName === 'DPD' && ($context?->baselinker_id ?? null) && !self::isAlzaSource($context)) {
            return self::usesOwnCzDpdContract($context) ? Dpd::class : DpdAllegro::class;
        }

        return Parcel::CARRIER_CLASS[$carrierName];
    }

    public static function isAlzaSource(?object $context): bool
    {
        return strcasecmp((string)($context?->source ?? ''), 'alza') === 0;
    }

    private static function usesOwnCzDpdContract(?object $context): bool
    {
        $delivery = (string)($context->delivery ?? '');

        return strtoupper(trim((string)($context->country ?? ''))) === 'CZ'
            && in_array($delivery, ['DPD', 'DPD Pickup'], true)
            && strcasecmp((string)($context->source ?? ''), 'allegro') !== 0
            && $delivery !== 'Allegro One';
    }
}
