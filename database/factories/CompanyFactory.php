<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('MITRA-########'), 'name' => fake()->company(), 'type' => 'sponsor', 'status' => 'active'];
    }
}
