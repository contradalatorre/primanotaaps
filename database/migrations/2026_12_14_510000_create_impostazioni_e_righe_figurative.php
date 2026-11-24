<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le impostazioni dell'associazione valgono da un esercizio in poi: per l'esercizio N vale la riga con il più grande
        // `dal_esercizio` ≤ N. Cambiare la denominazione nel 2028 non tocca il Modello D del 2026.
        Schema::create('impostazioni_associazione', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('dal_esercizio')->unique();
            $table->string('denominazione');
            $table->string('codice_fiscale', 16)->nullable();
            $table->string('numero_runts')->nullable();
            $table->boolean('affiliazione_rete')->default(false); // art. 85 c. 4 CTS: rete nazionale con finalità assistenziali riconosciute
            $table->timestamps();
        });

        // Costi e proventi figurativi dei dati dell'esercizio (#12): solo da ore rilevate, mai stime. Fuori da totali e avanzi.
        // L'inserimento è di #53 (dati dell'esercizio); qui si leggono.
        Schema::create('righe_figurative', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('esercizio');
            $table->string('tipo'); // costo | provento
            $table->string('area', 1);
            $table->string('descrizione');
            $table->bigInteger('importo'); // centesimi di euro
            $table->timestamps();
            $table->index('esercizio');
        });

        // Codice fiscale e numero RUNTS si scrivono dalla pagina delle impostazioni: nel Modello D compaiono come «da inserire» finché mancano.
        DB::table('impostazioni_associazione')->insert(['dal_esercizio' => 2026, 'denominazione' => 'Contrada La Torre APS', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('righe_figurative');
        Schema::dropIfExists('impostazioni_associazione');
    }
};
