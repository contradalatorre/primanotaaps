<?php

namespace App\Models;

use App\Models\Concerns\NelDatabaseVero;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Una riga del registro degli errori (#68; da non confondere con i registri delle modifiche e degli accessi). Sempre nel database vero (ADR 0003). */
class Errore extends Model
{
    use NelDatabaseVero;

    protected $table = 'registro_errori';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['grave' => 'boolean', 'volte' => 'integer', 'primo_il' => 'datetime', 'ultimo_il' => 'datetime', 'avvisato_il' => 'datetime', 'risolto_il' => 'datetime'];
    }

    public function scopeAperti(Builder $query): void
    {
        $query->whereNull('risolto_il');
    }
}
