<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('emergenza')->default(false); // l'account di emergenza (#36): una sola riga, creata al primo uso
        });

        // Registro degli accessi (glossario): chi è entrato, quando, da dove. Solo righe nuove, mai modificate.
        // #36 ci scrive gli usi dell'account di emergenza; esportazioni e cancellazione a 12 mesi sono di #65.
        Schema::create('registro_accessi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // vuoto se nessuno è entrato (tentativo fallito)
            $table->string('evento', 50);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registro_accessi');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('emergenza');
        });
    }
};
