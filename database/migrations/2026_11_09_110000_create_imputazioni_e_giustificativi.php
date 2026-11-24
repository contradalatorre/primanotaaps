<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imputazioni', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movimento_id')->constrained('movimenti');
            // Con segno per lasciare posto alla commissione in uscita su un incasso (PayPal, #41); per ora sempre positivo.
            $table->bigInteger('importo'); // centesimi di euro
            $table->string('area', 1); // App\Area
            $table->foreignId('attivita_id')->nullable()->constrained('attivita')->restrictOnDelete();
            $table->foreignId('iniziativa_id')->nullable()->constrained('iniziative')->restrictOnDelete();
            // Vuota finché un consigliere propone solo l'iniziativa: senza tipologia non si conferma.
            $table->foreignId('tipologia_id')->nullable()->constrained('tipologie')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('imputazione_tag', function (Blueprint $table) {
            $table->foreignId('imputazione_id')->constrained('imputazioni')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tag')->restrictOnDelete();
            $table->primary(['imputazione_id', 'tag_id']);
        });

        Schema::create('giustificativi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movimento_id')->constrained('movimenti');
            $table->string('riferimento'); // es. «Fattura 12 del 3/5/2026»
            $table->string('link', 2000)->nullable(); // collegamento al file sul Drive: l'app non conserva file
            $table->timestamps();
        });

        Schema::table('movimenti', function (Blueprint $table) {
            $table->string('tipo_controparte')->nullable(); // socio | terzo | non_pertinente; vuoto = da indicare
            // Chi ha scelto la classificazione in corso («Proposta da Mario Rossi»); regole, storico e AI arrivano dopo.
            $table->foreignId('proposto_da')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('movimenti', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proposto_da');
            $table->dropColumn('tipo_controparte');
        });
        Schema::dropIfExists('giustificativi');
        Schema::dropIfExists('imputazione_tag');
        Schema::dropIfExists('imputazioni');
    }
};
