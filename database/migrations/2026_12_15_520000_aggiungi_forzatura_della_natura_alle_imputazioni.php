<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La forzatura della natura commerciale (#52) sta sull'imputazione, non in una tabella a parte: `Classificazione` riscrive le
 * imputazioni a ogni salvataggio e porta le colonne della forzatura sulla riga nuova nella stessa posizione. `forzatura_ingressi`
 * è la fotografia dei dati da cui dipendeva il calcolo quando si è forzato: se oggi sono diversi, la forzatura va rivista.
 *
 * In più la proprietà fiscale «somministrazione» alle due tipologie che la danno (art. 85 c. 4: serve a riconoscerla senza
 * confrontare il nome, che si può cambiare).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imputazioni', function (Blueprint $table) {
            $table->boolean('natura_forzata')->nullable(); // vero = commerciale, falso = non commerciale, vuoto = nessuna forzatura
            $table->text('forzatura_motivazione')->nullable();
            $table->foreignId('forzatura_da')->nullable()->constrained('users');
            $table->dateTime('forzatura_il')->nullable();
            $table->json('forzatura_ingressi')->nullable();
        });

        foreach (['Somministrazione', 'Somministrazione ai soci'] as $nome) {
            $tipologia = DB::table('tipologie')->where('nome', $nome)->first();
            if ($tipologia) {
                $proprieta = array_values(array_unique([...json_decode($tipologia->proprieta, true), 'somministrazione']));
                DB::table('tipologie')->where('id', $tipologia->id)->update(['proprieta' => json_encode($proprieta)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('imputazioni', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forzatura_da');
            $table->dropColumn(['natura_forzata', 'forzatura_motivazione', 'forzatura_il', 'forzatura_ingressi']);
        });
    }
};
