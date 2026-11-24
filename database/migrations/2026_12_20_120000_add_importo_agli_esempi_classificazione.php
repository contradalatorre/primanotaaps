<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // L'importo dell'esempio (#63: descrizione, causale, segno, importo, classificazione): lo storico non lo usa per confrontare, serve a chi rilegge gli esempi.
        Schema::table('esempi_classificazione', function (Blueprint $table) {
            $table->bigInteger('importo')->nullable(); // centesimi, sempre positivo: il segno è il verso
        });
    }

    public function down(): void
    {
        Schema::table('esempi_classificazione', function (Blueprint $table) {
            $table->dropColumn('importo');
        });
    }
};
