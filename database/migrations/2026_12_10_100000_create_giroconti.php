<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un giroconto confermato (#47): un'uscita e un'entrata su due conti. Le proposte non si salvano: si ricalcolano dalle regole.
        // Ogni metà sta in al più un giroconto (unique), qualunque cosa succeda tra due richieste.
        Schema::create('giroconti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uscita_id')->unique()->constrained('movimenti');
            $table->foreignId('entrata_id')->unique()->constrained('movimenti');
            $table->string('regola', 80); // la chiave della regola (nel modulo del formato) che l'ha proposto
            $table->foreignId('confermato_da')->constrained('users');
            $table->dateTime('confermato_il');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giroconti');
    }
};
