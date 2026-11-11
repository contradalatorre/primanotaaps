<?php

namespace App\Models;

use App\Import\CreditAgricoleCsv;
use App\Import\Estratto;
use App\Import\FormatoImport;
use App\Import\RigaEstratto;
use App\Import\SatispayCsv;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Un caricamento di estratto conto. In anteprima conserva solo le righe lette (mai il file caricato); dopo la
 * conferma i suoi movimenti sono in `movimenti.import_estratto_id`. «Annullamento» si dice solo di un import.
 */
class ImportEstratto extends Model
{
    protected $table = 'import_estratti';

    protected $guarded = [];

    public const STATI = ['anteprima' => 'In anteprima', 'confermato' => 'Confermato', 'annullato' => 'Annullato'];

    /** Un modulo per formato (lettura, chiave contro i doppioni e regole dei giroconti). Un formato nuovo si registra qui, su una riga sua. */
    public const LETTORI = [
        'credit_agricole_csv' => CreditAgricoleCsv::class,
        'satispay_csv' => SatispayCsv::class,
    ];

    protected function casts(): array
    {
        return [
            'periodo_dal' => 'date', 'periodo_al' => 'date', 'saldo_iniziale' => 'integer', 'saldo_finale' => 'integer',
            'confermato_il' => 'datetime', 'annullato_il' => 'datetime',
        ];
    }

    public static function lettorePer(Conto $conto): ?FormatoImport
    {
        $classe = self::LETTORI[$conto->formato_import] ?? null;

        return $classe ? app($classe) : null; // dal contenitore: i test sostituiscono il modulo
    }

    public function conto(): BelongsTo
    {
        return $this->belongsTo(Conto::class);
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function movimenti(): HasMany
    {
        return $this->hasMany(Movimento::class, 'import_estratto_id');
    }

    public function conflitti(): HasMany
    {
        return $this->hasMany(ImportConflitto::class);
    }

    public function estratto(): Estratto
    {
        return Estratto::daJson(Storage::disk('local')->get($this->percorso));
    }

    /** Si annulla per intero, e solo se nessuno dei suoi movimenti è confermato. */
    public function annullabile(): bool
    {
        return $this->stato === 'confermato' && ! $this->movimenti()->where('stato', 'confermato')->exists();
    }

    /** I campi di un movimento che nasce da una riga dell'estratto: da classificare, non manuale. */
    public function attributiMovimento(RigaEstratto $riga, int $autoreId): array
    {
        return [
            'conto_id' => $this->conto_id, 'data' => $riga->data, 'importo' => abs($riga->importo), 'verso' => $riga->verso(),
            'descrizione' => $riga->descrizione, 'stato' => 'da_classificare', 'manuale' => false, 'inserito_da' => $autoreId,
            'import_estratto_id' => $this->id, 'data_valuta' => $riga->dataValuta, 'causale' => $riga->causale,
            'saldo_parziale' => $riga->saldoParziale, 'chiave' => $riga->chiave(), 'commissione' => $riga->commissione,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    public function istantanea(): array
    {
        return $this->only(['conto_id', 'stato', 'saldo_iniziale', 'saldo_finale', 'righe']) + [
            'periodo_dal' => $this->periodo_dal->toDateString(), 'periodo_al' => $this->periodo_al->toDateString(),
        ];
    }
}
