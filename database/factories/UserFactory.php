<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->userName().'@associazione.example',
            'google_id' => (string) fake()->unique()->numerify('####################'),
            // Già verificato: i test che entrano con actingAs non fanno chiamate a Google.
            'google_refresh_token' => 'refresh-token-finto',
            'google_verificato_il' => now(),
        ];
    }
}
