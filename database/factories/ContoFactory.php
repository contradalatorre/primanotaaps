<?php

namespace Database\Factories;

use App\Models\Conto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conto>
 */
class ContoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tipo' => 'cassa',
            'nome' => 'Cassa '.fake()->unique()->word(),
            'formato_import' => 'manuale',
            'riga_modello_d' => 'cassa',
            'aperto_il' => '2026-01-01',
            'chiuso_il' => null,
            'saldo_iniziale' => 0,
        ];
    }
}
