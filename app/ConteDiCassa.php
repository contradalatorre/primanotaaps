<?php

namespace App;

use App\Models\Conto;
use App\Models\ContaDiCassa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La conta di cassa (#50; glossario: Conta di cassa). Il Tesoriere conta i contanti e l'app li confronta con il suo saldo:
 * la differenza si spiega con un movimento di correzione (e allora la cassa quadra) o con una nota (e allora no).
 * L'ultima conta senza differenze è la data di riconciliazione della cassa (`Conto::riconciliatoFinoAl`).
 */
final class ConteDiCassa
{
    /**
     * Registra la conta, a conto bloccato: il saldo si legge dopo il blocco (un movimento in arrivo si mette in fila)
     * e il permesso si rilegge da zero (una revoca arrivata nel frattempo vale).
     *
     * @param  ?int  $contataCon  il secondo nome di una conta congiunta
     *
     * @throws ValidationException
     */
    public static function registra(User $utente, int $contoId, string $data, int $contato, bool $correggi, ?string $spiegazione, ?int $contataCon): ContaDiCassa
    {
        return DB::transaction(function () use ($utente, $contoId, $data, $contato, $correggi, $spiegazione, $contataCon) {
            $conto = Conto::lockForUpdate()->findOrFail($contoId);
            $utente->unsetRelation('assegnazioniAttive');
            abort_unless($utente->puo(Permesso::ImportareEConfermare) && $utente->puo(Permesso::InserireMovimentiCassa, $conto->id), 403);
            if ($conto->tipo !== 'cassa' || $conto->chiuso()) {
                throw ValidationException::withMessages(['conto' => 'Si contano i contanti di una cassa aperta.']);
            }
            if ($data < $conto->aperto_il->toDateString() || $data > today()->toDateString()) {
                throw ValidationException::withMessages(['data' => 'La conta non può essere prima dell’apertura della cassa ('.$conto->aperto_il->format('d/m/Y').') né nel futuro.']);
            }

            $saldo = $conto->saldoAl($data);
            $differenza = $contato - $saldo;
            $spiegazione = filled($spiegazione) ? trim($spiegazione) : null;
            if ($differenza !== 0 && ! $correggi && $spiegazione === null) {
                throw ValidationException::withMessages(['spiegazione' => 'I contanti contati ('.Euro::formatta($contato).' €) non sono il saldo dell’app ('.Euro::formatta($saldo).' €): c’è una differenza di '
                    .Euro::formatta(abs($differenza)).' € '.($differenza > 0 ? 'in più' : 'in meno').'. Spiegala con una nota o scegli di correggerla con un movimento.']);
            }

            $correzione = $differenza !== 0 && $correggi ? self::correggi($utente, $conto, $data, $differenza, $contato, $saldo) : null;
            $conta = ContaDiCassa::create([
                'conto_id' => $conto->id, 'data' => $data, 'importo_contato' => $contato, 'saldo_app' => $saldo, 'differenza' => $differenza,
                'correzione_id' => $correzione?->id, 'spiegazione' => $spiegazione, 'contata_da' => $utente->id, 'contata_con' => $contataCon,
            ]);
            RegistroModifica::registra($utente, $conto, 'conta', null, [
                'data' => $data, 'importo_contato' => $contato, 'saldo_app' => $saldo, 'differenza' => $differenza,
                'correzione_id' => $correzione?->id, 'spiegazione' => $spiegazione, 'contata_con' => $contataCon,
            ]);

            return $conta;
        });
    }

    /** Il movimento che porta il saldo dell'app ai contanti contati: da classificare, come ogni movimento scritto a mano. */
    private static function correggi(User $utente, Conto $conto, string $data, int $differenza, int $contato, int $saldo): Movimento
    {
        $quando = date('d/m/Y', strtotime($data));
        $correzione = (new Movimento)->forceFill([
            'conto_id' => $conto->id, 'data' => $data, 'importo' => abs($differenza), 'verso' => $differenza > 0 ? 'entrata' : 'uscita',
            'descrizione' => 'Correzione dopo la conta di cassa del '.$quando.' (contati '.Euro::formatta($contato).' €, saldo dell’app '.Euro::formatta($saldo).' €)',
            'stato' => 'da_classificare', 'manuale' => true, 'inserito_da' => $utente->id,
        ]);
        $correzione->save();
        RegistroModifica::registra($utente, $correzione, 'inserito', null, $correzione->istantanea(), 'Correzione dopo la conta di cassa del '.$quando);

        return $correzione;
    }
}
