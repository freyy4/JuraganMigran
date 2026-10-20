<?php

namespace Database\Factories;

use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;

class RegencyFactory extends Factory
{
    public function definition(): array
    {
        return ['province_id' => Province::factory(), 'code' => fake()->unique()->numerify('######'), 'name' => fake()->city()];
    }
}
