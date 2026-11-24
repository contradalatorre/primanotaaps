<?php

namespace App\Esercizio;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Un prospetto in PDF con mPDF (#58, #18): intestazione dell'associazione e dicitura «provvisorio» in alto a ogni pagina, piè di pagina con la data
 * di generazione, la versione dell'esercizio e il numero di pagina. «Provvisorio» sta nell'intestazione e non in filigrana: il PDF/A non ammette trasparenze.
 *
 * `crea` fa il PDF provvisorio, che non è PDF/A: così non lo si deposita per sbaglio. `deposito` fa quello da depositare al RUNTS (#60): PDF/A-1b, con l'indice in prima pagina.
 */
final class Pdf
{
    /**
     * @param  list<string>  $intestazione  la prima riga è la denominazione, in grassetto
     * @param  list<array>  $sezioni  di `Tabelle`
     * @param  ?list<array{titolo: string, sezioni: list<array>}>  $documenti  il PDF di deposito: un documento dopo l'altro, con l'indice in prima pagina; al posto di `$sezioni`
     * @return array{intestazione: string, corpo: string, piede: string} l'HTML delle tre parti: è ciò che mPDF stampa
     */
    public static function html(string $titolo, array $intestazione, array $sezioni, string $piede, ?array $documenti = null): array
    {
        return [
            'intestazione' => '<div style="font-family: dejavusans; font-size: 9pt; border-bottom: 0.3mm solid #111;">'
                .implode('', array_map(fn (string $riga, int $i) => '<div'.($i === 0 ? ' style="font-weight: bold; font-size: 11pt;"' : '').'>'.e($riga).'</div>', $intestazione, array_keys($intestazione))).'</div>',
            'corpo' => view('esportazione.pdf', compact('titolo', 'sezioni', 'documenti'))->render(),
            // {PAGENO} e {nbpg} li sostituisce mPDF.
            'piede' => '<div style="font-family: dejavusans; font-size: 8pt; border-top: 0.3mm solid #111; text-align: center;">'.e($piede).' · Pagina {PAGENO} di {nbpg}</div>',
        ];
    }

    /** @param  bool  $largo  pagina in orizzontale: il rendiconto ha una colonna per conto */
    public static function crea(string $titolo, array $intestazione, array $sezioni, string $piede, bool $largo = false): string
    {
        return self::scrivi($titolo, self::html($titolo, $intestazione, $sezioni, $piede), $largo, false);
    }

    /** Il PDF/A-1b da depositare: Modello D, e in seguito gli allegati, uno dopo l'altro; l'indice in prima pagina. */
    public static function deposito(string $titolo, array $intestazione, array $documenti, string $piede): string
    {
        return self::scrivi($titolo, self::html($titolo, $intestazione, [], $piede, $documenti), false, true);
    }

    private static function scrivi(string $titolo, array $html, bool $largo, bool $pdfa): string
    {
        // mPDF scrive i file temporanei in storage/: ADR 0001, nessuna cartella scrivibile fuori dallo spazio dell'app.
        $temporanea = storage_path('app/mpdf');
        File::ensureDirectoryExists($temporanea);
        // Senza `PDFAauto`: se qualcosa non è conforme (trasparenze, font non incorporati) mPDF si ferma e non produce un PDF che sembra PDF/A e non lo è.
        $pdf = new Mpdf(['mode' => 'utf-8', 'tempDir' => $temporanea, 'orientation' => $largo ? 'L' : 'P', 'margin_top' => 34, 'margin_bottom' => 18, 'margin_header' => 8, 'margin_footer' => 8, 'PDFA' => $pdfa]);
        $pdf->SetTitle($titolo);
        $pdf->SetHTMLHeader($html['intestazione']);
        $pdf->SetHTMLFooter($html['piede']);
        $pdf->WriteHTML($html['corpo']);

        $contenuto = $pdf->Output('', Destination::STRING_RETURN);

        return $pdfa ? PdfA::conCidSet($contenuto) : $contenuto;
    }
}
