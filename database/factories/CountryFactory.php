<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

class CountryFactory extends Factory
{
    protected $model = Country::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->lexify('???'), 'name' => fake()->country(), 'is_active' => true];
    }
}
