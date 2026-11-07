<?php

namespace App\Models;

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
