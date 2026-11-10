<?php

namespace App\Models;

use App\Models\Concerns\NelDatabaseVero;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un tentativo di backup (#68): mensile o su richiesta, col suo esito e il file sul drive. Sempre nel database vero (ADR 0003). */
class Backup extends Model
{
    use NelDatabaseVero;

    protected $table = 'backup';

    public $timestamps = false;

    protected $guarded = [];

    public const TIPI = ['mensile' => 'Mensile', 'su_richiesta' => 'Su richiesta'];

    protected function casts(): array
    {
        return ['avviato_il' => 'datetime', 'concluso_il' => 'datetime', 'eliminato_il' => 'datetime', 'dimensione' => 'integer'];
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** I riusciti il cui file è ancora sul drive: quelli su cui lavora la rotazione. */
    public function scopeSulDrive(Builder $query): void
    {
        $query->where('esito', 'riuscito')->whereNotNull('drive_id')->whereNull('eliminato_il');
    }
}
