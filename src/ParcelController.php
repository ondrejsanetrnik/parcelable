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

        if (!Storage::disk('private')->exists('labels/' . $parcel->label_name_pdf)) {
            if ($parcel->carrier == 'Zásilkovna')
                Packeta::getLabel($parcel->tracking_number, $parcel->parcelable?->carrier_id_inferred);
            //            elseif ($parcel->carrier == 'GLS')
            //                Gls::printLabels(Gls::generateJson($par));
            else {
                return redirect()->back()->with('error', 'Soubor nenalezen');
            }
        }

        return response()->download($parcel->label_path, $parcel->label_name_pdf, [], 'inline');
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
