<?php

namespace Database\Factories;

use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

class PipelineStageFactory extends Factory
{
    protected $model = PipelineStage::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('STAGE-########'), 'name' => fake()->words(2, true), 'sequence' => fake()->numberBetween(1, 50), 'is_terminal' => false, 'is_active' => true];
    }
}
