<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lo stato dell'esercizio (#59, #12): aperto → approvato → depositato (#60); la riapertura riporta ad aperto con la versione successiva.
        // Vive sulla riga dei dati dell'esercizio, che è già la serratura di ogni scrittura; i valori di partenza servono perché la riga si crea anche con un `insertOrIgnore`.
        Schema::table('dati_esercizio', function (Blueprint $table) {
            $table->string('stato', 12)->default('aperto');
            $table->unsignedTinyInteger('versione')->default(1);
        });

        // Ogni approvazione lascia una riga, che la riapertura marca ma non cancella: lo storico delle versioni. L'istantanea è la versione approvata
        // dei prospetti e dei test (solo per il confronto, non è l'istantanea di deposito di #60).
        Schema::create('versioni_esercizio', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('esercizio');
            $table->unsignedTinyInteger('numero');
            $table->date('approvata_il');
            $table->foreignId('approvata_da')->nullable()->constrained('users')->nullOnDelete();
            $table->string('criterio_secondarieta', 1); // fissato con l'approvazione
            $table->string('esito_secondarieta', 20); // del criterio fissato
            $table->unsignedInteger('soglia_rientro_centesimi_di_punto')->nullable(); // solo se non rispettata: la soglia ridotta dell'esercizio dopo (art. 4 DM 107/2021), mai sotto zero
            $table->string('stato_2bis', 20); // pareggio, tolleranza, non_rispettato: da qui si conta la tolleranza del 6%
            $table->json('istantanea');
            $table->dateTime('riaperta_il')->nullable();
            $table->foreignId('riaperta_da')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivazione_riapertura')->nullable();
            $table->date('segnalazione_inviata_il')->nullable(); // art. 4 DM 107/2021: entro 30 giorni dall'approvazione
            $table->string('segnalazione_protocollo')->nullable();
            $table->timestamps();
            $table->unique(['esercizio', 'numero']);
        });

        // La fase in cui una nota è stata scritta («aggiunta dopo l'approvazione», storia 96): vuota per un esercizio ancora aperto.
        Schema::table('note', function (Blueprint $table) {
            $table->string('fase', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('note', function (Blueprint $table) {
            $table->dropColumn('fase');
        });
        Schema::dropIfExists('versioni_esercizio');
        Schema::table('dati_esercizio', function (Blueprint $table) {
            $table->dropColumn(['stato', 'versione']);
        });
    }
};
