<?php

namespace App\Onboarding;

use App\Classificazione;
use App\Models\Assegnazione;
use App\Models\Conto;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\Ruolo;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;

/**
 * La situazione di partenza di una missione (glossario: Scenario): conti, movimenti e persone inventati, letti da
 * `resources/onboarding/scenari`. Si applica allo spazio di prova, quindi con la connessione `prova` già attiva.
 */
final class Scenario
{
    public static function dati(string $nome): array
    {
        return Yaml::parseFile(resource_path("onboarding/scenari/$nome.yml"));
    }

    /** Il file dell'estratto di prova dello scenario, se ce n'è uno. */
    public static function estratto(string $nome): ?string
    {
        $file = self::dati($nome)['estratto'] ?? null;

        return $file ? resource_path("onboarding/scenari/$file") : null;
    }

    public static function applica(User $utente, string $nome): void
    {
        $dati = self::dati($nome);

        // L'utente vero ricopiato con lo stesso id, senza token né credenziali: le chiavi esterne `user_id` valgono in prova.
        User::unguarded(fn () => User::create([
            'id' => $utente->id, 'name' => $utente->name, 'email' => $utente->email, 'esterno' => $utente->esterno,
        ]));

        $finti = [];
        foreach ($dati['utenti_finti'] ?? [] as $f) {
            $finto = User::unguarded(fn () => User::create(['name' => $f['nome'], 'email' => $f['email'], 'esterno' => false]));
            if (isset($f['ruolo'])) {
                Assegnazione::create(['user_id' => $finto->id, 'ruolo_id' => Ruolo::where('nome', $f['ruolo'])->value('id'), 'tutti_i_conti' => true]);
            }
            $finti[$f['chiave']] = $finto;
        }

        $conti = [];
        foreach ($dati['conti'] ?? [] as $c) {
            $conti[$c['chiave']] = Conto::create(Arr::except($c, ['chiave']));
        }

        foreach ($dati['movimenti'] ?? [] as $m) {
            $autore = $finti[$m['autore']];
            $movimento = Movimento::make(['conto_id' => $conti[$m['conto']]->id] + Arr::only($m, ['data', 'importo', 'verso', 'descrizione']));
            $movimento->forceFill(['stato' => 'da_classificare', 'manuale' => true, 'inserito_da' => $autore->id])->save();
            self::classifica($autore, $movimento, $m['tipologia'] ?? null, $m['iniziativa'] ?? null);
            if ($m['confermato'] ?? false) {
                Classificazione::conferma($autore, $movimento, Classificazione::versione($movimento->refresh()));
            }
        }
    }

    /**
     * «L'AI preregistrata»: propone una tipologia per i movimenti importati ancora da classificare che contengono
     * il testo indicato dallo scenario, una volta sola per movimento. In prova l'AI non si chiama mai (#21).
     */
    public static function applicaProposte(string $nome): void
    {
        $dati = self::dati($nome);
        if (empty($dati['proposte'])) {
            return;
        }
        $proponente = User::where('email', collect($dati['utenti_finti'])->firstWhere('chiave', $dati['proponente'])['email'])->first();
        $daProporre = Movimento::where('stato', 'da_classificare')->whereNotNull('import_estratto_id')
            ->whereDoesntHave('imputazioni')->whereNotExists(fn ($q) => $q->from('registro_modifiche')->whereColumn('registro_modifiche.oggetto_id', 'movimenti.id')
                ->where('oggetto_tipo', 'movimento')->where('azione', 'classificato'))->get();
        foreach ($daProporre as $movimento) {
            foreach ($dati['proposte'] as $p) {
                if (str_contains(mb_strtoupper($movimento->descrizione), mb_strtoupper($p['contiene']))) {
                    self::classifica($proponente, $movimento, $p['tipologia'], $p['iniziativa'] ?? null);
                    break;
                }
            }
        }
    }

    private static function classifica(User $utente, Movimento $movimento, ?string $tipologia, ?string $iniziativa): void
    {
        if ($tipologia || $iniziativa) {
            Classificazione::salva($utente, $movimento, null, [[
                'tipologia_id' => $tipologia ? Tipologia::where('nome', $tipologia)->value('id') : null,
                'iniziativa_id' => $iniziativa ? Iniziativa::where('nome', $iniziativa)->value('id') : null,
            ]]);
        }
    }
}
