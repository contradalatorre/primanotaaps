<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimenti', function (Blueprint $table) {
            // La commissione che il fornitore ha trattenuto su un incasso (PayPal, #48; Satispay): l'importo del movimento è il netto,
            // e il lordo è importo + commissione. Con una classificazione, la commissione è un'imputazione in uscita (D1).
            $table->unsignedBigInteger('commissione')->default(0); // centesimi
        });
    }

    public function down(): void
    {
        Schema::table('movimenti', function (Blueprint $table) {
            $table->dropColumn('commissione');
        });
    }
};
