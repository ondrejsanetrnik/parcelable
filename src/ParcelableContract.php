<?php

namespace Ondrejsanetrnik\Parcelable;

interface ParcelableContract
{
    public function getCodForParcelAttribute(): float;

    public function getValueForParcelAttribute(): float;

    public function getNationalCurrencyAttribute(): string;

    public function getCarrierPickupPointAttribute(): ?string;

    public function getDefaultParcelTypeAttribute(): string;
}
