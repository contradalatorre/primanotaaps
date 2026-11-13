<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una riga del preventivo di un'iniziativa per un esercizio: tipologia e importo previsto, in centesimi (il verso è quello della tipologia). */
class PreventivoIniziativa extends Model
{
    protected $table = 'preventivi_iniziativa';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['importo' => 'integer'];
    }

    public function tipologia(): BelongsTo
    {
        return $this->belongsTo(Tipologia::class);
    }
}
