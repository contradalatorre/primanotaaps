<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Il deposito al RUNTS (#60, #12): una versione approvata ha al più un deposito, e la sua istantanea (quello che il PDF di deposito stampa) vive qui, sulla
        // stessa riga. La riapertura marca la versione e ne apre un'altra: il deposito della prima resta nello storico.
        Schema::table('versioni_esercizio', function (Blueprint $table) {
            $table->date('depositata_il')->nullable(); // la data dell'invio al RUNTS
            $table->foreignId('depositata_da')->nullable()->constrained('users')->nullOnDelete();
            $table->json('istantanea_deposito')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('versioni_esercizio', function (Blueprint $table) {
            $table->dropConstrainedForeignId('depositata_da');
            $table->dropColumn(['depositata_il', 'istantanea_deposito']);
        });
    }
};
