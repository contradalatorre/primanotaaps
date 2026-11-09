<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lo spazio di prova di un utente (ADR 0003): le sue tabelle stanno nel database di prova col prefisso `p<id>_`;
        // qui si tiene solo che esiste, quale missione lo ha preparato e quando è stato usato l'ultima volta.
        Schema::create('spazi_prova', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('missione')->nullable();
            $table->dateTime('ultimo_uso');
            $table->timestamps();
        });

        // Avanzamento per utente e missione: resta anche quando lo spazio di prova si cancella. Il timbro è una
        // missione completata; il sigillo di un percorso si ricava dalle missioni del percorso.
        Schema::create('onboarding_avanzamenti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('missione');
            $table->string('stato')->default('da_fare'); // da_fare | in_corso | saltata | completata
            $table->unsignedSmallInteger('passo')->default(0);
            $table->unsignedSmallInteger('versione')->default(1); // la versione che si sta facendo
            $table->unsignedSmallInteger('versione_completata')->nullable();
            $table->unsignedSmallInteger('novita_vista')->nullable(); // l'ultima versione di cui ha visto «cosa è cambiato»
            $table->dateTime('invitato_il')->nullable();
            $table->dateTime('completata_il')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'missione']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_avanzamenti');
        Schema::dropIfExists('spazi_prova');
    }
};
