<?php

namespace App\Esercizio;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Un prospetto in PDF con mPDF (#58, #18): intestazione dell'associazione e dicitura «provvisorio» in alto a ogni pagina, piè di pagina con la data
 * di generazione, la versione dell'esercizio e il numero di pagina. «Provvisorio» sta nell'intestazione e non in filigrana: il PDF/A non ammette trasparenze.
 *
 * shortcut: questo è il PDF provvisorio, e quindi non è PDF/A; quello di deposito (PDF/A-1b, #60) si farà qui con un parametro in più; gli stati dell'esercizio (#59) ci sono, il deposito no. L'esercizio approvato porta già la sua versione al posto di «provvisorio», ma il PDF resta lo stesso.
 */
final class Pdf
{
    /**
     * @param  list<string>  $intestazione  la prima riga è la denominazione, in grassetto
     * @param  list<array>  $sezioni  di `Tabelle`
     * @return array{intestazione: string, corpo: string, piede: string} l'HTML delle tre parti: è ciò che mPDF stampa
     */
    public static function html(string $titolo, array $intestazione, array $sezioni, string $piede): array
    {
        return [
            'intestazione' => '<div style="font-family: dejavusans; font-size: 9pt; border-bottom: 0.3mm solid #111;">'
                .implode('', array_map(fn (string $riga, int $i) => '<div'.($i === 0 ? ' style="font-weight: bold; font-size: 11pt;"' : '').'>'.e($riga).'</div>', $intestazione, array_keys($intestazione))).'</div>',
            'corpo' => view('esportazione.pdf', compact('titolo', 'sezioni'))->render(),
            // {PAGENO} e {nbpg} li sostituisce mPDF.
            'piede' => '<div style="font-family: dejavusans; font-size: 8pt; border-top: 0.3mm solid #111; text-align: center;">'.e($piede).' · Pagina {PAGENO} di {nbpg}</div>',
        ];
    }

    /** @param  bool  $largo  pagina in orizzontale: il rendiconto ha una colonna per conto */
    public static function crea(string $titolo, array $intestazione, array $sezioni, string $piede, bool $largo = false): string
    {
        $html = self::html($titolo, $intestazione, $sezioni, $piede);

        // mPDF scrive i file temporanei in storage/: ADR 0001, nessuna cartella scrivibile fuori dallo spazio dell'app.
        $temporanea = storage_path('app/mpdf');
        File::ensureDirectoryExists($temporanea);
        $pdf = new Mpdf(['mode' => 'utf-8', 'tempDir' => $temporanea, 'orientation' => $largo ? 'L' : 'P', 'margin_top' => 34, 'margin_bottom' => 18, 'margin_header' => 8, 'margin_footer' => 8]);
        $pdf->SetTitle($titolo);
        $pdf->SetHTMLHeader($html['intestazione']);
        $pdf->SetHTMLFooter($html['piede']);
        $pdf->WriteHTML($html['corpo']);

        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
