<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // L'annotazione in calce al rendiconto (#54): il Tesoriere la scrive solo se corregge il testo che l'app precompila dagli esiti.
        // Null: vale il testo precompilato, che segue i dati. L'impaginazione nel rendiconto è di #18.
        Schema::table('dati_esercizio', function (Blueprint $table) {
            $table->text('annotazione_in_calce')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dati_esercizio', function (Blueprint $table) {
            $table->dropColumn('annotazione_in_calce');
        });
    }
};
