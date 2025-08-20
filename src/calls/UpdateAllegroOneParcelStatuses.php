<?php

namespace Ondrejsanetrnik\Parcelable\calls;

use App\Helpers\Api;
use Illuminate\Support\Facades\Log;
use Ondrejsanetrnik\Parcelable\AllegroOne;
use Ondrejsanetrnik\Parcelable\Parcel;

class UpdateAllegroOneParcelStatuses
{
    /**
     * Gets all Allegro parcels on the way and updates the statuses in bulk through the Baselinker API
     *
     * @return void
     */
    public function __invoke(): void
    {
        Parcel::query()
            ->whereCarrier('Allegro One')
            ->whereIn('status', Parcel::ON_THE_WAY_STATUSES)
            ->chunk(100, function ($parcels) {
                sleep(3);

                $response = Api::baselinker()->courierShipments()->getCourierPackagesStatusHistory($parcels->pluck('external_id')->toArray());

                if ($response->getParameter('status') === 'SUCCESS') {
                    $statuses = $response->getParameter('packages_history');
                    foreach ($parcels as $parcel) {
                        $statuses = $statuses[$parcel->external_id] ?? null;
                        if ($statuses) {
                            $status = AllegroOne::STATUSES[array_pop($statuses)['tracking_status']] ?? 'V přepravě';
                            $parcel->update([
                                'status'     => $status,
                                'updated_at' => now(),
                            ]);
                        }
                    }
                } else {
                    Log::warning('Chyba při aktualizaci stavů zásilek Allegro One');
                }
            });
    }
}
