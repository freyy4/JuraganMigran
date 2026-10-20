<?php

namespace Database\Factories;

use App\Models\Regency;
use Illuminate\Database\Eloquent\Factories\Factory;

class DistrictFactory extends Factory
{
    public function definition(): array
    {
        return ['regency_id' => Regency::factory(), 'code' => fake()->unique()->numerify('######'), 'name' => fake()->city()];
    }
}
