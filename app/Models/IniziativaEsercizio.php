<?php

namespace App\Models;

use App\Area;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Area e attività predefinite di un'iniziativa per un esercizio. */
class IniziativaEsercizio extends Model
{
    protected $table = 'iniziativa_esercizio';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['area' => Area::class];
    }

    public function attivita(): BelongsTo
    {
        return $this->belongsTo(Attivita::class);
    }
}
