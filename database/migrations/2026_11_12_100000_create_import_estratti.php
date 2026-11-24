<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_estratti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conto_id')->constrained('conti');
            $table->foreignId('user_id')->constrained('users');
            $table->string('stato'); // anteprima → confermato → annullato (App\Models\ImportEstratto::STATI)
            $table->string('percorso')->nullable(); // le righe lette, in anteprima: mai il file caricato
            $table->date('periodo_dal');
            $table->date('periodo_al');
            $table->bigInteger('saldo_iniziale'); // centesimi, come dice il file
            $table->bigInteger('saldo_finale');
            $table->unsignedInteger('righe'); // righe di movimento nel file
            $table->dateTime('confermato_il')->nullable();
            $table->dateTime('annullato_il')->nullable();
            $table->timestamps();
        });

        // Righe del file con la stessa chiave di un movimento già presente ma dati diversi: restano qui finché
        // un operatore non decide (#14), e non si sovrascrive mai nulla.
        Schema::create('import_conflitti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_estratto_id')->constrained('import_estratti');
            $table->foreignId('movimento_id')->constrained('movimenti'); // quello già presente
            $table->json('riga'); // la riga del file, come l'ha letta il formato
            $table->string('decisione')->nullable(); // tieni_esistente | importa_a_parte
            $table->foreignId('deciso_da')->nullable()->constrained('users');
            $table->dateTime('deciso_il')->nullable();
            $table->timestamps();
        });

        Schema::table('movimenti', function (Blueprint $table) {
            $table->foreignId('import_estratto_id')->nullable()->constrained('import_estratti');
            $table->date('data_valuta')->nullable();
            $table->string('causale')->nullable();
            $table->bigInteger('saldo_parziale')->nullable(); // centesimi, con segno, dall'estratto
            $table->string('chiave', 40)->nullable(); // doppioni: data + importo + descrizione + saldo parziale
            $table->index(['conto_id', 'chiave']);
        });
    }

    public function down(): void
    {
        Schema::table('movimenti', function (Blueprint $table) {
            $table->dropIndex(['conto_id', 'chiave']);
            $table->dropConstrainedForeignId('import_estratto_id');
            $table->dropColumn(['data_valuta', 'causale', 'saldo_parziale', 'chiave']);
        });
        Schema::dropIfExists('import_conflitti');
        Schema::dropIfExists('import_estratti');
    }
};
