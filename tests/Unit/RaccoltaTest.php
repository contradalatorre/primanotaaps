<?php

namespace Tests\Unit;

use App\Esercizio\Raccolta;
use App\Esercizio\SchemaModelloD;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Il rendiconto di una raccolta fondi occasionale (#61, #18, DM 9/6/2022): modulo puro, importi inventati in centesimi. */
class RaccoltaTest extends TestCase
{
    private function e(?string $riga, int $importo, string $conto = 'cassa'): array
    {
        return ['lato' => 'entrate', 'riga' => $riga, 'importo' => $importo, 'conto' => $conto];
    }

    private function u(?string $riga, int $importo, string $conto = 'cassa'): array
    {
        return ['lato' => 'uscite', 'riga' => $riga, 'importo' => $importo, 'conto' => $conto];
    }

    private function nonMonetaria(int $valore): array
    {
        return ['descrizione' => 'Cesti di prodotti locali', 'quantita' => 4, 'valore' => $valore, 'criterio' => 'Prezzo di listino del donatore', 'documento' => 'Ricevuta n. 1'];
    }

    public function test_proventi_oneri_e_risultato_secondo_lo_schema_del_decreto(): void
    {
        $r = Raccolta::calcola([
            $this->e('liberalita_monetarie', 30000), $this->e('liberalita_monetarie', 20000, 'depositi'), $this->e('altri_proventi', 5000),
            $this->u('acquisto_beni', 4000), $this->u('oneri_promozionali', 1500), $this->u('rimborsi_volontari', 500),
        ], [$this->nonMonetaria(8000)], []);

        $this->assertSame(['liberalita_monetarie' => 50000, 'liberalita_non_monetarie' => 8000, 'altri_proventi' => 5000, 'totale' => 63000], $r['proventi']);
        $this->assertSame(6000, $r['oneri']['totale']);
        $this->assertSame(4000, $r['oneri']['righe']['acquisto_beni']);
        $this->assertSame(0, $r['oneri']['righe']['noleggi_affitti']);
        $this->assertSame(57000, $r['risultato']);
    }

    public function test_il_denaro_si_divide_tra_contanti_e_conti_e_i_beni_non_monetari_non_sono_denaro(): void
    {
        $r = Raccolta::calcola([
            $this->e('liberalita_monetarie', 30000, 'cassa'), $this->e('altri_proventi', 700, 'cassa'), $this->e('liberalita_monetarie', 20000, 'depositi'),
        ], [$this->nonMonetaria(8000)], []);

        $this->assertSame(['contanti' => 30700, 'conti' => 20000, 'totale' => 50700], $r['relazione']['denaro']);
        $this->assertSame(8000, $r['relazione']['valore_beni']);
        $this->assertSame(['entrate' => 50700, 'uscite' => 0], $r['monetari']); // la quadratura con C2 guarda solo il denaro
    }

    public function test_le_imputazioni_senza_riga_si_contano_a_parte_e_non_entrano_nei_totali(): void
    {
        $r = Raccolta::calcola([$this->e('liberalita_monetarie', 10000), $this->e(null, 2500), $this->u(null, 900), $this->u('acquisto_servizi', 100)], [], []);

        $this->assertSame(['entrate' => ['numero' => 1, 'importo' => 2500], 'uscite' => ['numero' => 1, 'importo' => 900]], $r['senzaRiga']);
        $this->assertSame(10000, $r['proventi']['totale']);
        $this->assertSame(100, $r['oneri']['totale']);
    }

    /** @return array<string, array{0: list<array>, 1: list<array>, 2: ?string}> */
    public static function motivazioni(): array
    {
        $e = fn (int $c) => ['lato' => 'entrate', 'riga' => 'liberalita_monetarie', 'importo' => $c, 'conto' => 'cassa'];
        $u = fn (int $c) => ['lato' => 'uscite', 'riga' => 'acquisto_beni', 'importo' => $c, 'conto' => 'cassa'];

        return [
            'risultato negativo' => [[$e(10000), $u(10001)], [], 'negativo'],
            'spese al 90% esatto: non oltre' => [[$e(10000), $u(9000)], [], null],
            'un centesimo oltre il 90%' => [[$e(10000), $u(9001)], [], 'oltre_90'],
            'le liberalità non monetarie fanno parte delle entrate' => [[$e(10000), $u(9001)], [['descrizione' => 'x', 'quantita' => 1, 'valore' => 1000, 'criterio' => 'c', 'documento' => 'd']], null],
            'spese senza nessuna entrata' => [[$u(100)], [], 'negativo'],
            'niente di niente' => [[], [], null],
            'solo entrate' => [[$e(500)], [], null],
        ];
    }

    #[DataProvider('motivazioni')]
    public function test_la_motivazione_serve_se_il_risultato_e_negativo_o_le_spese_superano_il_90_per_cento(array $righe, array $liberalita, ?string $motivo): void
    {
        $m = Raccolta::calcola($righe, $liberalita, [])['motivazione'];

        $this->assertSame($motivo, $m['motivo']);
        $this->assertSame($motivo !== null, $m['richiesta']);
        $this->assertFalse($m['presente']);
    }

    public function test_la_motivazione_scritta_la_soddisfa_solo_se_non_e_vuota(): void
    {
        $righe = [$this->e('liberalita_monetarie', 1000), $this->u('acquisto_beni', 1000)];

        $this->assertTrue(Raccolta::calcola($righe, [], ['motivazione' => 'Spese di allestimento'])['motivazione']['presente']);
        $this->assertFalse(Raccolta::calcola($righe, [], ['motivazione' => " \n "])['motivazione']['presente']);
    }

    public function test_la_guida_elenca_cio_che_l_operatore_deve_ancora_scrivere(): void
    {
        $vuota = Raccolta::calcola([$this->e('liberalita_monetarie', 1000)], [], [])['relazione'];
        $campi = array_column($vuota['guida'], 'fatto', 'campo');

        $this->assertSame(
            ['dal' => false, 'al' => false, 'occasione' => false, 'beni_servizi' => false, 'descrizione' => false, 'luogo' => false, 'modalita' => false, 'finalita' => false,
                'dettaglio_costi' => false, 'attivita_destinazione' => false, 'beni_modico_numero' => false, 'beni_modico_prezzo' => false, 'elargizioni_persone_fisiche' => false, 'elargizioni_persone_giuridiche' => false],
            $campi, // la motivazione non è tra i campi: non serve
        );

        $piena = Raccolta::calcola([$this->e('liberalita_monetarie', 1000)], [], [
            'dal' => '2026-12-08', 'al' => '2026-12-08', 'occasione' => 'Giornata della memoria', 'beni_servizi' => 'Nessuno', 'descrizione' => 'Banchetto', 'luogo' => 'Piazza',
            'modalita' => 'Offerte libere', 'finalita' => 'Restauro', 'dettaglio_costi' => 'Nessuna spesa', 'attivita_destinazione' => 'Lettera i (art. 5 CTS)',
            'beni_modico_numero' => 0, 'beni_modico_prezzo' => 0, 'elargizioni_persone_fisiche' => 1000, 'elargizioni_persone_giuridiche' => 0,
        ])['relazione'];
        $this->assertSame([], array_filter(array_column($piena['guida'], 'fatto'), fn ($f) => ! $f));
    }

    public function test_con_la_motivazione_richiesta_la_guida_la_chiede_e_la_relazione_la_riporta(): void
    {
        $r = Raccolta::calcola([$this->e('liberalita_monetarie', 1000), $this->u('acquisto_beni', 1000)], [], ['motivazione' => 'Allestimento'])['relazione'];

        $this->assertTrue(array_column($r['guida'], 'fatto', 'campo')['motivazione']);
        $this->assertSame('Allestimento', $r['campi']['motivazione']);
    }

    public function test_la_quadratura_con_c2_guarda_entrate_e_uscite_in_denaro(): void
    {
        $a = Raccolta::calcola([$this->e('liberalita_monetarie', 10000), $this->u('acquisto_beni', 4000)], [$this->nonMonetaria(9999)], []);
        $b = Raccolta::calcola([$this->e('altri_proventi', 2000), $this->u('acquisto_servizi', 500)], [], []);

        $q = Raccolta::quadratura([$a, $b], ['entrate' => 12000, 'uscite' => 4500], ['entrate' => 0, 'uscite' => 0]);

        $this->assertTrue($q['quadra']);
        $this->assertSame([], $q['messaggi']);
        $this->assertSame(12000, $q['entrate']['raccolte']);
    }

    public function test_senza_raccolte_e_senza_c2_quadra(): void
    {
        $this->assertTrue(Raccolta::quadratura([], ['entrate' => 0, 'uscite' => 0], ['entrate' => 0, 'uscite' => 0])['quadra']);
    }

    public function test_se_non_quadra_il_messaggio_dice_da_dove_viene_la_differenza(): void
    {
        $a = Raccolta::calcola([$this->e('liberalita_monetarie', 10000), $this->e(null, 2500), $this->u('acquisto_beni', 4000)], [], []);

        $q = Raccolta::quadratura([$a], ['entrate' => 15000, 'uscite' => 4000], ['entrate' => 2500, 'uscite' => 0]);

        $this->assertFalse($q['quadra']);
        $this->assertSame(5000, $q['entrate']['differenza']); // C2 − raccolte
        $this->assertSame(0, $q['uscite']['differenza']);
        $this->assertCount(1, $q['messaggi']);
        $this->assertStringContainsString('25,00 € senza riga', $q['messaggi'][0]);
        $this->assertStringContainsString('25,00 € senza iniziativa', $q['messaggi'][0]);
    }

    public function test_le_uscite_che_non_quadrano_hanno_il_loro_messaggio(): void
    {
        $a = Raccolta::calcola([$this->u('acquisto_beni', 4000)], [], []);

        $q = Raccolta::quadratura([$a], ['entrate' => 0, 'uscite' => 4100], ['entrate' => 0, 'uscite' => 100]);

        $this->assertFalse($q['quadra']);
        $this->assertStringStartsWith('Uscite', $q['messaggi'][0]);
        $this->assertStringContainsString('1,00 € senza iniziativa', $q['messaggi'][0]);
    }

    public function test_i_bloccanti_sono_la_quadratura_e_le_motivazioni_mancanti(): void
    {
        $senzaMotivo = Raccolta::calcola([$this->e('liberalita_monetarie', 1000), $this->u('acquisto_beni', 1000)], [], []);
        $conMotivo = Raccolta::calcola([$this->e('liberalita_monetarie', 1000), $this->u('acquisto_beni', 1000)], [], ['motivazione' => 'Allestimento']);
        $nomi = ['Festa del rione'];

        $quadra = ['entrate' => 1000, 'uscite' => 1000];
        $senza = [['nome' => 'Festa del rione'] + $senzaMotivo];
        $con = [['nome' => 'Festa del rione'] + $conMotivo];

        $this->assertSame([], Raccolta::bloccanti($con, Raccolta::quadratura($con, $quadra, ['entrate' => 0, 'uscite' => 0])));
        $bloccanti = Raccolta::bloccanti($senza, Raccolta::quadratura($senza, $quadra, ['entrate' => 0, 'uscite' => 0]));
        $this->assertCount(1, $bloccanti);
        $this->assertStringContainsString('«'.$nomi[0].'»', $bloccanti[0]);
        $this->assertStringContainsString('motivazione', $bloccanti[0]);
        $nonQuadra = Raccolta::bloccanti($con, Raccolta::quadratura($con, ['entrate' => 1500, 'uscite' => 1000], ['entrate' => 0, 'uscite' => 0]));
        $this->assertCount(1, $nonQuadra);
    }

    public function test_le_righe_dello_schema_sono_quelle_del_decreto(): void
    {
        $this->assertSame(['liberalita_monetarie', 'altri_proventi'], array_keys(Raccolta::PROVENTI));
        $this->assertSame(['acquisto_beni', 'acquisto_servizi', 'noleggi_affitti', 'oneri_promozionali', 'lavoro', 'rimborsi_volontari', 'altri_oneri'], array_keys(Raccolta::ONERI));
    }

    /** @return array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string}> */
    public static function voci(): array
    {
        return [
            'in area C la voce della tabella è C1: occasionale diventa C2' => ['C', 'C1', 'occasionale', 'C2'],
            'abituale resta C1' => ['C', 'C1', 'abituale', 'C1'],
            'senza attività di raccolta in area C non si sa: senza riga' => ['C', 'C1', null, null],
            'senza voce in tabella resta senza voce' => ['C', null, 'occasionale', null],
            'fuori dall’area C l’attività non conta' => ['A', 'A4', 'occasionale', 'A4'],
            'una voce di C diversa da C1 non si cambia' => ['C', 'C3', 'occasionale', 'C3'],
        ];
    }

    #[DataProvider('voci')]
    public function test_la_voce_c1_o_c2_si_ricava_dall_attivita_della_raccolta(string $area, ?string $voce, ?string $raccolta, ?string $atteso): void
    {
        $this->assertSame($atteso, SchemaModelloD::voceDi($area, $voce, $raccolta));
    }
}
