<?php

namespace Ondrejsanetrnik\Parcelable;

use Illuminate\Support\Facades\Log;
use Throwable;

class ParcelCheckup
{
    # Windows use created_at. updateStatus() touches updated_at on each successful poll, so an updated_at window never ends.
    # A failed poll does not touch updated_at, so an updated_at window also dropped parcels after one month of carrier errors.
    private const TRACKING_MONTHS = 2;

    # DPD code 13 can mean delivered to us on a return. Keep polling a while after a false "Doručena".
    private const DPD_DELIVERED_RECHECK_DAYS = 30;

    /**
     * Updates on-the-way parcels created in the last two months, plus any still waiting at a pickup
     * point (those otherwise never get stored_until / a real terminal status).
     *
     * @return void
     */
    public function __invoke(): void
    {
        Parcel::query()
            ->where('carrier', '!=', 'Allegro One') # Allegro One is updated in batch through separate call
            ->where(fn($q) => $q->where('carrier', '!=', 'DPD')->orWhereNotNull('parcelable_id'))
            ->where(function ($q) {
                $q->where(function ($onTheWay) {
                    # Also null: unmapped Packeta codes used to wipe status and drop parcels from tracking
                    $onTheWay->where(fn($status) => $status->whereIn('status', Parcel::ON_THE_WAY_STATUSES)->orWhereNull('status'))
                        ->where(function ($fresh) {
                            $fresh->where('created_at', '>', now()->subMonths(self::TRACKING_MONTHS))
                                ->orWhere('status', ParcelStoredUntil::PICKUP_STATUS);
                        });
                })->orWhere(function ($dpdDelivered) {
                    $dpdDelivered->where('carrier', 'DPD')
                        ->where('status', 'Doručena')
                        ->whereNotNull('parcelable_id')
                        ->where('created_at', '>', now()->subDays(self::DPD_DELIVERED_RECHECK_DAYS));
                });
            })
            ->inRandomOrder()
            ->limit(10000)
            ->get()
            ->each(function (Parcel $parcel): void {
                try {
                    $parcel->updateStatus();
                } catch (Throwable $e) {
                    Log::error('ParcelCheckup failed for parcel ' . $parcel->id . ': ' . $e->getMessage(), [
                        'parcel_id'       => $parcel->id,
                        'carrier'         => $parcel->carrier,
                        'tracking_number' => $parcel->tracking_number,
                    ]);
                }
            });
    }
}
