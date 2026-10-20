<?php

namespace Database\Factories;

use App\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

class VillageFactory extends Factory
{
    public function definition(): array
    {
        return ['district_id' => District::factory(), 'code' => fake()->unique()->numerify('######'), 'name' => fake()->city()];
    }
}
