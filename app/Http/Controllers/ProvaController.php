<?php

namespace App\Http\Controllers;

use App\Models\Avanzamento;
use App\Models\Conto;
use App\Onboarding\Catalogo;
use App\Onboarding\Missione;
use App\Onboarding\Scenario;
use App\Onboarding\Schermate;
use App\Onboarding\Svolgimento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/** Le azioni della guida di Merlo dentro la prova (dietro `ProvaMiddleware`: lavorano sullo spazio di prova, l'avanzamento è nel database vero). */
class ProvaController extends Controller
{
    public function __construct(private readonly Svolgimento $svolgimento) {}

    public function avanti(Request $request): RedirectResponse
    {
        [$missione, $avanzamento] = $this->corrente($request);

        if ($this->svolgimento->avanti($missione, $avanzamento)) {
            $request->session()->forget('prova.missione');

            return redirect()->route('impara.timbri', ['nuovo' => $missione->id]);
        }

        return redirect(Schermate::url($missione->passi[$avanzamento->passo]->schermata) ?? route('home'));
    }

    public function salta(Request $request): RedirectResponse
    {
        [, $avanzamento] = $this->corrente($request);
        $this->svolgimento->salta($avanzamento);
        $request->session()->forget('prova.missione');

        return redirect()->route('impara')->with('ok', 'Missione saltata: niente timbro, e resta suggerita. La rifai quando vuoi.');
    }

    /** «Esci dalla prova» (e «Interrompi»): la missione resta dove l'hai lasciata, da Impara si riprende. */
    public function esci(Request $request): RedirectResponse
    {
        $request->session()->forget('prova.missione');

        return redirect()->route('impara')->with('ok', 'Sei fuori dalla prova. Da qui riprendi la missione dal punto in cui eri.');
    }

    /**
     * L'estratto di prova dello scenario passa dal normale caricamento di un estratto, come se l'utente
     * l'avesse scaricato dalla banca: stessi controlli e stessa anteprima (#40).
     */
    public function estratto(Request $request, Conto $conto, ImportEstrattiController $import): RedirectResponse
    {
        [$missione] = $this->corrente($request);
        $file = Scenario::estratto($missione->scenario) ?? abort(404);
        $request->files->set('estratto', new UploadedFile($file, 'estratto-di-prova.csv', 'text/csv', null, true));

        return $import->carica($request, $conto);
    }

    /** @return array{Missione, Avanzamento} la missione in corso in questa sessione; fuori dalla prova non c'è nulla da fare */
    private function corrente(Request $request): array
    {
        $missione = ($id = $request->session()->get('prova.missione')) ? Catalogo::predefinito()->missione($id) : null;
        $avanzamento = $missione ? Avanzamento::where(['user_id' => $request->user()->id, 'missione' => $missione->id, 'stato' => 'in_corso'])->first() : null;
        abort_unless($avanzamento, 404);

        return [$missione, $avanzamento];
    }
}
