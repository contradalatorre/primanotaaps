<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una conta di cassa (#50): i contanti contati a una data, il saldo dell'app in quel momento e la differenza.
        // Le conte si aggiungono e basta: una conta sbagliata si rifà, non si corregge.
        Schema::create('conte_di_cassa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conto_id')->constrained('conti');
            $table->date('data');
            $table->bigInteger('importo_contato'); // centesimi
            $table->bigInteger('saldo_app'); // il saldo dell'app a `data` prima dell'eventuale correzione, fotografato (quello dal vivo cambia con ogni movimento)
            $table->unsignedBigInteger('ultimo_movimento_id'); // il movimento con l'id più alto quando si è contato: i movimenti della stessa data scritti dopo (es. il versamento) non fanno parte di quel che si è contato
            $table->bigInteger('differenza'); // contato meno saldo dell'app, con segno
            $table->foreignId('correzione_id')->nullable()->constrained('movimenti'); // il movimento che spiega la differenza
            $table->text('spiegazione')->nullable(); // oppure una nota (non quella dei movimenti: qui non c'è un movimento)
            $table->foreignId('contata_da')->constrained('users');
            $table->foreignId('contata_con')->nullable()->constrained('users'); // conta congiunta: il secondo nome (passaggio di consegne, #70)
            $table->timestamps();
            $table->index(['conto_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conte_di_cassa');
    }
};
