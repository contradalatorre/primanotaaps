<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // I dati dell'esercizio che non derivano dai movimenti (#12, #53). Una riga per esercizio, creata alla prima scrittura:
        // senza riga i dati sono quelli di partenza (niente dichiarato). I costi e proventi figurativi stanno in `righe_figurative`.
        Schema::create('dati_esercizio', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('esercizio')->unique();
            $table->unsignedInteger('dipendenti_medi')->nullable();
            $table->bigInteger('attivo')->nullable(); // centesimi di euro: stima, un rendiconto per cassa non ha stato patrimoniale
            $table->string('criterio_riparto')->default('ricavi'); // costi promiscui: quota dei ricavi (Circolare 1/E) o dei costi diretti
            $table->string('criterio_secondarieta', 1)->nullable(); // «a» (30%) o «b» (66%) confermato dal Tesoriere
            // Il Tesoriere ha controllato i costi figurativi e quelli sono tutti (anche «nessuno»): finché non lo dice, il test di
            // secondarietà segnala il dato mancante.
            $table->boolean('figurativi_dichiarati')->default(false);
            $table->timestamps();
        });

        // La soglia «vicino alla soglia» (oltre l'80% del limite, #17) la sceglie l'Amministratore, con validità per esercizio come le altre impostazioni.
        Schema::table('impostazioni_associazione', function (Blueprint $table) {
            $table->unsignedTinyInteger('soglia_vicino')->default(80);
        });
    }

    public function down(): void
    {
        Schema::table('impostazioni_associazione', function (Blueprint $table) {
            $table->dropColumn('soglia_vicino');
        });
        Schema::dropIfExists('dati_esercizio');
    }
};
