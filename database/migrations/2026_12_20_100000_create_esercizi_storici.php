<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un esercizio storico (#63, #18): gli importi per voce Modello D e i saldi finali di un esercizio senza movimenti, caricati dal foglio
        // approvato. La riga c'è solo dopo la conferma e da allora è bloccata: la modifica solo l'Amministratore, con motivazione, nel registro.
        Schema::create('esercizi_storici', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('esercizio')->unique();
            $table->json('importi'); // «lato:codice» → centesimi («entrate:A1», «uscite:Imposte», «uscite:I1»)
            $table->bigInteger('saldo_cassa');
            $table->bigInteger('saldo_depositi');
            $table->json('figurativi'); // costi e proventi per area A e B, a mano (il foglio non li ha)
            $table->json('differenze'); // totali e avanzi del foglio che l'app ha ricalcolato diversi, per la storia
            $table->foreignId('caricato_da')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // La procedura di apertura (#63): i saldi iniziali dei conti dell'esercizio si inseriscono una sola volta.
        Schema::table('dati_esercizio', function (Blueprint $table) {
            $table->dateTime('saldi_apertura_il')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dati_esercizio', function (Blueprint $table) {
            $table->dropColumn('saldi_apertura_il');
        });
        Schema::dropIfExists('esercizi_storici');
    }
};
