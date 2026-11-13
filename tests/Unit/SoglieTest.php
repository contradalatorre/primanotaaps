<?php

namespace Tests\Unit;

use App\Esercizio\Soglie;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le soglie degli artt. 13, 30 e 31 CTS e il Modello E (#55) sul modulo puro, a tabella: importi inventati, in centesimi.
 * Ogni riga dà le misure dei due esercizi che precedono quello guardato (`$misure`: anno → entrate, attivo, dipendenti medi, o null se
 * l'esercizio non ha dati nell'app) e ciò che il caso vuole fissare.
 */
class SoglieTest extends TestCase
{
    /** L'esercizio misurato: entrate in euro interi, attivo (euro, o null se non dichiarato), dipendenti medi (o null). */
    private static function m(int $entrate, ?int $attivo = 0, ?int $dipendenti = 0): array
    {
        return ['entrate' => $entrate * 100, 'attivo' => $attivo === null ? null : $attivo * 100, 'dipendenti' => $dipendenti];
    }

    /** @return array<string, array{0: int, 1: array<int, ?array>, 2: array<string, mixed>}> anno, misure, atteso */
    public static function casi(): array
    {
        $ok = self::m(100_000, 20_000, 2);
        $oltre = self::m(100_000, 200_000, 9); // attivo e dipendenti sopra i limiti dell'art. 30

        return [
            // --- art. 13 e Modello E ---
            'esercizio precedente senza dati: niente esito, niente Modello E, niente cassa' => [2026, [2025 => null, 2024 => null], [
                'art13.esito' => null, 'art13.senzaDati' => true, 'art13.entrate' => null, 'art13.modelloE' => false, 'art13.cassa' => false, 'art30.esito' => null, 'art31.esito' => null,
            ]],
            'entrate di 5.000 euro: rispettato, si può redigere il Modello E' => [2026, [2025 => self::m(5_000), 2024 => null], [
                'art13.esito' => 'rispettato', 'art13.modelloE' => true, 'art13.cassa' => true, 'art13.entrate' => 500_000, 'art13.margine' => 29_500_000, 'riferimento' => 2025,
            ]],
            'esattamente 60.000 euro: Modello E ammesso (non superiori)' => [2026, [2025 => self::m(60_000), 2024 => null], ['art13.modelloE' => true]],
            '60.000 euro e un centesimo: niente Modello E' => [2026, [2025 => ['entrate' => 6_000_001, 'attivo' => 0, 'dipendenti' => 0], 2024 => null], ['art13.modelloE' => false, 'art13.cassa' => true]],
            'il Modello E vale dal rendiconto 2026: per il 2025 non si annuncia' => [2025, [2024 => self::m(5_000), 2023 => null], ['art13.modelloE' => false, 'art13.cassa' => true]],
            'esattamente 300.000 euro: cassa ammessa, ma vicino alla soglia' => [2026, [2025 => self::m(300_000), 2024 => null], ['art13.esito' => 'vicino', 'art13.cassa' => true, 'art13.margine' => 0]],
            '300.000 euro e un centesimo: soglia superata, non rispettato' => [2026, [2025 => ['entrate' => 30_000_001, 'attivo' => 0, 'dipendenti' => 0], 2024 => null], ['art13.esito' => 'non_rispettato', 'art13.cassa' => false, 'art13.margine' => -1]],

            'entrate in attesa di conferma nell’esercizio precedente: al massimo vicino, mai rispettato' => [2026, [2025 => self::m(5_000) + ['inAttesa' => 1], 2024 => null], ['art13.esito' => 'vicino', 'art13.inAttesa' => 1, 'art13.modelloE' => true]],
            'entrate in attesa e soglia già superata: resta non rispettato' => [2026, [2025 => ['entrate' => 30_000_001, 'attivo' => 0, 'dipendenti' => 0, 'inAttesa' => 2], 2024 => null], ['art13.esito' => 'non_rispettato']],
            'nessuna entrata in attesa: rispettato' => [2026, [2025 => self::m(5_000) + ['inAttesa' => 0], 2024 => null], ['art13.esito' => 'rispettato', 'art13.inAttesa' => 0]],

            // --- artt. 30 e 31: due limiti su tre, per due esercizi consecutivi ---
            'sotto tutti i limiti: rispettato' => [2026, [2025 => $ok, 2024 => $ok], ['art30.esito' => 'rispettato', 'art30.superati' => 0, 'art30.mancanti' => 0, 'art31.esito' => 'rispettato', 'art30.consecutivi' => false]],
            'due limiti superati in due esercizi consecutivi: obbligo dell’organo di controllo, non della revisione' => [2026, [2025 => $oltre, 2024 => $oltre], [
                'art30.esito' => 'non_rispettato', 'art30.superati' => 2, 'art30.consecutivi' => true, 'art31.esito' => 'rispettato', 'art31.superati' => 0,
            ]],
            'due limiti superati solo nell’ultimo esercizio: vicino, il secondo consecutivo farebbe nascere l’obbligo' => [2026, [2025 => $oltre, 2024 => $ok], ['art30.esito' => 'vicino', 'art30.superati' => 2, 'art30.consecutivi' => false]],
            'due limiti superati e l’esercizio prima non è nell’app: per prudenza non rispettato, consecutivi ignoto' => [2026, [2025 => $oltre, 2024 => null], ['art30.esito' => 'non_rispettato', 'art30.consecutivi' => null]],
            'sotto i limiti da un solo esercizio, dopo due oltre: l’obbligo non è ancora cessato, vicino' => [2026, [2025 => $ok, 2024 => $oltre], [
                'art30.superati' => 0, 'art30.esito' => 'vicino', 'art30.consecutivi' => false, 'art30.inCessazione' => true, 'art31.esito' => 'rispettato', 'art31.inCessazione' => false,
            ]],
            'oltre, oltre, sotto, oltre: l’obbligo nato prima non è cessato con un solo esercizio sotto, quindi non rispettato' => [2026, [2025 => $oltre, 2024 => $ok, 2023 => $oltre, 2022 => $oltre], [
                'art30.esito' => 'non_rispettato', 'art30.consecutivi' => false, 'art30.persiste' => true,
            ]],
            'sotto, oltre, sotto, oltre: l’obbligo non è mai nato, vicino' => [2026, [2025 => $oltre, 2024 => $ok, 2023 => $oltre, 2022 => $ok], ['art30.esito' => 'vicino', 'art30.persiste' => false]],
            'oltre, sotto, oltre e prima non si sa: per prudenza l’obbligo può essere nato, non rispettato' => [2026, [2025 => $oltre, 2024 => $ok, 2023 => $oltre, 2022 => null], ['art30.esito' => 'non_rispettato', 'art30.persiste' => true]],
            'sotto i limiti e l’esercizio prima non è nell’app: rispettato (non si inventa un obbligo che non si può dimostrare)' => [2026, [2025 => $ok, 2024 => null], ['art30.esito' => 'rispettato', 'art30.inCessazione' => false]],
            'sotto i limiti da due esercizi: l’obbligo è cessato' => [2026, [2025 => $ok, 2024 => $ok], ['art30.esito' => 'rispettato', 'art30.inCessazione' => false]],
            'un limite solo superato: non basta' => [2026, [2025 => self::m(100_000, 200_000, 3), 2024 => $ok], ['art30.superati' => 1, 'art30.esito' => 'rispettato']],
            'sette dipendenti non superano il limite (ma sono al limite), otto sì' => [2026, [2025 => self::m(100_000, 0, 7), 2024 => $ok], ['art30.superati' => 0, 'art30.parametri.dipendenti.stato' => 'vicino']],
            'otto dipendenti e le entrate oltre 300.000: due limiti' => [2026, [2025 => self::m(300_001, 0, 8), 2024 => $ok], ['art30.superati' => 2, 'art30.parametri.dipendenti.stato' => 'superato', 'art30.parametri.entrate.stato' => 'superato']],
            'attivo non dichiarato: conta come superato e si segnala' => [2026, [2025 => self::m(100_000, null, 3), 2024 => $ok], [
                'art30.superati' => 1, 'art30.mancanti' => 1, 'art30.parametri.attivo.stato' => 'mancante', 'art30.esito' => 'rispettato',
            ]],
            'attivo e dipendenti non dichiarati: due limiti per prudenza, ma l’esercizio prima era sotto: vicino' => [2026, [2025 => self::m(100_000, null, null), 2024 => $ok], [
                'art30.superati' => 2, 'art30.mancanti' => 2, 'art30.esito' => 'vicino',
            ]],
            'due parametri oltre l’80% del limite, nessuno superato: vicino' => [2026, [2025 => self::m(250_000, 130_000, 2), 2024 => $ok], ['art30.superati' => 0, 'art30.esito' => 'vicino', 'art30.parametri.entrate.stato' => 'vicino']],
            'un parametro vicino e uno non dichiarato (che vale superato): due parametri alla soglia, vicino' => [2026, [2025 => self::m(250_000, null, 2), 2024 => $ok], ['art30.superati' => 1, 'art30.mancanti' => 1, 'art30.esito' => 'vicino']],
            'la revisione ha limiti dieci volte più alti' => [2026, [2025 => self::m(3_000_001, 1_500_001, 21), 2024 => self::m(3_000_001, 1_500_001, 21)], [
                'art31.superati' => 3, 'art31.esito' => 'non_rispettato', 'art31.parametri.attivo.limite' => 150_000_000, 'art31.parametri.entrate.limite' => 300_000_000, 'art31.parametri.dipendenti.limite' => 20,
            ]],
        ];
    }

    /**
     * @param  array<int, ?array>  $misure
     * @param  array<string, mixed>  $atteso
     */
    #[DataProvider('casi')]
    public function test_a_tabella(int $anno, array $misure, array $atteso): void
    {
        $r = Soglie::calcola($anno, $misure, 80);

        foreach ($atteso as $percorso => $valore) {
            $trovato = Arr::get($r, $percorso);
            $this->assertEquals($valore, $trovato, "Valore di $percorso");
            $this->assertSame(gettype($valore), gettype($trovato), "Tipo di $percorso");
        }
    }
}
