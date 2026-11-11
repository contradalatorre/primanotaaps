<?php

namespace App\Models;

use App\Models\Concerns\Archiviabile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** La natura di un'imputazione, indipendente dall'area (glossario: Tipologia). */
#[Fillable(['nome', 'spiegazione', 'proprieta'])]
class Tipologia extends Model
{
    use Archiviabile;

    protected $table = 'tipologie';

    public const VERSI = ['entrata' => 'Entrata', 'uscita' => 'Uscita'];

    /** Dove vanno le commissioni di PayPal e Satispay sugli incassi (area D, voce D1): la imputa l'app, per nome: per questo la pagina Classificazione non la lascia rinominare né cancellare. */
    public const COMMISSIONI = 'Commissioni e oneri bancari';

    /** Le proprietà fiscali che il calcolo dell'esercizio leggerà (test CTS): per ora si caricano e si mostrano. */
    public const PROPRIETA = [
        'commerciale_per_natura' => 'Commerciale per natura',
        'neutra_nel_test_dell_ente' => 'Neutra nel test dell’ente',
    ];

    protected function casts(): array
    {
        return ['proprieta' => 'array'];
    }

    public function usata(): bool
    {
        return Imputazione::where('tipologia_id', $this->id)->exists()
            || Regola::where('tipologia_id', $this->id)->exists() || EsempioClassificazione::where('tipologia_id', $this->id)->exists();
    }
}
