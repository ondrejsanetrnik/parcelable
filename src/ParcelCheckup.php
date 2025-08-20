<?php

namespace Ondrejsanetrnik\Parcelable;

class ParcelCheckup
{
    /**
     * Updates the statuses of all recent parcels. 1 in 100 chance to check all parcels
     *
     * @return void
     */
    public function __invoke(): void
    {
        Parcel::query()
            ->where('carrier', '!=', 'Allegro One') # Allegro One is updated in batch through separate call
            ->whereIn('status', Parcel::ON_THE_WAY_STATUSES)
            ->when(rand(1, 100) <= 99, fn($q) => $q->where('updated_at', '>', now()->subMonth()))
            ->inRandomOrder()
            ->limit(10000)
            ->get()
            ->each
            ->updateStatus();
    }
}
