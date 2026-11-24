<?php

namespace App;

use App\Models\ContaDiCassa;
use App\Models\Conto;
use App\Models\DatiEsercizio;
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

            if ($differenza !== 0 && $correggi) {
                DatiEsercizio::controllaNonDepositato([(int) substr($data, 0, 4)], 'data'); // la correzione è un movimento: non entra in un esercizio depositato (#60)
            }
            $correzione = $differenza !== 0 && $correggi ? self::correggi($utente, $conto, $data, $differenza, $contato, $saldo) : null;
            $conta = ContaDiCassa::create([
                // Gli id crescono: ogni movimento scritto dopo (il conto è bloccato) ha un id più alto, e non fa parte di quel che si è contato.
                'ultimo_movimento_id' => Movimento::withTrashed()->max('id') ?? 0,
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

    /**
     * Una cassa si chiude dopo una conta e vuota, con il versamento come giroconto e nessun movimento da classificare (#50). Non scrive niente: la metà in banca
     * arriva dall'estratto, e «Registra e conferma» (#47) scrive quella sulla cassa finché è aperta. Da chiamare a conto bloccato.
     *
     * @throws ValidationException sotto `conto`
     */
    public static function controllaChiusura(Conto $conto, string $chiusaIl): void
    {
        $conta = $conto->contaRiconciliata();
        $errore = match (true) {
            $conta === null => 'Prima di chiudere la cassa serve una conta di cassa senza differenze: fai la conta dei contanti (e correggi o riconta se c’è una differenza).',
            $chiusaIl < $conta->data->toDateString() => 'La cassa non si chiude prima dell’ultima conta ('.$conta->data->format('d/m/Y').').',
            // Quel che si è versato dopo la conta si abbina come giroconto: una spesa o un incasso nuovi vogliono una nuova conta.
            $conto->movimenti()->senzaGiroconto()->where(fn ($m) => $m->where('data', '>', $conta->data->toDateString())
                ->orWhere(fn ($oggi) => $oggi->where('data', $conta->data->toDateString())->where('id', '>', $conta->ultimo_movimento_id)))->exists() => 'Dopo la conta del '.$conta->data->format('d/m/Y').' ci sono movimenti che non sono giroconti. Un versamento in banca si abbina come giroconto (nel passo Giroconti, quando arriva l’estratto); una spesa o un incasso nuovi vogliono una nuova conta.',
            // Alla chiusura ogni movimento è confermato o metà di giroconto: i contanti rimasti escono solo come giroconto, un azzeramento non viene da una correzione né da un'uscita a mano mai classificata.
            $conto->movimenti()->senzaGiroconto()->where('stato', '!=', 'confermato')->exists() => 'Nella cassa ci sono movimenti ancora da classificare o da confermare (una correzione o un versamento scritto a mano, per esempio): i contanti rimasti si versano con un giroconto, una perdita si registra come uscita classificata e confermata. Chiudi dopo.',
            ($saldo = $conto->saldoAl($chiusaIl)) !== 0 => 'Nella cassa restano '.Euro::formatta($saldo).' €: versali (il versamento è un giroconto, si abbina nel passo Giroconti) prima di chiuderla.',
            default => null,
        };
        if ($errore !== null) {
            throw ValidationException::withMessages(['conto' => $errore]);
        }
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
