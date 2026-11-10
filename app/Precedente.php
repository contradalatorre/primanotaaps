<?php

namespace App;

use App\Models\Attivita;
use App\Models\EsempioClassificazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\Tag;
use App\Models\Tipologia;
use Illuminate\Support\Carbon;

/**
 * Un caso da cui lo storico può imparare (#43): un movimento confermato con una sola imputazione, o un esempio senza
 * movimento. Tiene solo quello che serve a confrontarlo e a proporne la classificazione.
 */
final class Precedente
{
    /**
     * @param  array<string,true>  $parole le parole della descrizione che contano, vedi `Storico::parole`
     * @param  array{tipologia_id: int, iniziativa_id: ?int, area: string, attivita_id: ?int, tag: list<int>}  $riga la classificazione, come la vuole `Classificazione`
     */
    private function __construct(
        public readonly ?Movimento $movimento, public readonly ?EsempioClassificazione $esempio,
        public readonly string $verso, public readonly ?string $causale, public readonly Carbon $data,
        public readonly array $parole, public readonly array $riga,
    ) {}

    /** Tipologia, iniziativa, attività o tag archiviati: non si propongono più (come per le regole). */
    public function archiviato(): bool
    {
        $elementi = [Tipologia::find($this->riga['tipologia_id']), $this->riga['iniziativa_id'] ? Iniziativa::find($this->riga['iniziativa_id']) : null,
            $this->riga['attivita_id'] ? Attivita::find($this->riga['attivita_id']) : null, ...Tag::findMany($this->riga['tag'])];

        return collect($elementi)->filter()->contains(fn ($e) => $e->archiviata());
    }

    /** @param  Movimento  $movimento confermato, con le imputazioni (e i loro tag) già caricati; null se non ha una sola imputazione con tipologia */
    public static function daMovimento(Movimento $movimento): ?self
    {
        $imputazione = $movimento->imputazioni->count() === 1 ? $movimento->imputazioni->first() : null;
        if ($imputazione === null || $imputazione->tipologia_id === null) {
            return null;
        }

        return new self($movimento, null, $movimento->verso, Storico::causale($movimento->causale), $movimento->data, Storico::parole($movimento->descrizione), [
            'tipologia_id' => $imputazione->tipologia_id, 'iniziativa_id' => $imputazione->iniziativa_id, 'area' => $imputazione->area->value,
            'attivita_id' => $imputazione->attivita_id, 'tag' => $imputazione->tag->pluck('id')->sort()->values()->all(),
        ]);
    }

    public static function daEsempio(EsempioClassificazione $esempio): self
    {
        return new self(null, $esempio, $esempio->verso, Storico::causale($esempio->causale), $esempio->data, Storico::parole($esempio->descrizione), [
            'tipologia_id' => $esempio->tipologia_id, 'iniziativa_id' => $esempio->iniziativa_id, 'area' => $esempio->area,
            'attivita_id' => $esempio->attivita_id, 'tag' => [],
        ]);
    }
}
