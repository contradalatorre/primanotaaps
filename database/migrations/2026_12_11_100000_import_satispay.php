<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La commissione trattenuta su un incasso (Satispay #49; vale anche per PayPal): `importo` resta quello che entra davvero,
        // al netto, e il lordo è importo + commissione. La classificazione ne ricava l'imputazione in uscita (voce D1).
        Schema::table('movimenti', function (Blueprint $table) {
            $table->bigInteger('commissione')->nullable(); // centesimi, mai negativa
        });

        // Un estratto può non avere saldi (Satispay): non si inventano, il conto si riconcilia con il saldo dichiarato.
        Schema::table('import_estratti', function (Blueprint $table) {
            $table->bigInteger('saldo_iniziale')->nullable()->change();
            $table->bigInteger('saldo_finale')->nullable()->change();
        });

        // Il saldo letto dalla dashboard di un conto senza saldo nell'estratto, a una data; la differenza con l'app si spiega.
        Schema::create('saldi_dichiarati', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conto_id')->constrained('conti');
            $table->foreignId('user_id')->constrained('users');
            $table->date('data');
            $table->bigInteger('importo'); // centesimi
            $table->text('spiegazione')->nullable(); // obbligatoria se alla data il saldo dell'app è diverso
            $table->timestamps();
            $table->unique(['conto_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saldi_dichiarati');
        Schema::table('import_estratti', function (Blueprint $table) {
            $table->bigInteger('saldo_iniziale')->nullable(false)->change();
            $table->bigInteger('saldo_finale')->nullable(false)->change();
        });
        Schema::table('movimenti', function (Blueprint $table) {
            $table->dropColumn('commissione');
        });
    }
};
