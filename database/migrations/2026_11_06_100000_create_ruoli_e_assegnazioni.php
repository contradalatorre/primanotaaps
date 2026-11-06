<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Un esterno si aggiunge prima che entri: il suo google_id arriva al primo accesso.
            $table->string('google_id')->nullable()->change();
            $table->boolean('esterno')->default(false);
        });

        Schema::create('ruoli', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->json('permessi'); // valori di App\Permesso
            $table->timestamps();
        });

        Schema::create('assegnazioni', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ruolo_id')->constrained('ruoli');
            // Ambito: tutti i conti, oppure quelli in assegnazione_conto. Un flag a parte e non «nessuna riga = tutti»:
            // quando #38 toglie l'ultimo conto chiuso l'assegnazione resta senza ambito, non diventa onnipotente.
            $table->boolean('tutti_i_conti')->default(true);
            $table->date('scade_il')->nullable(); // ultimo giorno di validità; obbligatorio per gli esterni
            $table->date('nomina_firmata_il')->nullable(); // solo per gli esterni (#27)
            $table->timestamps();
        });

        Schema::create('assegnazione_conto', function (Blueprint $table) {
            $table->foreignId('assegnazione_id')->constrained('assegnazioni')->cascadeOnDelete();
            $table->unsignedBigInteger('conto_id')->index(); // la chiave esterna sui conti la aggiunge #38
            $table->primary(['assegnazione_id', 'conto_id']);
        });

        // Ruoli predefiniti (tabella di #16), poi modificabili. Elenchi scritti per esteso: una migrazione
        // non deve cambiare se l'elenco dei permessi cresce.
        $tesoriere = ['vedere', 'inserire_movimenti_cassa', 'modificare_movimenti_propri', 'proporre_classificazioni',
            'importare_e_confermare', 'eliminare_movimento_manuale', 'gestire_regole', 'chiedere_suggerimenti_ai',
            'riclassificare_in_blocco', 'gestire_esercizio', 'vedere_registro_modifiche', 'vedere_storia_movimento', 'note'];
        $ruoli = [
            'Amministratore' => [...$tesoriere, 'riaprire_esercizio', 'oscurare_nota', 'gestire_utenti', 'gestire_impostazioni', 'gestire_passaggio_consegne'],
            'Tesoriere' => $tesoriere,
            'Consigliere' => ['vedere', 'inserire_movimenti_cassa', 'modificare_movimenti_propri', 'proporre_classificazioni', 'vedere_storia_movimento', 'note'],
            'Lettore esterno' => ['vedere', 'note'],
        ];
        foreach ($ruoli as $nome => $permessi) {
            DB::table('ruoli')->insert(['nome' => $nome, 'permessi' => json_encode($permessi), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assegnazione_conto');
        Schema::dropIfExists('assegnazioni');
        Schema::dropIfExists('ruoli');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('esterno');
        });
    }
};
