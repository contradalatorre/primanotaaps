<?php

namespace Tests\Unit;

use App\Esercizio\RendicontoFoglio;
use App\Esercizio\SchemaModelloD;
use PHPUnit\Framework\TestCase;

/** Il riconoscimento delle voci dal foglio `RUNTS_Rendiconto` (#63): righe inventate, importi in euro nel foglio e in centesimi nel risultato. */
class RendicontoFoglioTest extends TestCase
{
    /**
     * Un foglio come lo scrive il Tesoriere: intestazioni di sezione, righe numerate (sempre tutte), totali. `$importi` è «lato:voce» → euro.
     *
     * @param  array<string, float>  $importi
     * @param  array<string, float>  $totali  sostituisce un totale calcolato giusto: «uscite:A», «avanzo_investimenti»…
     * @return list<list<mixed>>
     */
    public static function foglio(array $importi, array $totali = [], array $extra = []): array
    {
        $righe = [['Rendiconto per cassa 2025'], ['Esercizio', 2025]];
        $somma = ['uscite' => 0.0, 'entrate' => 0.0];
        $avanzi = [];
        foreach (['uscite' => 'USCITE', 'entrate' => 'ENTRATE'] as $lato => $titolo) {
            foreach (SchemaModelloD::AREE as $area => $nome) {
                $righe[] = [$area.') '.($lato === 'uscite' ? 'Uscite' : 'Entrate').' da '.lcfirst($nome)];
                $t = 0.0;
                foreach (SchemaModelloD::voci($lato)[$area] as $codice => $etichetta) {
                    $righe[] = [substr($codice, 1).') '.$etichetta, $importi["$lato:$codice"] ?? 0, 999]; // l'ultima colonna è l'anno precedente
                    $t += $importi["$lato:$codice"] ?? 0;
                }
                $righe[] = ['Totale', $totali["$lato:$area"] ?? $t];
                $somma[$lato] += $t;
                $avanzi[$area][$lato] = $t;
            }
        }
        $imposte = $importi['uscite:Imposte'] ?? 0;
        $righe[] = ['Totale uscite della gestione', $totali['gestione:uscite'] ?? $somma['uscite']];
        $righe[] = ['Totale entrate della gestione', $totali['gestione:entrate'] ?? $somma['entrate']];
        $righe[] = ['Avanzo/disavanzo d’esercizio prima delle imposte', $totali['avanzo_prima_imposte'] ?? $somma['entrate'] - $somma['uscite']];
        $righe[] = ['Imposte', $imposte];
        $righe[] = ['Avanzo/disavanzo d’esercizio prima degli investimenti e disinvestimenti patrimoniali e finanziamenti', $totali['avanzo_prima_investimenti'] ?? $somma['entrate'] - $somma['uscite'] - $imposte];
        $inv = ['uscite' => 0.0, 'entrate' => 0.0];
        foreach (['uscite' => 'Uscite da investimenti in immobilizzazioni o da deflussi di capitale di terzi', 'entrate' => 'Entrate da disinvestimenti di immobilizzazioni o da flussi di capitale di terzi'] as $lato => $titolo) {
            $righe[] = [$titolo];
            foreach (SchemaModelloD::investimenti($lato) as $codice => $etichetta) {
                $righe[] = [substr($codice, 1).') '.$etichetta, $importi["$lato:$codice"] ?? 0];
                $inv[$lato] += $importi["$lato:$codice"] ?? 0;
            }
            $righe[] = ['Totale', $inv[$lato]];
        }
        $righe[] = ['Avanzo/disavanzo da investimenti e disinvestimenti patrimoniali e finanziamenti', $totali['avanzo_investimenti'] ?? $inv['entrate'] - $inv['uscite']];
        $righe[] = ['Avanzo/disavanzo complessivo', $totali['avanzo_complessivo'] ?? $somma['entrate'] - $somma['uscite'] - $imposte + $inv['entrate'] - $inv['uscite']];
        $righe[] = ['Cassa', $extra['cassa'] ?? 0];
        $righe[] = ['Depositi bancari e postali', $extra['depositi'] ?? 0];

        return $righe;
    }

    public function test_riconosce_le_voci_dall_intestazione_di_sezione_e_dal_numero_e_non_dall_etichetta(): void
    {
        $esito = RendicontoFoglio::leggi(self::foglio([
            'entrate:A1' => 1234.56, 'entrate:E2' => 10, 'uscite:A1' => 50.5, 'uscite:E1' => 7, 'uscite:B2' => 3, 'uscite:Imposte' => 12, 'uscite:I1' => 100, 'entrate:I4' => 40,
        ], extra: ['cassa' => 800, 'depositi' => 4200.25]));

        $this->assertEqualsCanonicalizing([
            'entrate:A1' => 123456, 'entrate:E2' => 1000, 'uscite:A1' => 5050, 'uscite:B2' => 300, 'uscite:E1' => 700, 'uscite:Imposte' => 1200, 'uscite:I1' => 10000, 'entrate:I4' => 4000,
        ], $esito['voci']);
        $this->assertCount(8, $esito['voci']);
        $this->assertSame([], $esito['nonRiconosciute']); // né l'anno precedente né l'«Esercizio 2025» sono voci
        $this->assertSame(80000, $esito['saldi']['cassa']);
        $this->assertSame(420025, $esito['saldi']['depositi']);
    }

    public function test_una_riga_con_importo_che_non_si_riconosce_resta_da_abbinare_a_mano(): void
    {
        $righe = self::foglio(['uscite:A1' => 10]);
        array_splice($righe, 5, 0, [['9) Voce che nel DM non esiste', 33.1], ['Altri oneri vari', 2], ['Una riga con zero', 0]]);

        $esito = RendicontoFoglio::leggi($righe);

        $this->assertSame([['riga' => 6, 'etichetta' => '9) Voce che nel DM non esiste', 'importo' => 3310], ['riga' => 7, 'etichetta' => 'Altri oneri vari', 'importo' => 200]], $esito['nonRiconosciute']);
        $this->assertSame(['uscite:A1' => 1000], $esito['voci']);
    }

    public function test_legge_i_totali_del_foglio_per_confrontarli(): void
    {
        $esito = RendicontoFoglio::leggi(self::foglio(
            ['uscite:A1' => 100, 'entrate:A4' => 300, 'entrate:I1' => 0, 'uscite:I2' => 20],
            ['avanzo_investimenti' => 20.0, 'uscite:A' => 90], // la formula sbagliata sull'avanzo da investimenti e un totale di sezione che non torna
        ));

        $this->assertSame(2000, $esito['totali']['avanzo_investimenti']);
        $this->assertSame(9000, $esito['totali']['uscite:A']);
        $this->assertSame(30000, $esito['totali']['entrate:A']);
        $this->assertSame(20000, $esito['totali']['avanzo_prima_imposte']);
        $this->assertSame(30000, $esito['totali']['gestione:entrate']);
        $this->assertSame(18000, $esito['totali']['avanzo_complessivo']);
    }

    public function test_senza_cassa_e_depositi_i_saldi_mancano(): void
    {
        $esito = RendicontoFoglio::leggi([['A) Uscite da attività di interesse generale'], ['1) Materie prime', 5]]);

        $this->assertNull($esito['saldi']['cassa']);
        $this->assertNull($esito['saldi']['depositi']);
        $this->assertSame(['uscite:A1' => 500], $esito['voci']);
    }

    public function test_una_cella_2025_vuota_vale_zero_e_non_prende_l_importo_della_colonna_accanto(): void
    {
        $esito = RendicontoFoglio::leggi([
            ['A) Uscite da attività di interesse generale'],
            ['1) Materie prime', null, 999], // 2025 vuoto, anno precedente pieno
            ['2) Servizi', 20, 999],
            ['Totale', null, 888],
            ['Cassa', null, 777],
        ]);

        $this->assertSame(['uscite:A2' => 2000], $esito['voci']);
        $this->assertSame(0, $esito['totali']['uscite:A']);
        $this->assertSame([], $esito['nonRiconosciute']);
        $this->assertSame(0, $esito['saldi']['cassa']);
    }

    public function test_un_area_intera_vuota_nel_2025_non_riporta_l_anno_precedente(): void
    {
        $righe = array_map(fn ($riga) => count($riga) > 1 && is_numeric($riga[1]) ? [$riga[0], null, $riga[1]] : $riga, self::foglio(['uscite:A1' => 10, 'entrate:A4' => 30, 'uscite:I1' => 5]));

        $esito = RendicontoFoglio::leggi($righe);

        $this->assertSame([], $esito['voci']);
        $this->assertSame([], $esito['nonRiconosciute']);
        $this->assertSame(0, $esito['totali']['gestione:entrate']);
        $this->assertSame(0, $esito['totali']['avanzo_complessivo']);
    }

    public function test_i_figurativi_dopo_cassa_e_banca_non_diventano_voci_degli_investimenti(): void
    {
        $righe = self::foglio(['uscite:I1' => 100], extra: ['cassa' => 1, 'depositi' => 2]);
        array_push($righe,
            ['Cassa e banca'],
            ['Costi figurativi'], ['1) Da attività di interesse generale', 50], ['2) Da attività diverse', 7],
            ['Proventi figurativi'], ['1) Da attività di interesse generale', 60], ['Totale', 60],
        );

        $esito = RendicontoFoglio::leggi($righe);

        $this->assertSame(['uscite:I1' => 10000], $esito['voci']);
        $this->assertSame(['costi' => ['A' => 5000, 'B' => 700], 'proventi' => ['A' => 6000]], $esito['figurativi']);
        $this->assertSame([], $esito['nonRiconosciute']);
        $this->assertSame(10000, $esito['totali']['investimenti:uscite']); // il «Totale» dei figurativi non sovrascrive quello degli investimenti
    }
}
