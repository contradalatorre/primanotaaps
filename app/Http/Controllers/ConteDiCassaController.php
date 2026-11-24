<?php

namespace App\Http\Controllers;

use App\ConteDiCassa;
use App\Euro;
use App\Models\Conto;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La conta di cassa e la sua storia (#50). Leggere è per tutti (`vedere`, come i movimenti); contare è del Tesoriere
 * (`importare_e_confermare`) sulle casse del suo ambito: il controllo che vale si rifà a conto bloccato in `ConteDiCassa`.
 */
class ConteDiCassaController extends Controller
{
    public function index(Conto $conto)
    {
        abort_unless($conto->tipo === 'cassa', 404);

        return view('conte.indice', [
            'conto' => $conto,
            'saldo' => $conto->saldoAl(today()->toDateString()),
            'conte' => $conto->conte()->with('contataDa', 'contataCon', 'correzione')->orderByDesc('data')->orderByDesc('id')->get()->each->setRelation('conto', $conto),
            'persone' => User::where('emergenza', false)->where('id', '!=', auth()->id())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function registra(Request $request, Conto $conto): RedirectResponse
    {
        $dati = $request->validate([
            'data' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$conto->aperto_il->toDateString(), 'before_or_equal:today'],
            'importo' => ['required', 'string', function ($campo, $valore, $fallisci) {
                $centesimi = Euro::daTesto($valore);
                if ($centesimi === null || $centesimi < 0) {
                    $fallisci('Scrivi i contanti contati in euro, senza segno e con al più due decimali (es. 120,50).');
                }
            }],
            'spiegazione' => ['nullable', 'string', 'max:2000'],
            'contata_con' => ['nullable', 'integer', Rule::exists('users', 'id')->where('emergenza', false), fn ($campo, $valore, $fallisci) => (int) $valore === $request->user()->id
                && $fallisci('La conta congiunta ha due nomi diversi.')],
        ], attributes: ['importo' => 'importo contato', 'contata_con' => 'secondo nome']);

        ConteDiCassa::registra($request->user(), $conto->id, $dati['data'], Euro::daTesto($dati['importo']), $request->boolean('correggi'), $dati['spiegazione'] ?? null, $dati['contata_con'] ?? null);

        return redirect()->route('conti.conte', $conto)->with('ok', 'Conta registrata.');
    }
}
