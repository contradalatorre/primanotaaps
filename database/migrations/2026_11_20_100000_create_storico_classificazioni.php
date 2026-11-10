<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Esempi di classificazione senza movimento (#43): i casi veri del 2025 entreranno da qui (#63). Lo storico legge
        // anche i movimenti confermati, direttamente: qui non se ne tiene una copia.
        Schema::create('esempi_classificazione', function (Blueprint $table) {
            $table->id();
            $table->string('verso'); // entrata | uscita
            $table->string('causale')->nullable();
            $table->text('descrizione'); // senza nomi né email: lo garantisce chi li carica
            $table->date('data');
            $table->foreignId('tipologia_id')->constrained('tipologie')->restrictOnDelete();
            $table->foreignId('iniziativa_id')->nullable()->constrained('iniziative')->restrictOnDelete();
            $table->string('area', 1);
            $table->foreignId('attivita_id')->nullable()->constrained('attivita')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('movimenti', function (Blueprint $table) {
            // Da dove viene la proposta dallo storico in corso (come `regola_id` per le regole): al più uno dei tre, e
            // senza nessuno una proposta è una scelta di una persona. Se l’origine sparisce la proposta resta senza fonte: non è più «pronta» e la prossima cascata la riporta da classificare (o la ripropone).
            $table->foreignId('storico_movimento_id')->nullable()->constrained('movimenti')->nullOnDelete();
            $table->foreignId('storico_esempio_id')->nullable()->constrained('esempi_classificazione')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimenti', function (Blueprint $table) {
            $table->dropConstrainedForeignId('storico_esempio_id');
            $table->dropConstrainedForeignId('storico_movimento_id');
        });
        Schema::dropIfExists('esempi_classificazione');
    }
};
