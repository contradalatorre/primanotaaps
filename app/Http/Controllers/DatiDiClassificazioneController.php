<?php

namespace App\Http\Controllers;

use App\Area;
use App\Models\Attivita;
use App\Models\Iniziativa;
use App\Models\RegistroModifica;
use App\Models\Tag;
use App\Models\Tipologia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tipologie, attività, iniziative e tag: si leggono con `vedere`, si scrivono con `gestire_regole` (#16). Un solo
 * controllore perché le quattro cose si trattano allo stesso modo: si creano, si rinominano, si archiviano e si
 * cancellano solo se mai usate. Il tipo sta nell'indirizzo.
 */
class DatiDiClassificazioneController extends Controller
{
    private const TIPI = ['tipologie' => Tipologia::class, 'attivita' => Attivita::class, 'iniziative' => Iniziativa::class, 'tag' => Tag::class];

    public function index(Request $request)
    {
        $esercizio = $request->integer('esercizio') ?: today()->year;

        return view('classificazione.indice', [
            'esercizio' => $esercizio,
            'tipologie' => Tipologia::orderBy('verso')->orderBy('nome')->get(),
            'attivita' => Attivita::orderBy('area')->orderBy('nome')->get(),
            'iniziative' => Iniziativa::with('predefiniti.attivita')->orderBy('nome')->get(),
            'tag' => Tag::orderBy('nome')->get(),
            'voci' => DB::table('voci_modello_d')->where('esercizio', $esercizio)->get()->groupBy('tipologia_id'),
        ]);
    }

    public function crea(Request $request, string $tipo): RedirectResponse
    {
        $dati = $request->validate($this->regole($tipo, null));
        $elemento = new (self::TIPI[$tipo])($dati);
        if ($tipo === 'tipologie') {
            $elemento->verso = $dati['verso'];
            $elemento->proprieta = $dati['proprieta'] ?? [];
        }

        DB::transaction(function () use ($request, $tipo, $dati, $elemento) {
            $elemento->save();
            $this->impostaPredefiniti($tipo, $elemento, $dati);
            RegistroModifica::registra($request->user(), $elemento, 'creato', null, $elemento->istantanea());
        });

        return redirect()->route('classificazione')->with('ok', 'Aggiunto.');
    }

    public function salva(Request $request, string $tipo, int $id): RedirectResponse
    {
        $elemento = $this->trova($tipo, $id);
        $dati = $request->validate($this->regole($tipo, $elemento));

        DB::transaction(function () use ($request, $tipo, $dati, $elemento) {
            $prima = $elemento->istantanea();
            $elemento->fill($dati); // il verso di una tipologia non si cambia: non è fillable
            if ($tipo === 'tipologie') {
                $elemento->proprieta = $dati['proprieta'] ?? [];
            }
            $elemento->save();
            $this->impostaPredefiniti($tipo, $elemento, $dati);
            RegistroModifica::registra($request->user(), $elemento, 'modificato', $prima, $elemento->istantanea());
        });

        return redirect()->route('classificazione')->with('ok', 'Salvato.');
    }

    public function archivia(Request $request, string $tipo, int $id): RedirectResponse
    {
        $elemento = $this->trova($tipo, $id);

        DB::transaction(function () use ($request, $elemento) {
            $prima = $elemento->istantanea();
            $elemento->forceFill(['archiviata_il' => $elemento->archiviata() ? null : now()])->save();
            RegistroModifica::registra($request->user(), $elemento, $elemento->archiviata() ? 'archiviato' : 'ripristinato', $prima, $elemento->istantanea());
        });

        return redirect()->route('classificazione')->with('ok', $elemento->archiviata() ? 'Archiviato: non si sceglie più, ma resta dove è già stato usato.' : 'Ripristinato.');
    }

    public function elimina(Request $request, string $tipo, int $id): RedirectResponse
    {
        $elemento = $this->trova($tipo, $id);
        if ($elemento->usata()) {
            throw ValidationException::withMessages(['elimina' => '«'.$elemento->nome.'» è già stato usato: non si cancella, si archivia.']);
        }

        DB::transaction(function () use ($request, $elemento) {
            $prima = $elemento->istantanea();
            $elemento->delete();
            RegistroModifica::registra($request->user(), $elemento, 'eliminato', $prima, null);
        });

        return redirect()->route('classificazione')->with('ok', 'Eliminato.');
    }

    private function trova(string $tipo, int $id): Model
    {
        return self::TIPI[$tipo]::findOrFail($id);
    }

    private function regole(string $tipo, ?Model $esistente): array
    {
        $nome = ['required', 'string', 'max:255', Rule::unique((new (self::TIPI[$tipo]))->getTable(), 'nome')->ignore($esistente?->id)];
        $aree = Rule::in(array_column(Area::cases(), 'value'));

        return match ($tipo) {
            'tag' => ['nome' => $nome],
            'attivita' => ['nome' => $nome] + ($esistente ? [] : ['area' => ['required', $aree]]),
            'tipologie' => ['nome' => $nome, 'spiegazione' => ['required', 'string', 'max:2000'],
                'proprieta' => ['nullable', 'array'], 'proprieta.*' => [Rule::in(array_keys(Tipologia::PROPRIETA))],
            ] + ($esistente ? [] : ['verso' => ['required', Rule::in(array_keys(Tipologia::VERSI))]]),
            'iniziative' => ['nome' => $nome, 'esercizio' => ['required', 'integer', 'between:2000,2100'], 'area' => ['nullable', $aree],
                'attivita_id' => ['nullable', Rule::exists('attivita', 'id')->where(fn ($q) => $q->where('area', request('area')))],
            ],
        };
    }

    /** Area e attività che l'iniziativa propone ai movimenti dell'esercizio; l'area vuota toglie il predefinito. */
    private function impostaPredefiniti(string $tipo, Model $elemento, array $dati): void
    {
        if ($tipo !== 'iniziative') {
            return;
        }
        $elemento->predefiniti()->where('esercizio', $dati['esercizio'])->delete();
        if (! empty($dati['area'])) {
            $elemento->predefiniti()->create(['esercizio' => $dati['esercizio'], 'area' => $dati['area'], 'attivita_id' => $dati['attivita_id'] ?? null]);
        }
    }
}
