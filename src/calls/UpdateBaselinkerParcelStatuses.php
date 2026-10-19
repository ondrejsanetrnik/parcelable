<?php

namespace Ondrejsanetrnik\Parcelable\calls;

use App\Helpers\Api;
use Illuminate\Support\Facades\Log;
use Ondrejsanetrnik\Parcelable\AllegroOne;
use Ondrejsanetrnik\Parcelable\Parcel;
use Ondrejsanetrnik\Parcelable\ParcelStoredUntil;

class UpdateBaselinkerParcelStatuses
{
    /**
     * Bulk-updates on-the-way Allegro One and Allegro-marketplace DPD parcels via Baselinker.
     *
     * @return void
     */
    public function __invoke(): void
    {
        # Allegro One and Allegro-marketplace DPD (DpdAllegro) store a Baselinker package id
        # in external_id. Own-contract CZ/Alza DPD have none and are tracked via GeoAPI.
        Parcel::query()
            ->whereIn('carrier', ['Allegro One', 'DPD'])
            ->whereIn('status', Parcel::ON_THE_WAY_STATUSES)
            ->where('external_id', '>', 0)
            ->chunk(100, function ($parcels) {
                sleep(3);
                $packageIds = $parcels
                    ->pluck('external_id')
                    ->map(static fn($externalId) => is_numeric($externalId) ? (int)$externalId : null)
                    ->filter(static fn($packageId) => $packageId !== null && $packageId > 0)
                    ->values()
                    ->all();

                if ($packageIds === []) {
                    return;
                }

                $response = Api::baselinker()->courierShipments()->getCourierPackagesStatusHistory($packageIds);

                if ($response->getParameter('status') === 'SUCCESS') {
                    $packagesHistory = $response->getParameter('packages_history');
                    foreach ($parcels as $parcel) {
                        $parcelHistory = $packagesHistory[$parcel->external_id] ?? null;
                        if ($parcelHistory) {
                            $history = is_array($parcelHistory) ? $parcelHistory : [];
                            $last = end($history) ?: [];
                            # Baselinker tracking_status is courier-agnostic (Allegro One and DPD).
                            $status = AllegroOne::STATUSES[$last['tracking_status'] ?? null] ?? 'V přepravě';
                            $attributes = [
                                'status'     => $status,
                                'updated_at' => now(),
                            ];

                            if ($status === ParcelStoredUntil::PICKUP_STATUS) {
                                $storedUntil = ParcelStoredUntil::fromBaselinkerHistory($history);
                                if ($storedUntil !== null) {
                                    $attributes['stored_until'] = $storedUntil;
                                }
                            }

                            $parcel->update($attributes);
                        }
                    }
                } else {
                    Log::warning('Chyba při aktualizaci stavů zásilek z Baselinkeru');
                }
            });
    }
}
