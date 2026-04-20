<?php

namespace Ondrejsanetrnik\Parcelable;

/**
 * DPD zásilky z Allegro / přes Baselinker (kurýr allegrokurier).
 */
class DpdAllegro
{
    use BaselinkerDeliverable;
    use Concerns\DpdParcelIdentifier;

    public const COURIER_CODE = 'allegrokurier';
}
