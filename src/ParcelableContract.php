<?php

namespace Ondrejsanetrnik\Parcelable;

/**
 * Interface ParcelableContract
 *
 * Implementující třída MUSÍ obsahovat veřejné vlastnosti:
 * @property float $weight  Hmotnost zásilky v kg
 * @property string $country  Kód země (např. "CZ")
 * @property string $delivery
 */
interface ParcelableContract
{
    public function getCodForParcelAttribute(): float;

    public function getValueForParcelAttribute(): float;

    public function getNationalCurrencyAttribute(): string;

    public function getCarrierPickupPointAttribute(): ?string;

    public function getDefaultParcelTypeAttribute(): string;
}
