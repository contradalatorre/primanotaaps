<?php

namespace Tests\Unit;

use App\Import\EsempiDalFoglio;
use PHPUnit\Framework\TestCase;

/** Gli esempi di classificazione dalle schede dei conti del foglio 2025 (#63): righe inventate, conversione passata dal chiamante. */
class EsempiDalFoglioTest extends TestCase
{
    /** La conversione: chiave (minuscola) → verso, tipologia, area, attività, iniziativa. Gli id sono inventati. */
    private const CONVERSIONE = [
        'utenze' => ['verso' => 'uscita', 'tipologia_id' => 11, 'area' => 'E', 'attivita_id' => null, 'iniziativa_id' => null],
        'festa della torre, servizi' => ['verso' => 'uscita', 'tipologia_id' => 12, 'area' => 'A', 'attivita_id' => 5, 'iniziativa_id' => 7],
        'tesseramento ord' => ['verso' => 'entrata', 'tipologia_id' => 21, 'area' => 'A', 'attivita_id' => null, 'iniziativa_id' => null],
        'prelievo' => ['verso' => 'giroconto', 'tipologia_id' => null, 'area' => null, 'attivita_id' => null, 'iniziativa_id' => null],
    ];

    private static function cassa(array ...$righe): array
    {
        return ['Cassa' => [['Cassa 2025'], ['Data', 'Descrizione', 'Nuova causale', 'Entrate', 'Uscite', 'Saldo'], [null, 'Saldo iniziale', null, null, null, 500], ...$righe, ['Totale', null, null, 10, 20]]];
    }

    public function test_ogni_riga_con_data_causale_convertibile_e_importo_diventa_un_esempio(): void
    {
        $esito = EsempiDalFoglio::leggi(self::cassa(
            ['2025-03-01', 'Bolletta luce di marzo', '-> Utenze', null, 80.5, 419.5],
            ['02/04/2025', 'Quota anno 2025', 'Tesseramento ord ', 20, null, 439.5],
            ['2025-05-10', 'Cena', "→ Festa della Torre, servizi", null, 1234.56, 0],
        ), 2025, self::CONVERSIONE);

        $this->assertSame([
            ['verso' => 'uscita', 'causale' => null, 'descrizione' => 'Bolletta luce di marzo', 'importo' => 8050, 'data' => '2025-03-01', 'tipologia_id' => 11, 'area' => 'E', 'attivita_id' => null, 'iniziativa_id' => null],
            ['verso' => 'entrata', 'causale' => null, 'descrizione' => 'Quota anno 2025', 'importo' => 2000, 'data' => '2025-04-02', 'tipologia_id' => 21, 'area' => 'A', 'attivita_id' => null, 'iniziativa_id' => null],
            ['verso' => 'uscita', 'causale' => null, 'descrizione' => 'Cena', 'importo' => 123456, 'data' => '2025-05-10', 'tipologia_id' => 12, 'area' => 'A', 'attivita_id' => 5, 'iniziativa_id' => 7],
        ], $esito['esempi']);
        $this->assertSame(['Cassa'], $esito['schede']);
        $this->assertSame([], $esito['scartate']);
    }

    public function test_la_causale_dell_estratto_resta_quella_dell_estratto_e_le_colonne_non_ammesse_non_si_leggono(): void
    {
        $fogli = ['Banca' => [
            ['Data', 'Causale', 'Descrizione', 'Nome', 'Email', 'Nuova causale', 'Importo'],
            ['2025-02-01', 'PAGAMENTO TRAMITE POS', 'Ferramenta Rossi', 'Mario Rossi', 'mario@example.org', 'Utenze', -45],
            ['2025-02-02', 'BONIFICO', 'Quota di mario@example.org per il 2025', null, null, 'Tesseramento ord', 20],
        ]];

        $esempi = EsempiDalFoglio::leggi($fogli, 2025, self::CONVERSIONE)['esempi'];

        $this->assertSame(['PAGAMENTO TRAMITE POS', 'BONIFICO'], array_column($esempi, 'causale'));
        $this->assertSame(['uscita', 'entrata'], array_column($esempi, 'verso')); // il segno dell'importo è il verso
        $this->assertSame([4500, 2000], array_column($esempi, 'importo'));
        $this->assertSame(['Ferramenta Rossi', 'Quota di per il 2025'], array_column($esempi, 'descrizione')); // l'email sparisce
        $this->assertStringNotContainsString('Mario', json_encode($esempi));
    }

    public function test_le_righe_che_non_si_possono_convertire_si_scartano_e_si_contano_per_motivo(): void
    {
        $esito = EsempiDalFoglio::leggi(self::cassa(
            ['2025-03-01', 'ok', 'Utenze', null, 10, 0],
            ['2025-03-02', 'causale sconosciuta', 'Causale mai vista', null, 10, 0],
            ['2025-03-03', 'giroconto', 'Prelievo', null, 10, 0],
            ['2025-03-04', 'segno sbagliato', 'Utenze', 10, null, 0],
            ['2025-03-05', 'senza causale', null, null, 10, 0],
            ['2024-12-31', 'dell’anno prima', 'Utenze', null, 10, 0],
            ['non è una data', 'senza data', 'Utenze', null, 10, 0],
            ['2025-03-06', null, 'Utenze', null, 10, 0],
            ['2025-03-07', 'senza importo', 'Utenze', null, null, 0],
        ), 2025, self::CONVERSIONE);

        $this->assertCount(1, $esito['esempi']);
        $this->assertSame(['non_convertita' => 1, 'senza_tipologia' => 1, 'verso_diverso' => 1, 'senza_causale' => 1, 'fuori_anno' => 1, 'senza_data' => 1, 'senza_descrizione' => 1, 'senza_importo' => 1], $esito['scartate']);
    }

    public function test_le_schede_che_non_sono_dei_conti_o_non_hanno_le_colonne_si_saltano(): void
    {
        $esito = EsempiDalFoglio::leggi([
            'Mappatura' => [['Data', 'Nuova causale', 'Importo'], ['2025-01-01', 'Utenze', 5]],
            'Banca' => [['Qualcosa', 'altro'], ['x', 'y']],
            'RUNTS_Rendiconto' => [['A) Uscite']],
        ], 2025, self::CONVERSIONE);

        $this->assertSame([], $esito['esempi']);
        $this->assertSame([], $esito['schede']);
    }
}
