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
            ->where(fn($q) => $q->where('carrier', '!=', 'DPD')->orWhereNotNull('parcelable_id'))
            # Also null: unmapped Packeta codes used to wipe status and drop parcels from tracking
            ->where(fn($q) => $q->whereIn('status', Parcel::ON_THE_WAY_STATUSES)->orWhereNull('status'))
            ->when(rand(1, 100) <= 99, fn($q) => $q->where('updated_at', '>', now()->subMonth()))
            ->inRandomOrder()
            ->limit(10000)
            ->get()
            ->each
            ->updateStatus();
    }
}
