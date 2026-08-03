<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Enums\EventName;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ParcelObserver
{
    private const MAX_RETURNING_EMAIL_PARCEL_AGE_DAYS = 60;

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
                $identifier = $parcel->parcelable?->model_name_identifier ?? '?';
                Log::warning('Parcelable failed to fire parcelChange for ' . $identifier . ' : ' . $e->getMessage() . ' ' . $e->getTraceAsString());

                $country = $parcel->parcelable?->country ?? null;
                if ($country !== 'CZ') {
                    try {
                        User::find(1)?->sendSlackMessage(
                            "⚠️ Parcelable parcelChange selhalo: {$identifier}"
                                . ($country !== null ? " (země: {$country})" : '')
                                . "\n" . $e->getMessage()
                        );
                    } catch (\Throwable $slackException) {
                        Log::warning('Failed to send parcelChange Slack notification', [
                            'error' => $slackException->getMessage(),
                        ]);
                    }
                }
            }
        }

        # Also on "Vrácena obchodu" — some carriers skip "Na cestě zpátky"
        if (
            $statusChanged
            && in_array($parcel->status, ['Na cestě zpátky', 'Vrácena obchodu'], true)
            && config('parcelable.send_returning_email', false)
        ) {
            $this->sendParcelReturningEmail($parcel);
        }
    }

    protected function sendParcelReturningEmail(Parcel $parcel): void
    {
        $order = $parcel->parcelable;

        if (!$order || !method_exists($order, 'events') || !method_exists($order, 'mailSelf') || !method_exists($order, 'parcels')) {
            return;
        }

        # Cancelled orders must never get a return decision email
        if (($order->state ?? null) === 'Stornováno') {
            return;
        }

        # Stale historical parcels (e.g. revived by a full checkup scan) must not email customers
        if ($parcel->created_at?->lt(now()->subDays(self::MAX_RETURNING_EMAIL_PARCEL_AGE_DAYS))) {
            return;
        }

        # Ignore status updates on older parcels after a resend was created
        $latestParcelId = $order->parcels()->orderByDesc('id')->value('id');
        if ($latestParcelId && (int)$latestParcelId !== (int)$parcel->id) {
            return;
        }

        # One email per return cycle (per parcel), not once for the whole order lifetime
        $alreadySentForThisParcel = $order->events()
            ->where('title', EventName::ZasilkaSeVraciEmailOdeslan->value)
            ->where('created_at', '>=', $parcel->created_at)
            ->exists();

        if ($alreadySentForThisParcel) {
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
