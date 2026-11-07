<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Una sezione del manuale di classificazione (glossario: Manuale). Il `codice` è il suo numero, quello di
 * «manuale § 9»: lo dà l'app alla creazione e non cambia più.
 */
#[Fillable(['titolo', 'corpo'])]
class ManualeSezione extends Model
{
    protected $table = 'manuale_sezioni';

    /** Il testo è Markdown scritto da chi ha `gestire_regole`: l'HTML grezzo e i link `javascript:` non passano. */
    public function html(): string
    {
        return Str::markdown($this->corpo, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function istantanea(): array
    {
        return $this->only(['codice', 'titolo', 'corpo']);
    }

    /** Il manuale intero come testo, quello che riceverà l'AI. */
    public static function testo(): string
    {
        return static::orderBy('id')->get()->map(fn ($s) => "§ {$s->codice} {$s->titolo}\n\n{$s->corpo}")->join("\n\n");
    }
}
