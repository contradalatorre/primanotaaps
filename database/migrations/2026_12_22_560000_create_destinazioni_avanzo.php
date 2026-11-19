<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La destinazione dell'avanzo (glossario, #56): l'impiego proposto all'assemblea, righe con descrizione, importo e nota.
        // Dati dell'esercizio, scritti dal Tesoriere; compaiono solo nel rendiconto per l'assemblea, mai nel Modello D.
        Schema::create('destinazioni_avanzo', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('esercizio');
            $table->string('descrizione');
            $table->bigInteger('importo'); // centesimi di euro, maggiore di zero
            $table->string('nota')->nullable();
            $table->timestamps();
            $table->index('esercizio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinazioni_avanzo');
    }
};
