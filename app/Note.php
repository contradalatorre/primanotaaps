<?php

namespace App;

use App\Models\Menzione;
use App\Models\Movimento;
use App\Models\Nota;
use App\Models\RegistroModifica;
use App\Models\Ruolo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Scrivere, correggere, cancellare e oscurare note, chiudere i dubbi e segnare viste le menzioni (#45).
 * Ogni scrittura è una transazione che prima blocca le righe, sempre nello stesso ordine (oggetto, nota, menzione),
 * e solo dopo legge e ricontrolla chi può: su MySQL (REPEATABLE READ) una lettura semplice fatta prima del lock
 * fotografa dati che nel frattempo sono cambiati (una nota oscurata, un ruolo tolto, un movimento confermato).
 */
final class Note
{
    /**
     * Scrive una nota sul movimento, anche fuori dall'ambito dell'utente (#16: l'ambito limita le scritture sui
     * dati, non le note). Chi era chiamato in causa da una nota precedente e risponde la chiude.
     *
     * shortcut: «aggiunta dopo l'approvazione/il deposito» (#16) manca perché gli esercizi arrivano con #55 e
     * successivi; lì la nota deve portare la fase in cui è stata scritta.
     */
    public static function scrivi(User $utente, Movimento $movimento, string $testo, bool $dubbio): Nota
    {
        return DB::transaction(function () use ($utente, $movimento, $testo, $dubbio) {
            Classificazione::blocca($movimento);
            self::controllaPuoScrivere($utente);

            $nota = $movimento->note()->create(['user_id' => $utente->id, 'testo' => $testo, 'dubbio' => $dubbio]);
            self::sincronizzaMenzioni($nota);
            // Chi risponde chiude le menzioni a lui rivolte sullo stesso movimento (non quella appena scritta, che non lo cita).
            Menzione::whereNull('vista_il')->rivolteA($utente)
                ->whereIn('nota_id', $movimento->note()->where('user_id', '!=', $utente->id)->select('id'))->update(['vista_da' => $utente->id, 'vista_il' => now()]);
            RegistroModifica::registra($utente, $nota, 'scritta', null, $nota->istantanea());

            return $nota;
        });
    }

    /** Solo l'autore, entro 24 ore: altrimenti 403 (`abort`). */
    public static function modifica(User $utente, Nota $nota, string $testo): void
    {
        DB::transaction(function () use ($utente, $nota, $testo) {
            self::blocca($nota);
            self::controllaAutore($utente, $nota);
            $prima = $nota->istantanea();
            $nota->forceFill(['testo' => $testo, 'modificata_il' => now()])->save();
            self::sincronizzaMenzioni($nota);
            RegistroModifica::registra($utente, $nota, 'modificata', $prima, $nota->istantanea());
        });
    }

    /** L'autore cancella la propria nota: resta il segno «nota eliminata da X», il testo resta nel registro. */
    public static function elimina(User $utente, Nota $nota): void
    {
        DB::transaction(function () use ($utente, $nota) {
            self::blocca($nota);
            self::controllaAutore($utente, $nota);
            $prima = $nota->istantanea();
            $nota->forceFill(['eliminata_il' => now(), 'eliminata_da' => $utente->id])->save();
            RegistroModifica::registra($utente, $nota, 'eliminata', $prima, null);
        });
    }

    /**
     * L'Amministratore toglie dalla vista il testo di una nota, con motivazione (es. dati personali). Un dubbio
     * oscurato resta aperto: chi lo ha segnato voleva una verifica, e l'oscuramento riguarda il testo.
     */
    public static function oscura(User $utente, Nota $nota, string $motivazione): void
    {
        DB::transaction(function () use ($utente, $nota, $motivazione) {
            self::blocca($nota);
            self::controllaPermesso($utente, Permesso::OscurareNota);
            abort_if($nota->eliminata(), 404);
            if ($nota->oscurata()) {
                return; // già fatto, magari da un altro Amministratore un attimo prima
            }
            $prima = $nota->istantanea();
            $nota->forceFill(['oscurata_il' => now(), 'oscurata_da' => $utente->id, 'motivazione_oscuramento' => $motivazione])->save();
            RegistroModifica::registra($utente, $nota, 'oscurata', $prima, $nota->istantanea(), $motivazione);
        });
    }

    /**
     * Chiude i dubbi aperti del movimento. Da chiamare a riga del movimento già bloccata: lo fa la conferma
     * (`Classificazione::conferma`), dopo i suoi controlli.
     *
     * @return int quanti ne ha chiusi
     */
    public static function chiudiDubbi(User $utente, Movimento $movimento): int
    {
        $aperti = $movimento->note()->dubbioAperto()->lockForUpdate()->get();
        foreach ($aperti as $nota) {
            $prima = $nota->istantanea();
            $nota->forceFill(['chiuso_il' => now(), 'chiuso_da' => $utente->id])->save();
            RegistroModifica::registra($utente, $nota, 'dubbio_chiuso', $prima, $nota->istantanea());
        }

        return $aperti->count();
    }

    /** Il ? della revisione su un movimento già in dubbio: lo toglie, senza confermare. */
    public static function togliDubbio(User $utente, Movimento $movimento): void
    {
        DB::transaction(function () use ($utente, $movimento) {
            Classificazione::blocca($movimento);
            self::controllaPermesso($utente, Permesso::ImportareEConfermare);
            self::chiudiDubbi($utente, $movimento);
        });
    }

    /** «Fatto»: la menzione è vista. Per un ruolo basta una persona; se un'altra l'ha già vista non si cambia chi. */
    public static function segnaVista(User $utente, Menzione $menzione): void
    {
        $nota = $menzione->nota; // letta prima della transazione: serve solo a sapere quali righe bloccare
        DB::transaction(function () use ($utente, $menzione, $nota) {
            self::blocca($nota, $menzione);
            $menzione->refresh();
            self::controllaPuoScrivere($utente);
            abort_unless(Menzione::whereKey($menzione->id)->rivolteA($utente)->exists() && ! $nota->eliminata() && ! $nota->oscurata(), 403);
            if ($menzione->vista_il === null) {
                $menzione->forceFill(['vista_da' => $utente->id, 'vista_il' => now()])->save();
            }
        });
    }

    /**
     * Rifà l'elenco delle menzioni di una nota dal suo testo: chiama in causa gli utenti attivi che leggono le note
     * (e non l'autore) e i ruoli. Un nome che non c'è tra loro (un utente senza più assegnazioni, un esterno scaduto)
     * resta testo e non crea niente. Le menzioni già viste restano viste.
     */
    private static function sincronizzaMenzioni(Nota $nota): void
    {
        $nomi = [];
        foreach (User::where('emergenza', false)->where('id', '!=', $nota->user_id)->get() as $utente) {
            if ($utente->puo(Permesso::Vedere) && $utente->puo(Permesso::Note)) { // come chi può scrivere: leggere le note segue la visibilità (#16)
                $nomi['u'.$utente->id] = $utente->name;
            }
        }
        // Un ruolo senza `note` non ha nessuno che legga: la menzione resterebbe «da vedere» per sempre.
        foreach (Ruolo::all()->filter->haPermesso(Permesso::Note) as $ruolo) {
            $nomi['r'.$ruolo->id] = $ruolo->nome;
        }
        $citati = Menzioni::trova($nota->testo, $nomi);

        foreach ($nota->menzioni()->get() as $esistente) {
            $chiave = $esistente->user_id ? 'u'.$esistente->user_id : 'r'.$esistente->ruolo_id;
            if (in_array($chiave, $citati, true)) {
                $citati = array_diff($citati, [$chiave]);
            } else {
                $esistente->delete();
            }
        }
        foreach ($citati as $chiave) {
            $nota->menzioni()->create([$chiave[0] === 'u' ? 'user_id' : 'ruolo_id' => (int) substr($chiave, 1)]);
        }
    }

    /** Blocca nell'ordine fisso, oggetto, nota, menzione, e solo dopo rilegge la nota: un oggetto eliminato nel frattempo dà 404. */
    private static function blocca(Nota $nota, ?Menzione $menzione = null): void
    {
        $nota->oggetto()->lockForUpdate()->firstOrFail();
        Nota::whereKey($nota->id)->lockForUpdate()->firstOrFail();
        if ($menzione) {
            Menzione::whereKey($menzione->id)->lockForUpdate()->firstOrFail();
        }
        $nota->refresh();
    }

    private static function controllaAutore(User $utente, Nota $nota): void
    {
        self::controllaPuoScrivere($utente);
        abort_unless($nota->modificabileDa($utente), 403);
    }

    private static function controllaPuoScrivere(User $utente): void
    {
        self::controllaPermesso($utente, Permesso::Vedere);
        self::controllaPermesso($utente, Permesso::Note);
    }

    /** I permessi si rileggono qui, a riga bloccata: quelli caricati dal controllo della rotta possono essere di prima. */
    private static function controllaPermesso(User $utente, Permesso $permesso): void
    {
        $utente->unsetRelation('assegnazioniAttive');
        abort_unless($utente->puo($permesso), 403);
    }
}
