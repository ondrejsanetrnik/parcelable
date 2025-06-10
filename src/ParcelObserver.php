<?php

namespace Ondrejsanetrnik\Parcelable;

use Illuminate\Support\Facades\Log;

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
            try {
                $parcel->parcelable?->fire('updated', 'parcelChange');
            } catch (\Throwable $e) {
                Log::channel('separated')->warning('Parcelable failed to fire parcelChange for ' . $parcel->parcelable->model_name_identifier);
            }
        }
    }
}
