<?php

namespace App\Http\Controllers;

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
 * `gestire_esercizio`, le colonne delle note solo con `note`. Il prospetto è quello che la pagina mostra, `?proposte=1` compreso.
 * Esercizio aperto: tutto «provvisorio». I file hanno già il nome finale, perché il file firmato non si può rinominare.
 */
class EsportazioniController extends Controller
{
    /**
     * shortcut: finché l'esercizio non ha gli stati (#59) c'è una sola versione, la prima; con l'approvazione e la riapertura il numero verrà da lì.
     */
    private const VERSIONE = 1;

    public function modelloD(Request $request, int $anno, string $formato): Response
    {
        $proposte = $request->boolean('proposte');
        // Un esercizio storico (#63) non ha movimenti: il suo Modello D è ricalcolato dagli importi caricati dal foglio, come nella pagina.
        $proprio = EsercizioStorico::where('esercizio', $anno)->first();
        $m = $proprio?->prospetto() ?? Prospetto::modelloD($anno, $proposte);
        $sezioni = Tabelle::modelloD($m, EsercizioStorico::modelloD($anno - 1), $anno, $proprio !== null);

        return $this->consegna($request, $formato, 'ModelloD', 'Modello D', $anno, 'Modello D · Rendiconto per cassa '.$anno, $sezioni, $proprio !== null, $proposte, false);
    }

    /** Un esercizio storico non ha movimenti né rendiconto (come la pagina): 404. */
    public function rendiconto(Request $request, int $anno, string $formato): Response
    {
        abort_if(EsercizioStorico::where('esercizio', $anno)->exists(), 404);
        $proposte = $request->boolean('proposte');
        $sezioni = Tabelle::rendiconto(Prospetto::rendiconto($anno, $proposte), $request->user()->can('gestire_esercizio'));

        return $this->consegna($request, $formato, 'Rendiconto', 'Rendiconto', $anno, 'Rendiconto per l’assemblea · '.$anno, $sezioni, false, $proposte, true);
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
        $intestazione = Tabelle::intestazione(ImpostazioniAssociazione::per($anno)?->only(['denominazione', 'codice_fiscale', 'numero_runts']) ?? [], $titolo, $storico, $proposte, $ora);

        if ($formato === 'pdf') {
            return $this->scarica($request, Pdf::crea($titolo, $intestazione, $sezioni, Tabelle::piede($anno, $ora, self::VERSIONE, $storico), $largo), 'application/pdf', $this->nome($prefisso, $anno, 'pdf'));
        }

        // Il foglio «Dettaglio» per il commercialista: tutte le imputazioni dell'esercizio, le stesse del prospetto.
        $dettaglio = Elenchi::imputazioni($anno, $proposte, $request->user()->can('note'));
        $xlsx = Xlsx::crea([
            $foglio => Tabelle::comeRighe($sezioni, $intestazione),
            'Dettaglio' => [['celle' => $dettaglio['colonne'], 'grassetto' => true], ...array_map(fn (array $riga) => ['celle' => $riga], $dettaglio['righe'])],
        ]);

        return $this->scarica($request, $xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->nome($prefisso, $anno, 'xlsx'));
    }

    /** «Rendiconto_2026_ContradaLaTorre.pdf»: l'ente dalla denominazione delle impostazioni, ridotta a lettere e cifre. */
    private function nome(string $prefisso, int $anno, string $estensione): string
    {
        $ente = Str::of(ImpostazioniAssociazione::per($anno)?->denominazione ?? '')->ascii()->studly()->replaceMatches('/[^A-Za-z0-9]/', '')->toString();

        return $prefisso.'_'.$anno.'_'.($ente ?: 'Associazione').'.'.$estensione;
    }

    /** Ogni esportazione va nel registro degli accessi (glossario): «esportazione_modellod_pdf», «esportazione_imputazioni_csv». */
    private function scarica(Request $request, string $contenuto, string $tipo, string $nome): Response
    {
        RegistroAccesso::registra('esportazione_'.strtolower(strtok($nome, '_')).'_'.pathinfo($nome, PATHINFO_EXTENSION), $request, $request->user());

        return response($contenuto, 200, ['Content-Type' => $tipo, 'Content-Disposition' => 'attachment; filename="'.$nome.'"']);
    }
}
