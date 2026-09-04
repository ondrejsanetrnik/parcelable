<?php

namespace Ondrejsanetrnik\Parcelable;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

        if (!Storage::disk('private')->exists($labelKey)) {
            return $this->labelMissingRedirect($parcel);
        }

        return response()->download($parcel->label_path, $parcel->label_name_pdf, [], 'inline');
    }

    private function refetchMissingLabel(Parcel $parcel): void
    {
        if ($parcel->carrier !== 'Zásilkovna') {
            return;
        }

        Packeta::getLabel((int)$parcel->tracking_number, $parcel->parcelable?->carrier_id_inferred);
    }

    /**
     * Do not redirect()->back() — after /send the referer is the submit URL and a retry would create another parcel.
     */
    private function labelMissingRedirect(Parcel $parcel): RedirectResponse
    {
        $fallbackUrl = $parcel->parcelable?->pack_url ?: url('/');

        return redirect()->to($fallbackUrl)->with(
            'error',
            'Štítek zásilky se nepodařilo stáhnout. Zkuste tisk znovu za chvíli, balík u dopravce už existuje.'
        );
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
