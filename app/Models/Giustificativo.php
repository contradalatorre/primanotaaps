<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Il documento che prova un movimento: un riferimento testuale e, se c'è, il link al file sul Drive. */
#[Fillable(['riferimento', 'link'])]
class Giustificativo extends Model
{
    protected $table = 'giustificativi';

    public function movimento(): BelongsTo
    {
        return $this->belongsTo(Movimento::class);
    }
}
