<?php

namespace Ondrejsanetrnik\Parcelable;

class ParcelCheckup
{
    /**
     * Updates the statuses of all parcels
     *
     * @return void
     */
    public function __invoke(): void
    {
        Parcel::whereIn('status', Parcel::ON_THE_WAY_STATUSES)->chunk(500, function ($chunk) {
            $chunk->each->updateStatus();
        });
    }
}
