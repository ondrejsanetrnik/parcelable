<?php

namespace Ondrejsanetrnik\Parcelable;

use Illuminate\Support\Facades\App;
use Ondrejsanetrnik\Parcelable\Parcel;

class ParcelObserver
{
    /**
     * Handle the parcel "updated" event.
     *
     * @param Parcel $parcel
     * @return void
     */
    public function created(Parcel $parcel): void
    {
        // $parcel->parcelable->fire('updated', 'parcelChange'); # Parcelable not yet there on creation
    }

    /**
     * Handle the parcel "updated" event.
     *
     * @param Parcel $parcel
     * @return void
     */
    public function updated(Parcel $parcel): void
    {
        if (
            array_key_exists('status', $parcel->getChanges()) ||
            array_key_exists('parcelable_id', $parcel->getChanges())
        ) {
            $parcel->parcelable?->fire('updated', 'parcelChange');
        }
    }
}
