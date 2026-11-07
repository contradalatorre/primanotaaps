<?php

namespace Database\Factories;

use App\Models\Conto;
use App\Models\Movimento;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Movimento>
 */
class MovimentoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conto_id' => Conto::factory(),
            'data' => '2026-05-10',
            'importo' => 1250,
            'verso' => 'uscita',
            'descrizione' => 'Ghiaccio per la festa',
            'stato' => 'da_classificare',
            'manuale' => true,
            'inserito_da' => User::factory(),
        ];
    }

    public function confermato(): static
    {
        return $this->state(fn () => ['stato' => 'confermato', 'confermato_il' => now(), 'confermato_da' => User::factory()]);
    }
}
