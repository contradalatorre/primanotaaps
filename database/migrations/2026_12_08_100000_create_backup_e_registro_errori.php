<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ogni tentativo di backup (#68) e dove sta il suo file sul drive: la rotazione lavora da qui, senza chiedere l'elenco al drive.
        Schema::create('backup', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20); // mensile | su_richiesta
            $table->string('esito', 20)->default('in_corso'); // in_corso | riuscito | fallito
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // chi l'ha chiesto; vuoto per il mensile
            $table->dateTime('avviato_il');
            $table->dateTime('concluso_il')->nullable();
            $table->string('file')->nullable();
            $table->string('drive_id')->nullable();
            $table->unsignedBigInteger('dimensione')->nullable();
            $table->text('errore')->nullable(); // un messaggio breve e sicuro, mai il corpo di una risposta
            $table->dateTime('eliminato_il')->nullable(); // tolto dal drive dalla rotazione
        });

        // Registro degli errori (#68). Un errore uguale e ancora aperto si conta invece di ripetersi: `impronta` è unica
        // solo finché l'errore è aperto (chiusa la riga diventa null, e MySQL ammette più null).
        Schema::create('registro_errori', function (Blueprint $table) {
            $table->id();
            $table->string('origine', 80); // backup, compito:menzioni:riepilogo, app...
            $table->boolean('grave')->default(false); // gli errori gravi avvisano gli Amministratori per email
            $table->string('messaggio', 500);
            $table->string('impronta', 40)->nullable()->unique();
            $table->unsignedInteger('volte')->default(1);
            $table->dateTime('primo_il');
            $table->dateTime('ultimo_il');
            $table->dateTime('avvisato_il')->nullable(); // l'ultima email: un errore che si ripete avvisa al massimo una volta al giorno
            $table->dateTime('risolto_il')->nullable();
            $table->foreignId('risolto_da')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registro_errori');
        Schema::dropIfExists('backup');
    }
};
