<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProvinceFactory extends Factory
{
    public function definition(): array
    {
        return ['country_id' => Country::factory(), 'code' => fake()->unique()->numerify('######'), 'name' => fake()->city()];
    }
}
