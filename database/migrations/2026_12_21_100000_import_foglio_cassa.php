<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'import ripetibile della cassa dal foglio (#64, #19): la data di passaggio del conto, la riga della tabella di conversione da cui viene una
 * proposta (come `regola_id` per le regole: senza, una proposta senza fonte tornerebbe da classificare al prossimo salvataggio delle regole) e il
 * risultato dei controlli di un import del foglio (mese per mese, righe sparite), che resta dopo la conferma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conti', function (Blueprint $table) {
            $table->date('passaggio_il')->nullable(); // l'ultimo giorno che il foglio copre: l'import rifiuta le righe dopo, e con il passaggio tabella e import si chiudono
        });
        Schema::table('movimenti', function (Blueprint $table) {
            $table->foreignId('conversione_causale_id')->nullable()->constrained('conversione_causali')->nullOnDelete();
        });
        Schema::table('import_estratti', function (Blueprint $table) {
            $table->json('controlli')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_estratti', function (Blueprint $table) {
            $table->dropColumn('controlli');
        });
        Schema::table('movimenti', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversione_causale_id');
        });
        Schema::table('conti', function (Blueprint $table) {
            $table->dropColumn('passaggio_il');
        });
    }
};
