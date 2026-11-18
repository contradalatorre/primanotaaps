<?php

namespace App\Http\Controllers;

use App\Area;
use App\Conversioni;
use App\Models\Attivita;
use App\Models\ConversioneCausale;
use App\Models\Iniziativa;
use App\Models\Tag;
use App\Models\Tipologia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La tabella di conversione delle vecchie causali del foglio, modificabile in una pagina (#64, #19) finché il foglio è in uso. La vede chi vede,
 * la modifica chi gestisce i dati di base (`gestire_regole`, sulle rotte); la logica, le verifiche a riga bloccata e la chiusura sono in `App\Conversioni`.
 */
class ConversioneCausaliController extends Controller
{
    public function indice()
    {
        // Le scelte archiviate non si offrono più, tranne quella che una riga ha già.
        $righe = ConversioneCausale::with('tipologia', 'attivita', 'iniziativa', 'tag')->orderBy('id')->get();
        $attive = fn ($modello, string $campo) => $modello::query()->orderBy('nome')->get()->reject(fn ($e) => $e->archiviata() && ! $righe->contains($campo, $e->id));

        return view('conversione.indice', [
            'righe' => $righe, 'chiusa' => Conversioni::chiusa(),
            'tipologie' => $attive(Tipologia::class, 'tipologia_id'), 'attivita' => $attive(Attivita::class, 'attivita_id'),
            'iniziative' => $attive(Iniziativa::class, 'iniziativa_id'), 'tag' => $attive(Tag::class, 'tag_id'),
            'daCorreggere' => Conversioni::daCorreggere()->groupBy('import_estratto_id'),
        ]);
    }

    public function salva(Request $request, ConversioneCausale $riga): RedirectResponse
    {
        [$riga, $cambiati] = Conversioni::salva($request->user(), $riga, $this->dati($request));

        return redirect()->route('conversione')->with('ok', $this->effetto($riga, $cambiati));
    }

    public function crea(Request $request): RedirectResponse
    {
        $base = $request->validate(['causale' => ['required', 'string', 'max:255'], 'verso' => ['required', Rule::in(['entrata', 'uscita'])]]);
        [$riga, $cambiati] = Conversioni::aggiungi($request->user(), $base['causale'], $base['verso'], $this->dati($request));

        return redirect()->route('conversione')->with('ok', $this->effetto($riga, $cambiati));
    }

    /** @return array{tipologia_id: ?int, area: ?string, attivita_id: ?int, iniziativa_id: ?int, tag_id: ?int} */
    private function dati(Request $request): array
    {
        $dati = $request->validate([
            'tipologia_id' => ['nullable', 'integer', 'exists:tipologie,id'], 'area' => ['nullable', Rule::enum(Area::class)],
            'attivita_id' => ['nullable', 'integer', 'exists:attivita,id'], 'iniziativa_id' => ['nullable', 'integer', 'exists:iniziative,id'], 'tag_id' => ['nullable', 'integer', 'exists:tag,id'],
        ]);

        return [
            'tipologia_id' => isset($dati['tipologia_id']) ? (int) $dati['tipologia_id'] : null, 'area' => $dati['area'] ?? null,
            'attivita_id' => isset($dati['attivita_id']) ? (int) $dati['attivita_id'] : null, 'iniziativa_id' => isset($dati['iniziativa_id']) ? (int) $dati['iniziativa_id'] : null,
            'tag_id' => isset($dati['tag_id']) ? (int) $dati['tag_id'] : null,
        ];
    }

    private function effetto(ConversioneCausale $riga, int $cambiati): string
    {
        $daCorreggere = Conversioni::daCorreggere()->where('conversione_causale_id', $riga->id)->count();

        return 'Riga «'.$riga->causale.'» salvata. '
            .($cambiati === 0 ? 'Nessuna proposta è cambiata.' : ($cambiati === 1 ? 'Una proposta è cambiata.' : "$cambiati proposte sono cambiate.")).' '
            .($daCorreggere === 0 ? '' : ($daCorreggere === 1 ? 'Un movimento già confermato è da correggere (elenco in fondo).' : "$daCorreggere movimenti già confermati sono da correggere (elenco in fondo)."));
    }
}
