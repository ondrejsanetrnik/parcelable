<?php

namespace Ondrejsanetrnik\Parcelable\calls;

use App\Helpers\Api;
use Illuminate\Support\Facades\Log;
use Ondrejsanetrnik\Parcelable\AllegroOne;
use Ondrejsanetrnik\Parcelable\Parcel;

class UpdateBaselinkerParcelStatuses
{
    /**
     * Gets all Allegro parcels on the way and updates the statuses in bulk through the Baselinker API
     *
     * @return void
     */
    public function __invoke(): void
    {
        Parcel::query()
            ->whereIn('carrier', ['Allegro One', 'DPD'])
            ->whereIn('status', Parcel::ON_THE_WAY_STATUSES)
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
                            $status = AllegroOne::STATUSES[array_pop($parcelHistory)['tracking_status']] ?? 'V přepravě';
                            $parcel->update([
                                'status'     => $status,
                                'updated_at' => now(),
                            ]);
                        }
                    }
                } else {
                    Log::warning('Chyba při aktualizaci stavů zásilek z Baselinkeru');
                }
            });
    }
}
