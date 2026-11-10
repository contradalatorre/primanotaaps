<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le regole del tesoriere (#42): condizioni (tutte da soddisfare) ed effetti. Nessuna colonna per la natura commerciale:
        // le regole non la impostano mai.
        Schema::create('regole', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->unsignedInteger('posizione'); // la prima che corrisponde vince
            // Condizioni: quelle vuote non contano.
            $table->foreignId('conto_id')->nullable()->constrained('conti')->restrictOnDelete();
            $table->string('verso')->nullable(); // entrata | uscita
            $table->string('causale')->nullable(); // uguale a (senza badare alle maiuscole)
            $table->string('testo')->nullable(); // contenuto nella descrizione: la controparte oggi sta lì dentro
            $table->unsignedBigInteger('importo_min')->nullable(); // centesimi; esatto = min e max uguali
            $table->unsignedBigInteger('importo_max')->nullable();
            $table->date('valida_dal')->nullable();
            $table->date('valida_al')->nullable();
            // Effetti: la classificazione che la regola propone.
            $table->foreignId('tipologia_id')->nullable()->constrained('tipologie')->restrictOnDelete();
            $table->foreignId('iniziativa_id')->nullable()->constrained('iniziative')->restrictOnDelete();
            $table->string('area', 1)->nullable();
            $table->foreignId('attivita_id')->nullable()->constrained('attivita')->restrictOnDelete();
            $table->string('tipo_controparte')->nullable(); // socio | terzo
            $table->timestamps();
        });

        Schema::create('regola_tag', function (Blueprint $table) {
            $table->foreignId('regola_id')->constrained('regole')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tag')->restrictOnDelete();
            $table->primary(['regola_id', 'tag_id']);
        });

        Schema::table('movimenti', function (Blueprint $table) {
            // La regola da cui viene la proposta in corso: senza, una proposta è scelta di una persona e le regole non la toccano.
            $table->foreignId('regola_id')->nullable()->constrained('regole')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimenti', function (Blueprint $table) {
            $table->dropConstrainedForeignId('regola_id');
        });
        Schema::dropIfExists('regola_tag');
        Schema::dropIfExists('regole');
    }
};
