<?php

namespace App\Http\Controllers;

use App\Esercizio\Approvazione;
use App\Esercizio\Deposito;
use App\Esercizio\Differenze;
use App\Esercizio\Elenchi;
use App\Esercizio\Pdf;
use App\Esercizio\Prospetto;
use App\Esercizio\Tabelle;
use App\Esercizio\Xlsx;
use App\Models\EsercizioStorico;
use App\Models\ImpostazioniAssociazione;
use App\Models\RegistroAccesso;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Le esportazioni dei prospetti (#58, #18): Modello D e rendiconto per l'assemblea in PDF e XLSX, gli elenchi di imputazioni e movimenti in CSV.
 * Le rotte sono dietro `can:vedere` come le pagine da cui nascono, e ne seguono i permessi: la nota della destinazione dell'avanzo solo con
 * `gestire_esercizio`, le colonne delle note solo con `note`. Il prospetto è quello che la pagina mostra, `?proposte=1` compreso; la vista di controllo `per=voce` del rendiconto è solo a schermo, il file è sempre per area → attività → iniziativa.
 * Esercizio aperto: tutto «provvisorio». I file hanno già il nome finale, perché il file firmato non si può rinominare.
 */
class EsportazioniController extends Controller
{
    public function modelloD(Request $request, int $anno, string $formato): Response
    {
        // Un esercizio depositato (#60) esce dall'istantanea, come la pagina: né proposte né dati di oggi.
        $salvata = Deposito::salvata($anno);
        $proposte = $salvata === null && $request->boolean('proposte');
        // Un esercizio storico (#63) non ha movimenti: il suo Modello D è ricalcolato dagli importi caricati dal foglio, come nella pagina.
        $proprio = EsercizioStorico::where('esercizio', $anno)->first();
        $m = $salvata ? $salvata['prospetti']['modello_d'] : ($proprio?->prospetto() ?? Prospetto::modelloD($anno, $proposte));
        $impedimenti = $proprio || $salvata ? [] : array_column(Prospetto::impedimenti($anno), 'testo');
        $sezioni = Tabelle::modelloD($m, $salvata ? $salvata['precedente'] : EsercizioStorico::modelloD($anno - 1), $anno, $proprio !== null, $impedimenti);

        return $this->consegna($request, $formato, 'ModelloD', 'Modello D', $anno, 'Modello D · Rendiconto per cassa '.$anno, $sezioni, $proprio !== null, $proposte, false);
    }

    /** Un esercizio storico non ha movimenti né rendiconto (come la pagina): 404. */
    public function rendiconto(Request $request, int $anno, string $formato): Response
    {
        abort_if(EsercizioStorico::where('esercizio', $anno)->exists(), 404);
        $salvata = Deposito::salvata($anno);
        $proposte = $salvata === null && $request->boolean('proposte');
        $sezioni = Tabelle::rendiconto($salvata ? $salvata['prospetti']['rendiconto'] : Prospetto::rendiconto($anno, $proposte), $request->user()->can('gestire_esercizio'));

        return $this->consegna($request, $formato, 'Rendiconto', 'Rendiconto', $anno, 'Rendiconto per l’assemblea · '.$anno, $sezioni, false, $proposte, true);
    }

    /**
     * Il PDF/A-1b di deposito (#60): del rendiconto approvato, e dopo il deposito della sua istantanea. Non è mai «provvisorio» e non dipende dai dati di oggi.
     * Nome `Deposito_…` e non `Rendiconto_…`: i due file non devono confondersi.
     */
    public function deposito(Request $request, int $anno): Response
    {
        $istantanea = Deposito::istantanea($anno);
        abort_if($istantanea === null, 404);

        return $this->scarica($request, Deposito::pdf($istantanea), 'application/pdf', $this->nome('Deposito', $anno, 'pdf', $istantanea['impostazioni']['denominazione'] ?? null));
    }

    public function elenco(Request $request, int $anno, string $tipo): Response
    {
        $conNote = $request->user()->can('note');
        $elenco = $tipo === 'imputazioni' ? Elenchi::imputazioni($anno, $request->boolean('proposte'), $conNote) : Elenchi::movimenti($anno, $conNote);

        return $this->scarica($request, Elenchi::csv($elenco), 'text/csv; charset=UTF-8', $this->nome(ucfirst($tipo), $anno, 'csv'));
    }

    /** @param  list<array>  $sezioni */
    private function consegna(Request $request, string $formato, string $prefisso, string $foglio, int $anno, string $titolo, array $sezioni, bool $storico, bool $proposte, bool $largo): Response
    {
        $ora = now();
        // Un esercizio approvato non è «provvisorio»: porta la sua versione e dice se i dati di oggi se ne sono allontanati (#59).
        $situazione = Approvazione::situazione($anno);
        // Con `?proposte=1` il prospetto non è quello approvato (ci sono anche le proposte): esce provvisorio.
        $approvata = $storico || $proposte ? null : $situazione['versione'];
        $approvazione = $approvata ? 'Approvato il '.$approvata->approvata_il->format('d/m/Y').' · versione '.$approvata->numero : null;
        // Depositato (#60): intestazione e nome del file dalle impostazioni dell'istantanea, non da quelle di oggi; niente differenze (non c'è da confrontare).
        $impostazioni = Deposito::salvata($anno)['impostazioni'] ?? ImpostazioniAssociazione::per($anno)?->only(['denominazione', 'codice_fiscale', 'numero_runts']) ?? [];
        $intestazione = Tabelle::intestazione($impostazioni, $titolo, $storico, $proposte, $ora, $approvazione, $approvata !== null && $situazione['differenze'] !== null && Differenze::presenti($situazione['differenze'], $prefisso === 'Rendiconto' ? ['rendiconto'] : ['voci', 'esiti']));
        if ($approvata?->depositata_il) {
            $intestazione[3] = 'Depositato il '.$approvata->depositata_il->format('d/m/Y').' · versione '.$approvata->numero.' · istantanea del deposito.';
        }

        if ($formato === 'pdf') {
            return $this->scarica($request, Pdf::crea($titolo, $intestazione, $sezioni, Tabelle::piede($anno, $ora, $situazione['numero'], $storico || $approvata !== null), $largo), 'application/pdf', $this->nome($prefisso, $anno, 'pdf', $impostazioni['denominazione'] ?? null));
        }

        $fogli = [$foglio => Tabelle::comeRighe($sezioni, $intestazione)];
        // Il foglio «Dettaglio» (le imputazioni con le descrizioni dei movimenti) è per il commercialista, solo nel Modello D: il rendiconto per l'assemblea non ha mai nomi di persone (spec #27).
        if ($prefisso === 'ModelloD') {
            $dettaglio = Elenchi::imputazioni($anno, $proposte, $request->user()->can('note'));
            $fogli['Dettaglio'] = [['celle' => $dettaglio['colonne'], 'grassetto' => true], ...array_map(fn (array $riga) => ['celle' => $riga], $dettaglio['righe'])];
        }
        $xlsx = Xlsx::crea($fogli);

        return $this->scarica($request, $xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->nome($prefisso, $anno, 'xlsx', $impostazioni['denominazione'] ?? null));
    }

    /** «Rendiconto_2026_ContradaLaTorre.pdf»: l'ente dalla denominazione delle impostazioni, ridotta a lettere e cifre. */
    private function nome(string $prefisso, int $anno, string $estensione, ?string $denominazione = null): string
    {
        $ente = Str::of($denominazione ?? ImpostazioniAssociazione::per($anno)?->denominazione ?? '')->ascii()->studly()->replaceMatches('/[^A-Za-z0-9]/', '')->toString();

        return $prefisso.'_'.$anno.'_'.($ente ?: 'Associazione').'.'.$estensione;
    }

    /** Ogni esportazione va nel registro degli accessi (glossario): «esportazione_modellod_pdf_2026», «esportazione_imputazioni_csv_2026» (l'anno dal nome del file). */
    private function scarica(Request $request, string $contenuto, string $tipo, string $nome): Response
    {
        $parti = explode('_', pathinfo($nome, PATHINFO_FILENAME));
        RegistroAccesso::registra('esportazione_'.strtolower($parti[0]).'_'.pathinfo($nome, PATHINFO_EXTENSION).'_'.$parti[1], $request, $request->user());

        return response($contenuto, 200, ['Content-Type' => $tipo, 'Content-Disposition' => 'attachment; filename="'.$nome.'"']);
    }
}
