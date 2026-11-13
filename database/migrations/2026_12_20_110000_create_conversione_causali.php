<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La tabella di conversione delle vecchie causali del foglio (#19, #63): ogni «Nuova causale» diventa tipologia, area, attività, iniziativa e
 * tag. Per ora la legge il caricamento degli esempi di classificazione del 2025; #64 la rende modificabile in una pagina dell'app.
 * I dati sono quelli di docs/import-foglio/conversione-causali.csv, scritti per esteso: `docs/` non viene pubblicato su Aruba e lì le
 * migrazioni partono da una pagina. Un test confronta questi dati con il CSV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversione_causali', function (Blueprint $table) {
            $table->id();
            $table->string('causale'); // com'è scritta nel foglio, senza il prefisso «->» o «→»
            $table->string('chiave')->unique(); // minuscola e senza spazi in testa e in coda: con questa si confronta la causale del foglio
            $table->string('verso'); // entrata | uscita | giroconto
            $table->foreignId('tipologia_id')->nullable()->constrained('tipologie')->nullOnDelete(); // vuota: la riga arriva come dubbio; cancellando una voce mai usata la conversione la perde (#64 potrà fare altrimenti)
            $table->string('area', 1)->nullable();
            $table->foreignId('attivita_id')->nullable()->constrained('attivita')->nullOnDelete();
            $table->foreignId('iniziativa_id')->nullable()->constrained('iniziative')->nullOnDelete();
            $table->foreignId('tag_id')->nullable()->constrained('tag')->nullOnDelete();
            $table->timestamps();
        });

        // causale, verso, tipologia, area, attività, iniziativa, tag
        $righe = [
            ['Palio e Corteo materiali, accessori', 'uscita', 'Materiali e beni di consumo', 'A', 'Lettera i (art. 5 CTS)', 'Palio e Corteo', null],
            ['Palio e Corteo altre spese', 'uscita', 'Materiali e beni di consumo', 'A', 'Lettera i (art. 5 CTS)', 'Palio e Corteo', null],
            ['Acquisto libri', 'uscita', 'Libri e pubblicazioni', 'A', null, null, null],
            ['Materiale di consumo', 'uscita', 'Materiali e beni di consumo', 'E', null, null, null],
            ['Materiale "La Casa"', 'uscita', 'Materiali e beni di consumo', 'E', null, 'La Casa', null],
            ['Strumenti e attrezzature', 'uscita', 'Strumenti e attrezzature', 'A', null, null, null],
            ['Carnevale, org', 'uscita', 'Materiali e beni di consumo', 'A', 'Lettera i (art. 5 CTS)', 'Carnevale', null],
            ['Carnevale, gruppo clt', 'uscita', 'Materiali e beni di consumo', 'A', 'Lettera i (art. 5 CTS)', 'Carnevale', 'Gruppo CLT'],
            ['Infiorata', 'uscita', 'Materiali e beni di consumo', 'A', 'Lettera i (art. 5 CTS)', 'Infiorata', null],
            ['Rappresentanza: materie prime, di consumo, ecc.', 'uscita', 'Spese di rappresentanza', 'E', null, null, null],
            ['Festa della Torre, somministrazione', 'uscita', 'Acquisti per la somministrazione', 'A', 'Lettera i (art. 5 CTS)', 'Festa della Torre', null],
            ['Festa della Torre, altre spese', 'uscita', 'Materiali e beni di consumo', 'A', 'Lettera i (art. 5 CTS)', 'Festa della Torre', null],
            ['Si può fare NO', 'uscita', null, 'A', null, 'Si può fare', null],
            ['Attività natalizie', 'uscita', 'Materiali e beni di consumo', 'A', 'Lettera i (art. 5 CTS)', 'Attività natalizie', null],
            ['Uscite aggregazione e convivialità', 'uscita', 'Acquisti per la somministrazione', 'A', 'Lettera i (art. 5 CTS)', 'Aggregazione e convivialità', null],
            ['Materiale di comunicazione', 'uscita', 'Materiale di comunicazione', 'A', null, null, null],
            ['Strumenti e materiali Si può fare', 'uscita', 'Strumenti e attrezzature', 'A', null, 'Si può fare', null],
            ['Strumenti e materiali MICROPROG Si può fare', 'uscita', 'Strumenti e attrezzature', 'A', null, 'Si può fare', 'Microprogetto'],
            ['Servizi professionali per att int gen', 'uscita', 'Servizi professionali', 'A', null, null, null],
            ['Servizi internet', 'uscita', 'Servizi internet e software', 'E', null, null, null],
            ['Assicurazioni', 'uscita', 'Assicurazioni', 'E', null, null, null],
            ['Formazione volontari', 'uscita', 'Formazione dei volontari', 'A', null, null, null],
            ['Utenze "La Casa"', 'uscita', 'Utenze', 'E', null, 'La Casa', null],
            ['Servizi "La Casa"', 'uscita', 'Servizi vari', 'E', null, 'La Casa', null],
            ['Festa della Torre, servizi', 'uscita', 'Servizi vari', 'A', 'Lettera i (art. 5 CTS)', 'Festa della Torre', null],
            ['Servizi vari Si può fare', 'uscita', 'Servizi vari', 'A', null, 'Si può fare', null],
            ['Servizi MICROPROG Si può fare', 'uscita', 'Servizi vari', 'A', null, 'Si può fare', 'Microprogetto'],
            ['Affitto "La Casa"', 'uscita', 'Affitti e noleggi', 'E', null, 'La Casa', null],
            ['Prestazioni d\'opera Si può fare', 'uscita', 'Prestazioni occasionali', 'A', null, 'Si può fare', null],
            ['Rimborsi spese Si può fare', 'uscita', 'Rimborsi spese ai volontari', 'A', null, 'Si può fare', null],
            ['Quota adesione magistrato', 'uscita', 'Quote associative ad altri enti', 'A', 'Lettera i (art. 5 CTS)', null, null],
            ['Erogazioni liberali ad altri enti', 'uscita', 'Erogazioni liberali ad altri enti', 'A', null, null, null],
            ['Altre spese di rappresentanza', 'uscita', 'Spese di rappresentanza', 'E', null, null, null],
            ['Imposta di bollo c/c', 'uscita', 'Imposte e tasse', 'E', null, null, null],
            ['Imposte festa della Torre', 'uscita', 'Imposte e tasse', 'A', 'Lettera i (art. 5 CTS)', 'Festa della Torre', null],
            ['Imposte Si può fare', 'uscita', 'Imposte e tasse', 'A', null, 'Si può fare', null],
            ['Imposte varie "La Casa"', 'uscita', 'Imposte e tasse', 'E', null, 'La Casa', null],
            ['IVA', 'uscita', 'IVA', 'B', null, null, null],
            ['Altre imposte', 'uscita', 'Imposte e tasse', 'E', null, null, null],
            ['Materiale pubblicitario c/sponsor', 'uscita', 'Materiale di comunicazione', 'B', 'Sponsorizzazioni e pubblicità', null, null],
            ['Provvigioni raccolta pubb', 'uscita', 'Provvigioni', 'B', 'Sponsorizzazioni e pubblicità', null, null],
            ['Ritenuta d\'acc raccolta pubb', 'uscita', 'Provvigioni', 'B', 'Sponsorizzazioni e pubblicità', null, null],
            ['Canoni e commissioni bancarie', 'uscita', 'Commissioni e oneri bancari', 'D', null, null, null],
            ['Tesseramento ord', 'entrata', 'Quote associative', 'A', null, null, null],
            ['Entrate aggregazione e convivialità', 'entrata', 'Somministrazione ai soci', 'A', 'Lettera i (art. 5 CTS)', 'Aggregazione e convivialità', null],
            ['2) Entrate dagli associati per attività mutuali', 'entrata', 'Corrispettivi dei soci per attività', 'A', null, null, null],
            ['Vendita bandiere', 'entrata', 'Cessioni ai soci', 'B', 'Vendita di beni', null, null],
            ['Erogazioni liberali', 'entrata', 'Erogazioni liberali', 'A', null, null, null],
            ['Entrata 5 per mille', 'entrata', '5 per mille', 'A', null, null, null],
            ['Palio e corteo, contrib. Magistrato', 'entrata', 'Contributi da privati', 'A', 'Lettera i (art. 5 CTS)', 'Palio e Corteo', null],
            ['Festa della Torre, entrate somministrazione', 'entrata', 'Somministrazione', 'A', 'Lettera i (art. 5 CTS)', 'Festa della Torre', null],
            ['Contributo Carnevale da comune', 'entrata', 'Contributi da enti pubblici', 'A', 'Lettera i (art. 5 CTS)', 'Carnevale', null],
            ['Contributo Si può fare da comune', 'entrata', 'Contributi da enti pubblici', 'A', null, 'Si può fare', null],
            ['Contributo Si può fare da ministero', 'entrata', 'Contributi da enti pubblici', 'A', null, 'Si può fare', null],
            ['Altre entrate', 'entrata', 'Altre entrate', 'A', null, null, null],
            ['Entrate MICROPROG Si può fare', 'entrata', 'Altre entrate', 'A', null, 'Si può fare', 'Microprogetto'],
            ['Pubblicità e sponsorizzazioni', 'entrata', 'Sponsorizzazioni e pubblicità', 'B', 'Sponsorizzazioni e pubblicità', null, null],
            ['Collaborazione Locanda', 'entrata', 'Corrispettivi da terzi', 'B', 'Collaborazioni con esercizi commerciali', null, null],
            ['Competenze e interessi c/c', 'entrata', 'Interessi attivi', 'D', null, null, null],
            ['Prelievo', 'giroconto', null, null, null, null, null],
            ['Versamento', 'giroconto', null, null, null, null, null],
        ];
        $ora = ['created_at' => now(), 'updated_at' => now()];
        foreach ($righe as [$causale, $verso, $tipologia, $area, $attivita, $iniziativa, $tag]) {
            DB::table('conversione_causali')->insert([
                'causale' => $causale, 'chiave' => mb_strtolower(trim($causale)), 'verso' => $verso,
                'tipologia_id' => $tipologia === null ? null : DB::table('tipologie')->where('nome', $tipologia)->value('id'),
                'area' => $area,
                'attivita_id' => $attivita === null ? null : DB::table('attivita')->where(['area' => $area, 'nome' => $attivita])->value('id'),
                'iniziativa_id' => $iniziativa === null ? null : DB::table('iniziative')->where('nome', $iniziativa)->value('id'),
                'tag_id' => $tag === null ? null : DB::table('tag')->where('nome', $tag)->value('id'),
            ] + $ora);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversione_causali');
    }
};
