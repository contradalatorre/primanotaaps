<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conti', function (Blueprint $table) {
            $table->id();
            $table->string('tipo'); // App\Models\Conto::TIPI
            $table->string('nome')->unique();
            $table->string('formato_import')->nullable(); // App\Models\Conto::FORMATI_IMPORT
            $table->string('riga_modello_d'); // cassa | depositi
            $table->date('aperto_il');
            $table->date('chiuso_il')->nullable();
            $table->bigInteger('saldo_iniziale'); // centesimi di euro, con segno: solo euro
            $table->timestamps();
        });

        // La chiave esterna che #35 ha lasciato a questo ticket.
        Schema::table('assegnazione_conto', function (Blueprint $table) {
            $table->foreign('conto_id')->references('id')->on('conti')->cascadeOnDelete();
        });

        Schema::create('movimenti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conto_id')->constrained('conti');
            $table->date('data');
            $table->unsignedBigInteger('importo'); // centesimi di euro, sempre positivi: il segno sta in `verso`
            $table->string('verso'); // entrata | uscita
            $table->text('descrizione');
            $table->string('valuta_originale')->nullable(); // annotazione libera (es. «20 USD»), mai un importo che conta
            $table->string('stato')->default('da_classificare'); // da_classificare → proposto → confermato
            $table->boolean('manuale')->default(true); // gli importati non si eliminano mai uno per uno
            $table->foreignId('inserito_da')->constrained('users');
            $table->foreignId('confermato_da')->nullable()->constrained('users');
            $table->dateTime('confermato_il')->nullable();
            // Eliminazione logica: ritiro dell'autore (senza motivazione) o eliminazione con motivazione.
            $table->dateTime('eliminato_il')->nullable();
            $table->foreignId('eliminato_da')->nullable()->constrained('users');
            $table->text('motivazione_eliminazione')->nullable();
            $table->timestamps();
            $table->index(['conto_id', 'data']);
        });

        // Registro delle modifiche: solo righe aggiunte (il modello rifiuta update e delete).
        Schema::create('registro_modifiche', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('oggetto_tipo'); // movimento, conto: altri oggetti arriveranno con i loro ticket
            $table->unsignedBigInteger('oggetto_id');
            $table->string('azione');
            $table->json('prima')->nullable();
            $table->json('dopo')->nullable();
            $table->text('motivazione')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['oggetto_tipo', 'oggetto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registro_modifiche');
        Schema::dropIfExists('movimenti');
        Schema::table('assegnazione_conto', function (Blueprint $table) {
            $table->dropForeign(['conto_id']);
        });
        Schema::dropIfExists('conti');
    }
};
