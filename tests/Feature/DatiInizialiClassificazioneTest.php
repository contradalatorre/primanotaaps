<?php

namespace Tests\Feature;

use App\Models\Attivita;
use App\Models\Iniziativa;
use App\Models\Tag;
use App\Models\Tipologia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** I dati con cui l'app parte, confrontati riga per riga con la tabella di conversione del repo (docs/import-foglio). */
class DatiInizialiClassificazioneTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string,string>> */
    private function conversione(): array
    {
        $file = fopen(base_path('docs/import-foglio/conversione-causali.csv'), 'r');
        $intestazioni = fgetcsv($file, escape: '');
        $righe = [];
        while (($riga = fgetcsv($file, escape: '')) !== false) {
            $righe[] = array_combine($intestazioni, $riga);
        }
        fclose($file);

        return $righe;
    }

    private function voce(string $area, string $tipologia, int $esercizio = 2026): ?string
    {
        return \DB::table('voci_modello_d')->where(['esercizio' => $esercizio, 'area' => $area, 'tipologia_id' => Tipologia::where('nome', $tipologia)->value('id')])->value('voce');
    }

    public function test_le_tipologie_sono_quelle_della_tabella_di_conversione(): void
    {
        $righe = collect($this->conversione())->filter(fn ($r) => $r['tipologia'] !== '');

        foreach (['uscita', 'entrata'] as $verso) {
            $this->assertEqualsCanonicalizing(
                $righe->where('verso', $verso)->pluck('tipologia')->unique()->values()->all(),
                Tipologia::where('verso', $verso)->pluck('nome')->all(),
                "tipologie di $verso",
            );
        }
        $this->assertSame(21, Tipologia::where('verso', 'uscita')->count());
        $this->assertSame(0, Tipologia::whereNotNull('archiviata_il')->count());
        $this->assertSame(0, Tipologia::where('spiegazione', '')->count());
    }

    public function test_area_e_tipologia_danno_la_voce_della_tabella_di_conversione(): void
    {
        foreach ($this->conversione() as $riga) {
            if ($riga['tipologia'] === '') {
                continue;
            }
            $this->assertSame($riga['voce'], $this->voce($riga['area'], $riga['tipologia']), "{$riga['causale']}: {$riga['area']} + {$riga['tipologia']}");
        }
    }

    public function test_attivita_iniziative_e_tag_sono_quelli_della_tabella_di_conversione(): void
    {
        $righe = collect($this->conversione());

        $this->assertEqualsCanonicalizing(
            $righe->where('area', 'B')->where('attivita', '!=', '')->pluck('attivita')->unique()->values()->all(),
            Attivita::where('area', 'B')->pluck('nome')->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_map(fn ($l) => "Lettera $l (art. 5 CTS)", ['f', 'i', 'k', 'l', 'w', 'z']),
            Attivita::where('area', 'A')->pluck('nome')->all(),
        );

        $this->assertSame(8, Iniziativa::count());
        $this->assertSame(2, Tag::count());
        $this->assertEqualsCanonicalizing($righe->pluck('tag')->filter()->unique()->values()->all(), Tag::pluck('nome')->all());
        foreach ($righe->where('iniziativa', '!=', '')->groupBy('iniziativa') as $nome => $gruppo) {
            $predefiniti = Iniziativa::where('nome', $nome)->firstOrFail()->predefiniti->firstWhere('esercizio', 2026);
            $this->assertSame($gruppo->first()['area'], $predefiniti->area->value, $nome);
            $attivita = $gruppo->pluck('attivita')->filter()->first();
            $atteso = $attivita === null ? null : ($gruppo->first()['area'] === 'A' ? "Lettera $attivita (art. 5 CTS)" : $attivita);
            $this->assertSame($atteso, $predefiniti->attivita?->nome, $nome);
        }
    }

    public function test_le_combinazioni_fuori_tabella_non_hanno_voce(): void
    {
        $this->assertNull($this->voce('B', 'Quote associative'));
        $this->assertNull($this->voce('A', 'Sponsorizzazioni e pubblicità'));
        $this->assertNull($this->voce('A', 'Commissioni e oneri bancari'));
        $this->assertNull($this->voce('A', 'Materiali e beni di consumo', 2027)); // le voci del 2027 arrivano con l'esercizio
    }

    public function test_le_proprieta_fiscali_delle_tipologie_che_le_hanno(): void
    {
        $this->assertSame(['commerciale_per_natura', 'neutra_nel_test_dell_ente'], Tipologia::firstWhere('nome', 'Sponsorizzazioni e pubblicità')->proprieta);
        $this->assertSame(['commerciale_per_natura'], Tipologia::firstWhere('nome', 'Somministrazione')->proprieta);
        $this->assertSame([], Tipologia::firstWhere('nome', 'Quote associative')->proprieta);
    }
}
