<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Enums\EventName;
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
        $statusChanged = array_key_exists('status', $parcel->getChanges());

        if ($statusChanged || array_key_exists('parcelable_id', $parcel->getChanges())) {
            try {
                $parcel->parcelable?->fire('updated', 'parcelChange');
            } catch (\Throwable $e) {
                Log::warning('Parcelable failed to fire parcelChange for ' . $parcel->parcelable->model_name_identifier . ' : ' . $e->getMessage() . ' ' . $e->getTraceAsString());
            }
        }

        if ($statusChanged && $parcel->status === 'Na cestě zpátky' && config('parcelable.send_returning_email', false)) {
            $this->sendParcelReturningEmail($parcel);
        }
    }

    protected function sendParcelReturningEmail(Parcel $parcel): void
    {
        $order = $parcel->parcelable;

        if (!$order || !method_exists($order, 'hasEvents')) {
            return;
        }

        if ($order->hasEvents([EventName::ZasilkaSeVraciEmailOdeslan])) {
            return;
        }

        try {
            $order->createEvent(EventName::ZasilkaSeVraciEmailOdeslan);
            $order->mailSelf('ParcelReturning');
        } catch (\Throwable $e) {
            Log::warning('Failed to send ParcelReturning email for order ' . $order->id . ': ' . $e->getMessage());
        }
    }
}
