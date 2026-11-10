<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una nota si attacca a un oggetto qualunque: `oggetto_tipo` è l'alias della mappa dei morph (AppServiceProvider),
        // come in `registro_modifiche`. Oggi solo i movimenti (#16); altri oggetti arriveranno senza cambiare lo schema.
        Schema::create('note', function (Blueprint $table) {
            $table->id();
            $table->string('oggetto_tipo');
            $table->unsignedBigInteger('oggetto_id');
            $table->foreignId('user_id')->constrained('users');
            $table->text('testo');
            $table->boolean('dubbio')->default(false); // una nota contrassegnata (glossario: Dubbio)
            $table->dateTime('chiuso_il')->nullable(); // il dubbio si chiude con la conferma o col ? della revisione
            $table->foreignId('chiuso_da')->nullable()->constrained('users');
            $table->dateTime('modificata_il')->nullable();
            $table->dateTime('eliminata_il')->nullable();
            $table->foreignId('eliminata_da')->nullable()->constrained('users');
            $table->dateTime('oscurata_il')->nullable();
            $table->foreignId('oscurata_da')->nullable()->constrained('users');
            $table->text('motivazione_oscuramento')->nullable();
            $table->timestamps();
            $table->index(['oggetto_tipo', 'oggetto_id']);
        });

        // `@utente` o `@ruolo`: esattamente uno tra user_id e ruolo_id. Un ruolo ha una riga sola, così basta una
        // persona che la segni vista (#16); chi ha il ruolo si risolve alla lettura, non alla scrittura.
        Schema::create('menzioni', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nota_id')->constrained('note')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->foreignId('ruolo_id')->nullable()->constrained('ruoli');
            $table->foreignId('vista_da')->nullable()->constrained('users');
            $table->dateTime('vista_il')->nullable();
            $table->timestamps();
            $table->unique(['nota_id', 'user_id']);
            $table->unique(['nota_id', 'ruolo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menzioni');
        Schema::dropIfExists('note');
    }
};
