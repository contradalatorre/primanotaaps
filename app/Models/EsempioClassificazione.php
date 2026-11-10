<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un caso di classificazione senza movimento (#43): lo storico lo usa come un movimento confermato. Serve agli
 * esempi del foglio 2025 (#63), che non diventano movimenti.
 */
class EsempioClassificazione extends Model
{
    protected $table = 'esempi_classificazione';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }
}
