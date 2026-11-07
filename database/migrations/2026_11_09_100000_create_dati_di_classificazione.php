<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attività, iniziative, tipologie, tag e tabella delle voci del Modello D, con i dati iniziali di
 * docs/import-foglio/conversione-causali.csv. L'elenco è scritto per esteso e non letto dal CSV: `docs/` non
 * viene pubblicato su Aruba e lì le migrazioni partono da una pagina. Un test confronta questi dati con il CSV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attivita', function (Blueprint $table) {
            $table->id();
            $table->string('area', 1); // App\Area
            $table->string('nome');
            $table->dateTime('archiviata_il')->nullable();
            $table->timestamps();
            $table->unique(['area', 'nome']);
        });

        Schema::create('iniziative', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->dateTime('archiviata_il')->nullable();
            $table->timestamps();
        });

        // Area e attività che l'iniziativa propone ai suoi movimenti in un esercizio (anno solare).
        Schema::create('iniziativa_esercizio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iniziativa_id')->constrained('iniziative')->cascadeOnDelete();
            $table->unsignedSmallInteger('esercizio');
            $table->string('area', 1);
            $table->foreignId('attivita_id')->nullable()->constrained('attivita')->restrictOnDelete();
            $table->unique(['iniziativa_id', 'esercizio']);
        });

        Schema::create('tipologie', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->string('verso'); // entrata | uscita: non cambia dopo la creazione
            $table->text('spiegazione');
            $table->json('proprieta'); // App\Models\Tipologia::PROPRIETA
            $table->dateTime('archiviata_il')->nullable();
            $table->timestamps();
        });

        Schema::create('tag', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->dateTime('archiviata_il')->nullable();
            $table->timestamps();
        });

        // La voce Modello D di un'imputazione si ricava da qui (esercizio, area, tipologia) e non si salva sul
        // movimento. Una combinazione senza riga non è ammessa.
        Schema::create('voci_modello_d', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('esercizio');
            $table->string('area', 1);
            $table->foreignId('tipologia_id')->constrained('tipologie')->cascadeOnDelete();
            $table->string('voce'); // «A1», «E5», «Imposte»
            $table->unique(['esercizio', 'area', 'tipologia_id']);
        });

        $this->caricaDatiIniziali();
    }

    public function down(): void
    {
        Schema::dropIfExists('voci_modello_d');
        Schema::dropIfExists('tag');
        Schema::dropIfExists('tipologie');
        Schema::dropIfExists('iniziativa_esercizio');
        Schema::dropIfExists('iniziative');
        Schema::dropIfExists('attivita');
    }

    private function caricaDatiIniziali(): void
    {
        $ora = ['created_at' => now(), 'updated_at' => now()];
        // shortcut: solo l'esercizio 2026 (voci e predefiniti delle iniziative); dal 1/1/2027 nessun movimento si classifica finché
        // non si carica la tabella del nuovo esercizio (#55 e seguenti, o la pagina della tabella delle voci, storia 79).
        $esercizio = 2026;

        // Area A: le lettere dell'art. 5 CTS previste dallo statuto. Area B: lo statuto non le elenca, i nomi
        // sono quelli proposti in docs/import-foglio/README.md e accettati dal tesoriere il 3/11/2026.
        foreach (['f', 'i', 'k', 'l', 'w', 'z'] as $lettera) {
            DB::table('attivita')->insert(['area' => 'A', 'nome' => "Lettera $lettera (art. 5 CTS)"] + $ora);
        }
        foreach (['Sponsorizzazioni e pubblicità', 'Collaborazioni con esercizi commerciali', 'Vendita di beni'] as $nome) {
            DB::table('attivita')->insert(['area' => 'B', 'nome' => $nome] + $ora);
        }
        $lettera_i = DB::table('attivita')->where('nome', 'Lettera i (art. 5 CTS)')->value('id');

        // La Casa è un progetto senza attività, con le spese in area E; Si può fare non ha attività nella tabella.
        $iniziative = [
            'Palio e Corteo' => ['A', $lettera_i], 'Carnevale' => ['A', $lettera_i], 'Infiorata' => ['A', $lettera_i],
            'Festa della Torre' => ['A', $lettera_i], 'Si può fare' => ['A', null], 'Attività natalizie' => ['A', $lettera_i],
            'Aggregazione e convivialità' => ['A', $lettera_i], 'La Casa' => ['E', null],
        ];
        foreach ($iniziative as $nome => [$area, $attivita]) {
            $id = DB::table('iniziative')->insertGetId(['nome' => $nome] + $ora);
            DB::table('iniziativa_esercizio')->insert(['iniziativa_id' => $id, 'esercizio' => $esercizio, 'area' => $area, 'attivita_id' => $attivita]);
        }

        foreach (['Microprogetto', 'Gruppo CLT'] as $nome) {
            DB::table('tag')->insert(['nome' => $nome] + $ora);
        }

        // Le uscite A, B ed E hanno le stesse righe (docs/import-foglio/README.md); le entrate indicano la voce per area.
        // shortcut: l'area C (C1 o C2 secondo che la raccolta sia abituale o occasionale) non è caricata: arriva con le raccolte fondi.
        $uscita = fn (string $nome, string $spiegazione, string|int $riga) => [
            $nome, 'uscita', $spiegazione, [], is_int($riga)
                ? ['A' => "A$riga", 'B' => "B$riga", 'E' => "E$riga"]
                : ['A' => $riga, 'B' => $riga, 'E' => $riga],
        ];
        $entrata = fn (string $nome, string $spiegazione, array $voci, array $proprieta = []) => [$nome, 'entrata', $spiegazione, $proprieta, $voci];
        $commerciale = 'commerciale_per_natura';

        $tipologie = [
            $uscita('Materiali e beni di consumo', 'Materiali e beni che si consumano: cancelleria, addobbi, materie prime. Va bene anche per le spese generiche da verificare.', 1),
            $uscita('Strumenti e attrezzature', 'Strumenti e attrezzature durevoli. Sopra 516,46 euro il singolo bene è un investimento: chiedi al commercialista.', 1),
            $uscita('Acquisti per la somministrazione', 'Alimenti e bevande comprati per darli da mangiare e da bere a una festa o a una cena.', 1),
            $uscita('Materiale di comunicazione', 'Locandine, volantini, striscioni, stampe e materiale pubblicitario.', 1),
            $uscita('Libri e pubblicazioni', 'Libri, riviste e pubblicazioni acquistati.', 1),
            $uscita('Servizi professionali', 'Compensi a professionisti con partita IVA: commercialista, grafico, tecnico.', 2),
            $uscita('Servizi internet e software', 'Sito, posta, abbonamenti a programmi e servizi online.', 2),
            $uscita('Assicurazioni', 'Premi di assicurazione.', 2),
            $uscita('Utenze', 'Luce, gas, acqua, telefono.', 2),
            $uscita('Formazione dei volontari', 'Corsi e formazione per i volontari.', 2),
            $uscita('Servizi vari', 'Servizi pagati a chi li presta che non rientrano nelle altre tipologie.', 2),
            $uscita('Prestazioni occasionali', 'Compensi per un lavoro occasionale. Non è «Personale», che vale solo per il lavoro con contratto.', 2),
            $uscita('Provvigioni', 'Provvigioni, compresa la ritenuta d’acconto sul compenso.', 2),
            $uscita('Affitti e noleggi', 'Affitti di locali e noleggio di beni.', 3),
            $uscita('Rimborsi spese ai volontari', 'Rimborsi ai volontari delle spese sostenute. Mai in «Personale».', 5),
            $uscita('Quote associative ad altri enti', 'Quote di adesione pagate ad altre associazioni ed enti.', 5),
            $uscita('Erogazioni liberali ad altri enti', 'Denaro donato dall’associazione ad altri enti.', 5),
            $uscita('Spese di rappresentanza', 'Spese per ospiti e rappresentanza dell’associazione.', 5),
            $uscita('Imposte e tasse', 'Imposte e tasse pagate dall’associazione, tranne l’IVA.', 5),
            $uscita('IVA', 'IVA pagata. Nel Modello D sta nella riga «Imposte».', 'Imposte'),
            ['Commissioni e oneri bancari', 'uscita', 'Canoni e commissioni di banca, PayPal e Satispay.', [], ['D' => 'D1']],

            $entrata('Quote associative', 'Quote pagate dai soci per l’iscrizione.', ['A' => 'A1']),
            $entrata('Corrispettivi dei soci per attività', 'Somme pagate dai soci per partecipare a un’attività dell’associazione.', ['A' => 'A2']),
            $entrata('Cessioni ai soci', 'Vendita ai soci di beni nuovi prodotti per la vendita: è un’attività commerciale (art. 85 c. 3 CTS).', ['A' => 'A3', 'B' => 'B1'], [$commerciale]),
            $entrata('Somministrazione ai soci', 'Pasti e bevande dati ai soci a pagamento. Resta commerciale (art. 85 c. 3 CTS).', ['A' => 'A2', 'B' => 'B1'], [$commerciale]),
            $entrata('Erogazioni liberali', 'Donazioni ricevute, anche quelle fatte con PayPal.', ['A' => 'A4']),
            $entrata('5 per mille', 'Contributo del 5 per mille.', ['A' => 'A5']),
            $entrata('Contributi da privati', 'Contributi di aziende e privati per un’iniziativa.', ['A' => 'A6', 'B' => 'B2']),
            $entrata('Somministrazione', 'Pasti e bevande venduti al pubblico. Resta commerciale (art. 85 c. 3 CTS).', ['A' => 'A7', 'B' => 'B3'], [$commerciale]),
            $entrata('Corrispettivi da terzi', 'Somme pagate da chi non è socio per un servizio o un bene.', ['A' => 'A7', 'B' => 'B3']),
            $entrata('Contributi da enti pubblici', 'Contributi di Comune, Regione, ministeri.', ['A' => 'A8', 'B' => 'B4']),
            $entrata('Altre entrate', 'Entrate che non rientrano nelle altre tipologie.', ['A' => 'A10', 'B' => 'B6']),
            $entrata('Sponsorizzazioni e pubblicità', 'Somme pagate da sponsor per la pubblicità. È commerciale e non conta nel test dell’ente (art. 79 c. 5 CTS).', ['B' => 'B3'], [$commerciale, 'neutra_nel_test_dell_ente']),
            $entrata('Interessi attivi', 'Interessi pagati dalla banca sul conto.', ['D' => 'D1']),
        ];

        foreach ($tipologie as [$nome, $verso, $spiegazione, $proprieta, $voci]) {
            $id = DB::table('tipologie')->insertGetId(['nome' => $nome, 'verso' => $verso, 'spiegazione' => $spiegazione, 'proprieta' => json_encode($proprieta)] + $ora);
            foreach ($voci as $area => $voce) {
                DB::table('voci_modello_d')->insert(['esercizio' => $esercizio, 'area' => $area, 'tipologia_id' => $id, 'voce' => $voce]);
            }
        }
    }
};
