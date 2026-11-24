<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le raccolte fondi occasionali (#61, #18; art. 87 c. 6 e art. 48 c. 3 CTS, DM 9/6/2022 Allegato 1): il tipo di raccolta dell'attività (abituale o occasionale: da
 * lì l'imputazione in area C ha la voce C1 o C2), la tabella tipologia → riga dello schema delle raccolte (per esercizio, come `voci_modello_d`), i dati della raccolta
 * con la sua relazione illustrativa e le liberalità non monetarie inserite a mano. Più i dati iniziali: le due attività di raccolta, le voci C delle tipologie e la
 * proprietà «Liberalità» di «Erogazioni liberali».
 */
return new class extends Migration
{
    /** Tipologia → riga dello schema delle raccolte (`App\Esercizio\Raccolta`). Quelle che non compaiono qui non hanno voce in area C. */
    private const RIGHE = [
        'Erogazioni liberali' => 'liberalita_monetarie', 'Contributi da privati' => 'liberalita_monetarie',
        'Corrispettivi da terzi' => 'altri_proventi', 'Somministrazione' => 'altri_proventi', 'Altre entrate' => 'altri_proventi',
        'Materiali e beni di consumo' => 'acquisto_beni', 'Strumenti e attrezzature' => 'acquisto_beni', 'Acquisti per la somministrazione' => 'acquisto_beni', 'Libri e pubblicazioni' => 'acquisto_beni',
        'Servizi professionali' => 'acquisto_servizi', 'Servizi internet e software' => 'acquisto_servizi', 'Assicurazioni' => 'acquisto_servizi', 'Utenze' => 'acquisto_servizi', 'Servizi vari' => 'acquisto_servizi',
        'Affitti e noleggi' => 'noleggi_affitti', 'Materiale di comunicazione' => 'oneri_promozionali',
        'Prestazioni occasionali' => 'lavoro', 'Provvigioni' => 'lavoro', 'Rimborsi spese ai volontari' => 'rimborsi_volontari',
        'Formazione dei volontari' => 'altri_oneri', 'Spese di rappresentanza' => 'altri_oneri', 'Imposte e tasse' => 'altri_oneri', 'IVA' => 'altri_oneri',
    ];

    public function up(): void
    {
        Schema::table('attivita', function (Blueprint $table) {
            $table->string('raccolta')->nullable(); // abituale | occasionale, solo per l'area C
        });

        Schema::create('righe_raccolta', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('esercizio');
            $table->foreignId('tipologia_id')->constrained('tipologie')->cascadeOnDelete();
            $table->string('riga'); // chiave di App\Esercizio\Raccolta::PROVENTI o ONERI
            $table->unique(['esercizio', 'tipologia_id']);
        });

        // Una raccolta per iniziativa ed esercizio (mai cumulativa). Importi in centesimi.
        Schema::create('raccolte_occasionali', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iniziativa_id')->constrained('iniziative')->cascadeOnDelete();
            $table->unsignedSmallInteger('esercizio');
            $table->date('dal')->nullable();
            $table->date('al')->nullable();
            $table->string('occasione')->nullable(); // ricorrenza o campagna di sensibilizzazione
            $table->text('beni_servizi')->nullable();
            $table->text('descrizione')->nullable();
            $table->string('luogo')->nullable();
            $table->text('modalita')->nullable();
            $table->text('finalita')->nullable();
            $table->text('dettaglio_costi')->nullable();
            $table->foreignId('attivita_destinazione_id')->nullable()->constrained('attivita')->restrictOnDelete();
            $table->unsignedInteger('beni_modico_numero')->nullable();
            $table->bigInteger('beni_modico_prezzo')->nullable();
            $table->bigInteger('elargizioni_persone_fisiche')->nullable();
            $table->bigInteger('elargizioni_persone_giuridiche')->nullable();
            $table->text('motivazione')->nullable();
            $table->timestamps();
            $table->unique(['iniziativa_id', 'esercizio']);
        });

        Schema::create('liberalita_non_monetarie', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raccolta_id')->constrained('raccolte_occasionali')->cascadeOnDelete();
            $table->string('descrizione');
            $table->unsignedInteger('quantita');
            $table->bigInteger('valore'); // il valore di mercato stimato del totale, in centesimi
            $table->string('criterio'); // criterio di stima (DM 28/11/2019)
            $table->string('documento'); // riferimento al documento che prova la liberalità
            $table->timestamps();
        });

        $this->caricaDatiIniziali();
    }

    public function down(): void
    {
        Schema::dropIfExists('liberalita_non_monetarie');
        Schema::dropIfExists('raccolte_occasionali');
        Schema::dropIfExists('righe_raccolta');
        Schema::table('attivita', fn (Blueprint $table) => $table->dropColumn('raccolta'));
    }

    private function caricaDatiIniziali(): void
    {
        $ora = ['created_at' => now(), 'updated_at' => now()];
        foreach (['abituale' => 'Raccolta fondi abituale', 'occasionale' => 'Raccolta fondi occasionale'] as $raccolta => $nome) {
            DB::table('attivita')->updateOrInsert(['area' => 'C', 'nome' => $nome], ['raccolta' => $raccolta] + $ora);
        }

        $liberalita = DB::table('tipologie')->where('nome', 'Erogazioni liberali')->first();
        if ($liberalita !== null) {
            $proprieta = array_values(array_unique([...json_decode($liberalita->proprieta, true), 'liberalita']));
            DB::table('tipologie')->where('id', $liberalita->id)->update(['proprieta' => json_encode($proprieta)]);
        }

        // Ogni esercizio che ha già la tabella delle voci riceve quelle dell'area C e le righe delle raccolte; gli esercizi nuovi le copiano con `Apertura`.
        // La tabella dice «C1» per tutte: C1 o C2 dipende dall'attività dell'imputazione (`SchemaModelloD::voceDi`).
        $tipologie = DB::table('tipologie')->whereIn('nome', array_keys(self::RIGHE))->pluck('id', 'nome');
        foreach (DB::table('voci_modello_d')->distinct()->pluck('esercizio') as $esercizio) {
            foreach (self::RIGHE as $nome => $riga) {
                if (! isset($tipologie[$nome])) {
                    continue;
                }
                DB::table('voci_modello_d')->insertOrIgnore(['esercizio' => $esercizio, 'area' => 'C', 'tipologia_id' => $tipologie[$nome], 'voce' => 'C1']);
                DB::table('righe_raccolta')->insertOrIgnore(['esercizio' => $esercizio, 'tipologia_id' => $tipologie[$nome], 'riga' => $riga]);
            }
        }
    }
};
