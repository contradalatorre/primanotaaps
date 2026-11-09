<?php

namespace App\Onboarding;

use App\Permesso;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Le missioni e i percorsi, letti dai file YAML del repo (`resources/onboarding`): i testi cambiano con una pull request.
 * Un campo mancante o sbagliato è un errore che dice file e passo, non un tutorial rotto in silenzio.
 */
final class Catalogo
{
    public const POSE = ['saluta', 'indica', 'pensa', 'festeggia', 'preoccupato'];

    private ?Collection $missioni = null;

    public function __construct(private readonly string $cartella) {}

    public static function predefinito(): self
    {
        static $catalogo;

        return $catalogo ??= new self(resource_path('onboarding'));
    }

    /** @return Collection<string, Missione> per id, nell'ordine dei percorsi e poi della missione */
    public function missioni(): Collection
    {
        return $this->missioni ??= $this->leggiMissioni();
    }

    public function missione(string $id): ?Missione
    {
        return $this->missioni()->get($id);
    }

    /** @return Collection<string, array{id: string, titolo: string}> nell'ordine del file */
    public function percorsi(): Collection
    {
        return collect(Yaml::parseFile($this->cartella.'/percorsi.yml'))->keyBy('id');
    }

    public function percorso(string $id): ?array
    {
        return $this->percorsi()->get($id);
    }

    private function leggiMissioni(): Collection
    {
        $ordineDeiPercorsi = $this->percorsi()->keys()->flip();
        $missioni = collect(glob($this->cartella.'/missioni/*.yml'))->map(fn (string $file) => $this->missioneDa(basename($file, '.yml'), Yaml::parseFile($file)));

        return $missioni
            ->sortBy([fn (Missione $a, Missione $b) => ($ordineDeiPercorsi[$a->percorso] ?? 99) <=> ($ordineDeiPercorsi[$b->percorso] ?? 99), ['ordine', 'asc']])
            ->keyBy('id');
    }

    private function missioneDa(string $id, array $d): Missione
    {
        $serve = function (array $dati, string $campo, string $dove) use ($id) {
            if (! isset($dati[$campo]) || $dati[$campo] === '' || $dati[$campo] === []) {
                throw new InvalidArgumentException("$id: $dove non ha «{$campo}»");
            }

            return $dati[$campo];
        };

        $passi = [];
        foreach (array_values($serve($d, 'passi', 'la missione')) as $n => $p) {
            $dove = 'il passo '.($n + 1);
            $posa = $serve($p, 'posa', $dove);
            if (! in_array($posa, self::POSE, true)) {
                throw new InvalidArgumentException("$id: $dove ha una posa sconosciuta ($posa)");
            }
            $passi[] = new Passo(
                $serve($p, 'schermata', $dove), $serve($p, 'elemento', $dove), $posa, trim($serve($p, 'frase', $dove)),
                isset($p['verifica']) ? (string) $p['verifica'] : null, (int) ($p['almeno'] ?? 1),
            );
        }

        return new Missione(
            $id, $serve($d, 'titolo', 'la missione'), (int) $serve($d, 'versione', 'la missione'), $d['cosa_e_cambiato'] ?? null,
            $serve($d, 'percorso', 'la missione'), (int) ($d['ordine'] ?? 1), (string) ($d['durata'] ?? ''),
            array_map(fn (string $p) => Permesso::from($p), $serve($d, 'permessi', 'la missione')),
            $serve($d, 'scenario', 'la missione'), (bool) ($d['telefono'] ?? false), $passi,
        );
    }
}
