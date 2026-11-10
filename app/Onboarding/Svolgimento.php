<?php

namespace App\Onboarding;

use App\Models\Avanzamento;
use App\Models\SpazioProva;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Come un utente avanza nelle missioni: avvio con lo spazio azzerato, ripresa dal passo lasciato, passi pratici
 * che si completano da soli, «Avanti» per quelli descrittivi, salto senza timbro. L'avanzamento sta nel database vero.
 */
final class Svolgimento
{
    public function __construct(private readonly Prova $prova) {}

    public function avanzamento(User $utente, Missione $missione): ?Avanzamento
    {
        return Avanzamento::where(['user_id' => $utente->id, 'missione' => $missione->id])->first();
    }

    /** Parte dal primo passo con lo spazio di prova azzerato; chi l'aveva già completata la rifà in versione rapida (il timbro resta). */
    public function avvia(User $utente, Missione $missione): Avanzamento
    {
        $this->prova->prepara($utente, $missione);

        return Avanzamento::updateOrCreate(
            ['user_id' => $utente->id, 'missione' => $missione->id],
            ['stato' => 'in_corso', 'passo' => 0, 'versione' => $missione->versione],
        );
    }

    /** Riprende dal passo lasciato, se lo spazio di prova c'è ancora ed è quello di questa missione; altrimenti riparte. */
    public function riprendi(User $utente, Missione $missione): Avanzamento
    {
        $avanzamento = $this->avanzamento($utente, $missione);
        $spazio = SpazioProva::find($utente->id);
        if ($avanzamento?->stato === 'in_corso' && $avanzamento->versione === $missione->versione && $spazio?->missione === $missione->id && $this->prova->esiste($utente->id)) {
            return $avanzamento;
        }

        return $this->avvia($utente, $missione);
    }

    /**
     * I passi pratici già fatti si saltano da soli, in base allo stato dello spazio di prova (connessione `prova` attiva).
     *
     * @return bool se la missione è finita
     */
    public function sincronizza(Missione $missione, Avanzamento $avanzamento): bool
    {
        $passo = $avanzamento->passo;
        while ($passo < count($missione->passi) && $missione->passi[$passo]->pratico() && Verifiche::passa($missione->passi[$passo])) {
            $passo++;
        }
        if ($passo !== $avanzamento->passo) {
            $avanzamento->update(['passo' => $passo]);
        }
        if ($passo >= count($missione->passi)) {
            $avanzamento->update(['stato' => 'completata', 'versione_completata' => $missione->versione, 'completata_il' => now()]);
        }

        return $avanzamento->stato === 'completata';
    }

    /** «Avanti» su un passo descrittivo; su uno pratico non fa nulla (si va avanti facendo la cosa). */
    public function avanti(Missione $missione, Avanzamento $avanzamento): bool
    {
        if ($avanzamento->passo < count($missione->passi) && ! $missione->passi[$avanzamento->passo]->pratico()) {
            $avanzamento->update(['passo' => $avanzamento->passo + 1]);
        }

        return $this->sincronizza($missione, $avanzamento);
    }

    /** Saltata non conta come completata: niente timbro, resta suggerita. */
    public function salta(Avanzamento $avanzamento): void
    {
        $avanzamento->update(['stato' => 'saltata', 'passo' => 0]);
    }

    /** I dati per Merlo nella pagina in cui l'utente si trova (connessione `prova` attiva, per l'indirizzo del passo). */
    public function guida(Missione $missione, Avanzamento $avanzamento, ?string $rotta): array
    {
        $passo = $missione->passi[$avanzamento->passo];
        $qui = $rotta === $passo->schermata;

        return [
            'missione' => $missione, 'passo' => $passo, 'numero' => $avanzamento->passo + 1, 'totale' => count($missione->passi),
            'rapida' => $avanzamento->completata(), 'qui' => $qui, 'url' => $qui ? null : Schermate::url($passo->schermata),
        ];
    }

    /** Le missioni dei permessi dell'utente non ancora completate (suggerite), nell'ordine del catalogo. @return Collection<string, Missione> */
    public function suggerite(User $utente): Collection
    {
        $fatte = Avanzamento::where('user_id', $utente->id)->whereNotNull('versione_completata')->pluck('missione');

        return Catalogo::predefinito()->missioni()->filter(fn (Missione $m) => ! $fatte->contains($m->id) && $m->suggeritaA($utente));
    }

    /** L'invito compare finché c'è una missione suggerita a cui non è stato ancora invitato; «Non ora» lo spegne per quelle. */
    public function invitoDovuto(User $utente): bool
    {
        $invitate = Avanzamento::where('user_id', $utente->id)->whereNotNull('invitato_il')->pluck('missione');

        return $this->suggerite($utente)->keys()->diff($invitate)->isNotEmpty();
    }

    public function rifiutaInvito(User $utente): void
    {
        foreach ($this->suggerite($utente) as $missione) {
            Avanzamento::firstOrCreate(['user_id' => $utente->id, 'missione' => $missione->id], ['versione' => $missione->versione])->update(['invitato_il' => now()]);
        }
    }

    /** «Cosa è cambiato» per chi aveva completato una versione precedente: si mostra una volta sola (poi `novita_vista`). */
    public function novita(Missione $missione, ?Avanzamento $avanzamento): ?string
    {
        if (! $avanzamento?->completata() || $missione->cosaECambiato === null
            || $avanzamento->versione_completata >= $missione->versione || ($avanzamento->novita_vista ?? 0) >= $missione->versione) {
            return null;
        }
        $avanzamento->update(['novita_vista' => $missione->versione]);

        return $missione->cosaECambiato;
    }
}
