<?php

namespace Ondrejsanetrnik\Parcelable;

use Illuminate\Support\Facades\Log;
use Throwable;

class ParcelCheckup
{
    # DPD code 13 can mean delivered to us on a return. Keep polling a while after a false "Doručena".
    private const DPD_DELIVERED_RECHECK_DAYS = 21;

    /**
     * Updates on-the-way parcels from the last month, plus any still waiting at a pickup
     * point (those drop out of the month window and otherwise never get stored_until / a real terminal status).
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
                            $fresh->where('updated_at', '>', now()->subMonth())
                                ->orWhere('status', ParcelStoredUntil::PICKUP_STATUS);
                        });
                })->orWhere(function ($dpdDelivered) {
                    $dpdDelivered->where('carrier', 'DPD')
                        ->where('status', 'Doručena')
                        ->whereNotNull('parcelable_id')
                        ->where('updated_at', '>', now()->subDays(self::DPD_DELIVERED_RECHECK_DAYS));
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
