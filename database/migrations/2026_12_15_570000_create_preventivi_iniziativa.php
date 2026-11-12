<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il preventivo di un'iniziativa per un esercizio (#57, glossario: Preventivo): una riga per tipologia. Il verso non si salva,
 * è quello della tipologia (non cambia dopo la creazione). Nessuna riga vuol dire nessun preventivo; non c'è approvazione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preventivi_iniziativa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iniziativa_id')->constrained('iniziative')->restrictOnDelete();
            $table->unsignedSmallInteger('esercizio');
            $table->foreignId('tipologia_id')->constrained('tipologie')->restrictOnDelete();
            $table->unsignedBigInteger('importo'); // centesimi, maggiore di zero
            $table->unique(['iniziativa_id', 'esercizio', 'tipologia_id'], 'preventivi_iniziativa_riga_unique'); // nome esplicito: quello automatico supera i 64 caratteri di MySQL
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preventivi_iniziativa');
    }
};
