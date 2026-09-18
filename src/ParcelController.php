<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Throwable;

class ParcelController extends Controller
{
    /**
     * Disassociates the label from the current order
     *
     * @param int $id
     * @return RedirectResponse
     */
    public function remove(int $id): RedirectResponse
    {
        Parcel::findOrFail($id)->update([
            'parcelable_id'   => null,
            'parcelable_type' => null,
        ]);

        return redirect()->back()->with('success', 'Balíček byl odejmut. Je možné ho najít mezi <a href="' . route('parcelsIndex', [], false) . '">nepřipárovanými balíčky</a>');
    }

    /**
     * Downloads the parcel label
     *
     * @param int $id
     * @return RedirectResponse | BinaryFileResponse
     */
    public function label(int $id): RedirectResponse|BinaryFileResponse
    {
        $parcel = Parcel::findOrFail($id);

        if ($parcel->status === 'Vrácena obchodu') {
            return redirect()->back()->with('error', 'Štítek zásilky ve stavu Vrácena obchodu nelze tisknout. Podej nový balík.');
        }

        $labelKey = 'labels/' . $parcel->label_name_pdf;

        if (!Storage::disk('private')->exists($labelKey)) {
            $this->refetchMissingLabel($parcel);
        }

        if (!Storage::disk('private')->exists($labelKey) || !is_file($parcel->label_path)) {
            $this->logMissingLabel($parcel, $labelKey, 'missing_file');

            return $this->labelMissingRedirect($parcel);
        }

        try {
            return response()->download($parcel->label_path, $parcel->label_name_pdf, [], 'inline');
        } catch (FileNotFoundException $e) {
            $this->logMissingLabel($parcel, $labelKey, 'race', $e);

            return $this->labelMissingRedirect($parcel);
        }
    }

    private function refetchMissingLabel(Parcel $parcel): void
    {
        if ($parcel->carrier !== 'Zásilkovna') {
            Log::warning('Parcel label missing and carrier cannot refetch', [
                'parcel_id'       => $parcel->id,
                'carrier'         => $parcel->carrier,
                'tracking_number' => $parcel->tracking_number,
                'reason'          => 'unsupported_carrier',
            ]);

            return;
        }

        try {
            Packeta::getLabel((int)$parcel->tracking_number, $parcel->parcelable?->carrier_id_inferred);
        } catch (Throwable $e) {
            Log::warning('Parcel label Packeta refetch failed', [
                'parcel_id'       => $parcel->id,
                'tracking_number' => $parcel->tracking_number,
                'reason'          => 'carrier_fail',
                'error'           => $e->getMessage(),
            ]);
        }
    }

    /**
     * Do not redirect()->back() — after /send the referer is the submit URL and a retry would create another parcel.
     */
    private function labelMissingRedirect(Parcel $parcel): RedirectResponse
    {
        $fallbackUrl = $parcel->parcelable?->pack_url;
        if (!$fallbackUrl) {
            $previous = url()->previous();
            $fallbackUrl = ($previous !== url()->current())
                ? $previous
                : route('parcelsIndex');
        }

        return redirect()->to($fallbackUrl)->with(
            'error',
            'Štítek zásilky se nepodařilo stáhnout. Zkuste tisk znovu za chvíli, balík u dopravce už existuje.',
        );
    }

    private function logMissingLabel(
        Parcel $parcel,
        string $labelKey,
        string $reason,
        ?Throwable $e = null,
    ): void {
        Log::warning('Parcel label file missing', [
            'parcel_id'       => $parcel->id,
            'carrier'         => $parcel->carrier,
            'tracking_number' => $parcel->tracking_number,
            'label_key'       => $labelKey,
            'path'            => $parcel->label_path,
            'reason'          => $reason,
            'error'           => $e?->getMessage(),
        ]);
    }

    /**
     * Shows an index of unpaired parcels
     *
     * @return View
     */
    public function index(): View
    {
        return view('parcelable::index', [
            'parcels' => Parcel::orderByDesc('id')->whereNull('parcelable_id')->paginate(50),
        ]);
    }
}
