<?php

namespace Tests\Unit;

use App\Import\Estratto;
use App\Import\EstrattoNonValido;
use App\Import\FoglioCassa;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\FogliInventati;

/** La lettura della scheda Cassa del foglio 2026 (#64): fogli inventati, colonne riconosciute dal nome dell'intestazione. */
class FoglioCassaTest extends TestCase
{
    use FogliInventati;

    private const INTESTAZIONE = ['Data', 'Nuova causale', 'Descrizione', 'Rif. documento', 'Entrate', 'Uscite', 'Saldo'];

    /** Una scheda Cassa: titolo, intestazione, saldo iniziale, le righe, la riga dei totali. */
    private static function cassa(array ...$righe): array
    {
        return ['Mappatura' => [['x']], 'Cassa' => [['Cassa 2026'], self::INTESTAZIONE, [null, null, 'Saldo iniziale', null, null, null, 500], ...$righe, ['Totale', null, null, null, 10, 20]]];
    }

    private static function leggi(array $fogli, string $formato = 'xlsx'): Estratto
    {
        return (new FoglioCassa)->leggi(self::$formato($fogli));
    }

    public function test_legge_le_righe_saltando_saldo_iniziale_e_totali_e_normalizza_le_causali(): void
    {
        $estratto = self::leggi(self::cassa(
            [new DateTimeImmutable('2026-03-01'), '-> Utenze', 'Bolletta', 'F1', null, 80.5, 419.5],
            ['02/04/2026', '→ Tesseramento ord ', 'Quota', null, 20, null, 439.5],
        ));

        $this->assertCount(2, $estratto->righe);
        [$a, $b] = $estratto->righe;
        $this->assertSame(['2026-03-01', 'Utenze', 'Bolletta', -8050], [$a->data, $a->causale, $a->descrizione, $a->importo]);
        $this->assertSame(['2026-04-02', 'Tesseramento ord', 'Quota', 2000], [$b->data, $b->causale, $b->descrizione, $b->importo]);
        $this->assertSame(50000, $estratto->saldoIniziale);
        $this->assertSame(43950, $estratto->saldoFinale);
        $this->assertSame(['2026-03' => 41950, '2026-04' => 43950], $estratto->saldiMensili);
        $this->assertNull($a->saldoParziale); // il saldo non entra nella riga: cambiandolo, ogni riga dopo sarebbe un conflitto
    }

    #[DataProvider('formati')]
    public function test_legge_anche_l_ods(string $formato): void
    {
        $estratto = self::leggi(self::cassa([new DateTimeImmutable('2026-03-01'), 'Utenze', 'Bolletta', null, null, 10, 490]), $formato);

        $this->assertSame(-1000, $estratto->righe[0]->importo);
    }

    /** @return array<string, array{0: string}> */
    public static function formati(): array
    {
        return ['xlsx' => ['xlsx'], 'ods' => ['ods']];
    }

    public function test_le_colonne_si_riconoscono_dal_nome_e_non_dalla_posizione(): void
    {
        $estratto = self::leggi(['Cassa' => [
            ['Saldo', 'Uscite', 'Entrate', 'Descrizione', 'Nuova causale', 'Data', 'Nome', 'Email'],
            [100, null, 15, 'Quota', 'Tesseramento ord', '2026-01-05', 'Persona Inventata', 'persona@example.org'],
        ]]);

        $this->assertSame(1500, $estratto->righe[0]->importo);
        $this->assertSame('Quota', $estratto->righe[0]->descrizione);
        $this->assertStringNotContainsString('Inventata', json_encode($estratto->righe)); // le colonne fuori elenco non si leggono
    }

    public function test_email_e_iban_nel_testo_si_mascherano(): void
    {
        $estratto = self::leggi(self::cassa([new DateTimeImmutable('2026-03-01'), 'Utenze', 'Bonifico a persona@example.org IT60X0542811101000000123456', null, null, 10, 490]));

        $this->assertStringNotContainsString('persona@example.org', $estratto->righe[0]->descrizione);
        $this->assertStringNotContainsString('IT60X0542811101000000123456', $estratto->righe[0]->descrizione);
        $this->assertStringContainsString('3456', $estratto->righe[0]->descrizione);
    }

    public function test_righe_identiche_dello_stesso_giorno_hanno_chiavi_diverse_e_stabili_se_ne_arriva_un_altra(): void
    {
        $uguale = [new DateTimeImmutable('2026-03-01'), 'Utenze', 'Caffè', 'F1', null, 2, 0];
        $altra = [new DateTimeImmutable('2026-03-01'), 'Utenze', 'Altro', 'F9', null, 5, 0];
        $prima = self::leggi(self::cassa($uguale, $uguale))->righe;
        $dopo = self::leggi(self::cassa($altra, $uguale, $uguale))->righe;

        $this->assertNotSame($prima[0]->chiave(), $prima[1]->chiave());
        // L'ordine conta solo tra le righe identiche: una riga diversa inserita prima non sposta le chiavi delle altre.
        $this->assertSame([$prima[0]->chiave(), $prima[1]->chiave()], [$dopo[1]->chiave(), $dopo[2]->chiave()]);
        $this->assertLessThanOrEqual(40, strlen($prima[0]->chiave()));
    }

    public function test_la_chiave_non_dipende_dal_saldo_ne_dalla_causale_ne_dalla_descrizione(): void
    {
        $a = self::leggi(self::cassa([new DateTimeImmutable('2026-03-01'), 'Utenze', 'Bolletta', 'F-1', null, 80, 420]))->righe[0];
        $b = self::leggi(self::cassa([new DateTimeImmutable('2026-03-01'), 'Altre spese', 'Altro testo', 'f 1', null, 80, 999]))->righe[0];
        $c = self::leggi(self::cassa([new DateTimeImmutable('2026-03-01'), 'Utenze', 'Bolletta', 'F-2', null, 80, 420]))->righe[0];

        $this->assertSame($a->chiave(), $b->chiave()); // il riferimento è normalizzato: «F-1» e «f 1» sono lo stesso
        $this->assertNotSame($a->chiave(), $c->chiave());
    }

    public function test_un_importo_con_una_data_illeggibile_si_rifiuta_con_il_numero_di_riga(): void
    {
        $this->expectException(EstrattoNonValido::class);
        $this->expectExceptionMessage('Riga 4');

        self::leggi(self::cassa(['ieri', 'Utenze', 'Bolletta', null, null, 10, 490]));
    }

    public function test_senza_la_scheda_cassa_o_senza_le_colonne_necessarie_si_rifiuta(): void
    {
        foreach ([['Banca' => [self::INTESTAZIONE]], ['Cassa' => [['Data', 'Descrizione']]], ['Cassa' => [self::INTESTAZIONE]]] as $fogli) {
            try {
                self::leggi($fogli);
                $this->fail('Doveva rifiutare.');
            } catch (EstrattoNonValido $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_prelievi_e_versamenti_sono_giroconti_con_il_conto_bancario(): void
    {
        $regole = (new FoglioCassa)->regoleGiroconti();

        $this->assertSame(['entrata', 'banca'], [$regole['prelievo']['verso'], $regole['prelievo']['con']]);
        $this->assertSame(['uscita', 'banca'], [$regole['versamento']['verso'], $regole['versamento']['con']]);
        $this->assertArrayNotHasKey('meta', $regole['versamento']); // la metà sta sull'estratto della banca, non si scrive qui
    }
}
