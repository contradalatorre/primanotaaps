<?php

namespace App\Models;

use App\Import\Estratto;
use App\Import\RigaEstratto;
use Carbon\CarbonInterface;
use Database\Factories\ContoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un luogo in cui l'associazione tiene denaro (glossario: Conto). Può esistere solo per un periodo. */
#[Fillable(['tipo', 'nome', 'formato_import', 'riga_modello_d', 'aperto_il', 'chiuso_il', 'saldo_iniziale'])]
class Conto extends Model
{
    /** @use HasFactory<ContoFactory> */
    use HasFactory;

    protected $table = 'conti';

    public const TIPI = ['cassa' => 'Cassa', 'banca' => 'Banca', 'paypal' => 'PayPal', 'satispay' => 'Satispay'];

    /** Formati di import per tipo: un file di un altro conto si rifiuta (#14). I moduli veri arrivano con l'import. */
    public const FORMATI_IMPORT = [
        'cassa' => ['manuale' => 'Inserimento a mano', 'foglio_cassa' => 'Foglio di calcolo (cassa)'],
        'banca' => ['credit_agricole_csv' => 'Crédit Agricole (CSV)'],
        'paypal' => ['paypal_csv' => 'PayPal (CSV)'],
        'satispay' => ['satispay_csv' => 'Satispay (report transazioni)'],
    ];

    public const RIGHE_MODELLO_D = ['cassa' => 'Cassa', 'depositi' => 'Depositi'];

    protected function casts(): array
    {
        return ['aperto_il' => 'date', 'chiuso_il' => 'date', 'saldo_iniziale' => 'integer'];
    }

    public function movimenti(): HasMany
    {
        return $this->hasMany(Movimento::class);
    }

    public function importEstratti(): HasMany
    {
        return $this->hasMany(ImportEstratto::class);
    }

    /** Il saldo dell'app: saldo di partenza più i movimenti non eliminati fino a quella data (compresa o no). */
    public function saldoAl(string $data, bool $compresa = true): int
    {
        $somma = $this->movimenti()->where('data', $compresa ? '<=' : '<', $data)
            ->selectRaw("coalesce(sum(case when verso = 'entrata' then cast(importo as signed) else -cast(importo as signed) end), 0) as somma")
            ->value('somma');

        return $this->saldo_iniziale + (int) $somma;
    }

    /**
     * La data fino a cui l'app concorda con la banca: l'ultimo estratto confermato il cui saldo finale è quello
     * dell'app a fine periodo. Un buco o un import annullato lo fanno arretrare, senza nulla da correggere a mano.
     * Un conflitto non deciso no: il movimento già presente ha lo stesso importo. Null se nessun estratto concorda.
     * shortcut: se un movimento con data operazione dentro un estratto compare solo in quello successivo,
     * l'estratto precedente risulta non riconciliato; da rivedere con estratti veri (#11, punto 6).
     */
    public function riconciliatoFinoAl(): ?CarbonInterface
    {
        foreach ($this->importEstratti()->where('stato', 'confermato')->orderByDesc('periodo_al')->get() as $import) {
            if ($this->saldoAl($import->periodo_al->toDateString()) === $import->saldo_finale) {
                return $import->periodo_al;
            }
        }

        return null;
    }

    /**
     * Le righe dell'estratto divise in nuove, già presenti (stessa chiave e stessi dati) e in conflitto (stessa
     * chiave, dati diversi: data valuta o causale). Con la stessa chiave la data operazione, l'importo, la
     * descrizione e il saldo parziale sono già uguali; sono presenti anche le righe con un conflitto già deciso.
     *
     * @return array{nuove: list<RigaEstratto>, presenti: list<RigaEstratto>, conflitti: list<array{riga: RigaEstratto, movimento: Movimento}>}
     */
    public function classifica(Estratto $estratto): array
    {
        $esistenti = $this->movimenti()->whereIn('chiave', array_map(fn (RigaEstratto $r) => $r->chiave(), $estratto->righe))->get()->groupBy('chiave');
        // Un conflitto già deciso «tieni il presente» non si riapre a ogni file che si sovrappone.
        $tenuti = ImportConflitto::where('decisione', 'tieni_esistente')->whereIn('movimento_id', $esistenti->flatten()->pluck('id'))->get()
            ->map(fn (ImportConflitto $c) => $c->movimento_id.'|'.RigaEstratto::daArray($c->riga)->impronta())->flip();
        $risultato = ['nuove' => [], 'presenti' => [], 'conflitti' => []];

        foreach ($estratto->righe as $riga) {
            $uguali = $esistenti->get($riga->chiave(), collect());
            if ($uguali->isEmpty()) {
                $risultato['nuove'][] = $riga;
            } elseif ($uguali->contains(fn (Movimento $m) => $this->stessaRiga($m, $riga) || $tenuti->has($m->id.'|'.$riga->impronta()))) {
                $risultato['presenti'][] = $riga;
            } else {
                $risultato['conflitti'][] = ['riga' => $riga, 'movimento' => $uguali->first()];
            }
        }

        return $risultato;
    }

    /**
     * Il movimento è già quella riga: tutto ciò che il file dice coincide. Con la chiave fatta di data, importo, descrizione e saldo
     * (Crédit Agricole) restano da guardare data valuta e causale; con una chiave che è solo un codice (PayPal, #48) anche il resto.
     */
    public function stessaRiga(Movimento $movimento, RigaEstratto $riga): bool
    {
        return $movimento->data->toDateString() === $riga->data && $movimento->importo === abs($riga->importo) && $movimento->verso === $riga->verso()
            && $movimento->descrizione === $riga->descrizione && $movimento->saldo_parziale === $riga->saldoParziale && $movimento->commissione === $riga->commissione
            && $movimento->data_valuta?->toDateString() === $riga->dataValuta && $movimento->causale === $riga->causale;
    }

    public function scopeAperti(Builder $query): void
    {
        $query->whereNull('chiuso_il');
    }

    public function chiuso(): bool
    {
        return $this->chiuso_il !== null;
    }

    public function etichettaTipo(): string
    {
        return self::TIPI[$this->tipo];
    }

    /** I campi che il registro delle modifiche conserva prima e dopo ogni cambiamento. */
    public function istantanea(): array
    {
        return [
            'tipo' => $this->tipo, 'nome' => $this->nome, 'formato_import' => $this->formato_import,
            'riga_modello_d' => $this->riga_modello_d, 'aperto_il' => $this->aperto_il?->toDateString(),
            'chiuso_il' => $this->chiuso_il?->toDateString(), 'saldo_iniziale' => $this->saldo_iniziale,
        ];
    }
}
