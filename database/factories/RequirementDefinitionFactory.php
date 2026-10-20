<?php

namespace Database\Factories;

use App\Models\RequirementDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

class RequirementDefinitionFactory extends Factory
{
    protected $model = RequirementDefinition::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('REQ-########'), 'name' => fake()->words(3, true), 'category' => 'Umum', 'data_type' => 'string', 'is_active' => true];
    }
}
