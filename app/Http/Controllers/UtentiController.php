<?php

namespace App\Http\Controllers;

use App\Models\Assegnazione;
use App\Models\Ruolo;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/** Utenti, assegnazioni e account esterni; le rotte stanno dietro `can:gestire_utenti`. */
class UtentiController extends Controller
{
    public function index()
    {
        return view('utenti', [
            'utenti' => User::with('assegnazioni.ruolo')->orderBy('esterno')->orderBy('name')->get(),
            'ruoli' => Ruolo::orderBy('nome')->get(),
        ]);
    }

    public function assegna(Request $request, User $utente): RedirectResponse
    {
        $dati = $request->validate([
            'ruolo_id' => ['required', 'exists:ruoli,id'],
            // La scadenza è facoltativa per chi è dell'associazione, obbligatoria per un esterno.
            'scade_il' => [$utente->esterno ? 'required' : 'nullable', 'date', 'after_or_equal:today'],
            'nomina_firmata_il' => ['nullable', 'date'],
        ]);

        $utente->assegnazioni()->create([
            'ruolo_id' => $dati['ruolo_id'],
            'scade_il' => $dati['scade_il'] ?? null,
            'nomina_firmata_il' => $utente->esterno ? ($dati['nomina_firmata_il'] ?? null) : null,
        ]);

        return $this->fatto('Assegnazione aggiunta.');
    }

    public function revoca(Assegnazione $assegnazione): RedirectResponse
    {
        User::proteggendoUltimoAmministratore(fn () => $assegnazione->delete());

        return $this->fatto('Assegnazione revocata.');
    }

    /** Un esterno (commercialista, revisore) si aggiunge uno per uno, con scadenza obbligatoria: vedi #16 e #27. */
    public function aggiungiEsterno(Request $request): RedirectResponse
    {
        $dominio = strtolower((string) config('services.google.workspace_domain'));
        $dati = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', fn ($campo, $valore, $fallisci) => str_ends_with(strtolower($valore), '@'.$dominio)
                && $fallisci('Un indirizzo del Workspace non è un esterno: chi è dell’associazione entra con il suo account.')],
            'ruolo_id' => ['required', 'exists:ruoli,id'],
            'scade_il' => ['required', 'date', 'after_or_equal:today'],
            'nomina_firmata_il' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($dati) {
            $esterno = new User(['name' => $dati['name'], 'email' => strtolower($dati['email'])]);
            $esterno->esterno = true;
            $esterno->save();
            $esterno->assegnazioni()->create(Arr::only($dati, ['ruolo_id', 'scade_il', 'nomina_firmata_il']));
        });

        return $this->fatto('Esterno aggiunto: potrà entrare con il suo account Google fino alla scadenza.');
    }

    /** L'elenco degli autorizzati al trattamento (#27): le assegnazioni in corso, datate. */
    public function elencoPdf(): Response
    {
        $data = now();
        $html = view('autorizzati', [
            'assegnazioni' => Assegnazione::attive()->with('user', 'ruolo')->get()->sortBy(fn ($a) => mb_strtolower($a->user->name)),
            'data' => $data,
        ])->render();

        // mPDF scrive i file temporanei in storage/: ADR 0001, nessuna cartella scrivibile fuori dallo spazio dell'app.
        $temporanea = storage_path('app/mpdf');
        File::ensureDirectoryExists($temporanea);
        $pdf = new Mpdf(['mode' => 'utf-8', 'tempDir' => $temporanea]);
        $pdf->SetTitle('Elenco degli autorizzati');
        $pdf->WriteHTML($html);

        return response($pdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="elenco-autorizzati-'.$data->toDateString().'.pdf"',
        ]);
    }

    private function fatto(string $messaggio): RedirectResponse
    {
        return redirect()->route('utenti')->with('ok', $messaggio);
    }
}
