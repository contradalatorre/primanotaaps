<?php

namespace App;

use App\Models\EsempioClassificazione;
use App\Models\Movimento;
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

    /** @param  Movimento  $movimento confermato, con le imputazioni (e i loro tag) già caricati; null se non ha una sola imputazione con tipologia */
    public static function daMovimento(Movimento $movimento): ?self
    {
        $imputazione = $movimento->imputazioni->count() === 1 ? $movimento->imputazioni->first() : null;
        if ($imputazione === null || $imputazione->tipologia_id === null) {
            return null;
        }

        return new self($movimento, null, $movimento->verso, Storico::causale($movimento->causale), $movimento->data, Storico::parole($movimento->descrizione), [
            'tipologia_id' => $imputazione->tipologia_id, 'iniziativa_id' => $imputazione->iniziativa_id, 'area' => $imputazione->area->value,
            'attivita_id' => $imputazione->attivita_id, 'tag' => $imputazione->tag->modelKeys(),
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
