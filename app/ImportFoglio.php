<?php

namespace App;

use App\Import\Estratto;
use App\Import\RigaEstratto;
use App\Models\Conto;
use App\Models\Movimento;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * I controlli di un import del foglio della cassa (#64, #19): le righe sparite dal foglio e il confronto mese per mese tra entrate, uscite e saldo
 * dell'app e quelli del foglio (colonna Saldo). Blocca la conferma solo un saldo finale che non torna; il resto diventa un dubbio sul movimento.
 * Il confronto si fa su quello che l'app avrà dopo la conferma: i movimenti di adesso più le righe nuove del file (che a conferma fatta sono già dentro).
 */
final class ImportFoglio
{
    /**
     * @param  list<RigaEstratto>  $nuove  le righe del file che entrano con questa conferma (già presenti: vuoto)
     * @return array{mesi: list<array<string, mixed>>, finale: array{foglio: ?int, app: ?int, ok: bool}, iniziale: ?array{foglio: int, apertura: int, ok: bool}, sparite: list<array<string, mixed>>}
     */
    public static function controlli(Conto $conto, Estratto $estratto, array $nuove): array
    {
        $sparite = self::sparite($conto, $estratto);
        $netto = fn (RigaEstratto $r) => $r->importo;

        // Per mese: entrate e uscite dell'app (i movimenti di adesso e le righe nuove), poi il saldo di fine mese.
        $app = [];
        $dalDb = $conto->movimenti()->selectRaw("date_format(data, '%Y-%m') as mese, verso, cast(sum(importo) as signed) as totale")->groupBy('mese', 'verso')->get();
        foreach ($dalDb as $r) {
            $app[$r->mese][$r->verso === 'entrata' ? 0 : 1] = (int) $r->totale;
        }
        foreach ($nuove as $r) {
            $app[substr($r->data, 0, 7)][$r->importo > 0 ? 0 : 1] = ($app[substr($r->data, 0, 7)][$r->importo > 0 ? 0 : 1] ?? 0) + abs($r->importo);
        }
        $foglio = [];
        foreach ($estratto->righe as $r) {
            $foglio[substr($r->data, 0, 7)][$r->importo > 0 ? 0 : 1] = ($foglio[substr($r->data, 0, 7)][$r->importo > 0 ? 0 : 1] ?? 0) + abs($r->importo);
        }

        $mesi = [];
        $saldo = $conto->saldo_iniziale;
        $tutti = array_unique([...array_keys($app), ...array_keys($foglio)]);
        sort($tutti);
        foreach ($tutti as $mese) {
            $saldo += ($app[$mese][0] ?? 0) - ($app[$mese][1] ?? 0);
            if (! isset($foglio[$mese])) {
                continue; // un mese che il foglio non ha non si confronta (i movimenti a mano dopo il passaggio, un conto aperto prima)
            }
            $f = ['entrate' => $foglio[$mese][0] ?? 0, 'uscite' => $foglio[$mese][1] ?? 0, 'saldo' => $estratto->saldiMensili[$mese] ?? null];
            $a = ['entrate' => $app[$mese][0] ?? 0, 'uscite' => $app[$mese][1] ?? 0, 'saldo' => $saldo];
            $mesi[] = ['mese' => $mese, 'foglio' => $f, 'app' => $a, 'ok' => $f['entrate'] === $a['entrate'] && $f['uscite'] === $a['uscite'] && ($f['saldo'] === null || $f['saldo'] === $a['saldo'])];
        }

        // Il saldo finale vale per quello che il file dice: le righe sparite restano nell'app (mai cancellate) ma non fanno fallire il controllo, hanno il loro dubbio.
        $firmato = fn (Movimento $m) => $m->verso === 'entrata' ? $m->importo : -$m->importo;
        $finaleApp = $estratto->saldoFinale === null ? null
            : $conto->saldoAl($estratto->al()) + array_sum(array_map($netto, $nuove)) - $sparite->where('data', '<=', Carbon::parse($estratto->al()))->sum($firmato);

        return [
            'mesi' => $mesi,
            'finale' => ['foglio' => $estratto->saldoFinale, 'app' => $finaleApp, 'ok' => $estratto->saldoFinale === null || $estratto->saldoFinale === $finaleApp],
            'iniziale' => $estratto->saldoIniziale === null ? null : ['foglio' => $estratto->saldoIniziale, 'apertura' => $conto->saldo_iniziale, 'ok' => $estratto->saldoIniziale === $conto->saldo_iniziale],
            'sparite' => $sparite->map(fn (Movimento $m) => ['id' => $m->id, 'data' => $m->data->toDateString(), 'descrizione' => $m->descrizione, 'importo' => $firmato($m)])->values()->all(),
        ];
    }

    /**
     * I movimenti che un import del foglio aveva portato e che il file di adesso non ha più. Mai cancellati: si segnalano.
     *
     * @return Collection<int, Movimento>
     */
    public static function sparite(Conto $conto, Estratto $estratto): Collection
    {
        $chiavi = array_flip(array_map(fn (RigaEstratto $r) => $r->chiave(), $estratto->righe));

        return $conto->movimenti()->whereNotNull('import_estratto_id')->whereNotNull('chiave')->orderBy('data')->orderBy('id')->get()
            ->reject(fn (Movimento $m) => isset($chiavi[$m->chiave]))->values();
    }

    /**
     * A conferma fatta, i dubbi che i controlli lasciano: uno sulle righe sparite e uno sull'ultimo movimento di ogni mese che non torna. Per chi ha già
     * bloccato il conto; se il dubbio c'è già aperto non se ne scrive un altro.
     *
     * @param  array{mesi: list<array<string, mixed>>, sparite: list<array<string, mixed>>}  $controlli
     */
    public static function segnalaDubbi(User $utente, Conto $conto, array $controlli): void
    {
        foreach ($controlli['sparite'] as $s) {
            Note::segnalaDubbio($utente, Movimento::findOrFail($s['id']), 'Non c’è più nel foglio: l’import l’aveva portata e l’ultimo foglio caricato no. Controlla se è stata tolta per errore.', 'Non c’è più nel foglio');
        }
        foreach (array_filter($controlli['mesi'], fn (array $m) => ! $m['ok']) as $m) {
            $ultimo = $conto->movimenti()->whereBetween('data', ["{$m['mese']}-01", Carbon::parse("{$m['mese']}-01")->endOfMonth()->toDateString()])->orderByDesc('data')->orderByDesc('id')->first();
            if ($ultimo === null) {
                continue;
            }
            $inizio = 'Controllo del foglio di '.self::mese($m['mese']).':';
            $euro = fn (?int $c) => $c === null ? '–' : Euro::formatta($c).' €';
            Note::segnalaDubbio($utente, $ultimo, "$inizio il foglio ha entrate {$euro($m['foglio']['entrate'])}, uscite {$euro($m['foglio']['uscite'])} e saldo {$euro($m['foglio']['saldo'])}; l'app ha entrate {$euro($m['app']['entrate'])}, uscite {$euro($m['app']['uscite'])} e saldo {$euro($m['app']['saldo'])}.", $inizio);
        }
    }

    /** «marzo 2026» da «2026-03». */
    public static function mese(string $mese): string
    {
        return Carbon::parse("$mese-01")->locale('it')->translatedFormat('F Y');
    }
}
