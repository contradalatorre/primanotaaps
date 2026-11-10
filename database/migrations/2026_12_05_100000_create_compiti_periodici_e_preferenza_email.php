<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quando è partito l'ultima volta ogni compito periodico (#46): il cron HTTP di Aruba non ha orari precisi.
        Schema::create('compiti_periodici', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->dateTime('ultimo_avvio');
        });

        // Come ognuno vuole sapere delle menzioni: riepilogo | immediata | nessuna.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_menzioni', 20)->default('riepilogo');
        });
    }
};
