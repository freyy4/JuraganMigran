<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationQualificationResult;
use App\Models\RequirementDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationQualificationResultFactory extends Factory
{
    protected $model = ApplicationQualificationResult::class;

    public function definition(): array
    {
        return ['application_id' => Application::factory(), 'vacancy_requirement_id' => function (array $attributes): int {
            return Application::findOrFail($attributes['application_id'])->vacancy->requirements()->create(['requirement_definition_id' => RequirementDefinition::factory()->create()->id, 'operator' => 'eq', 'value' => fake()->word()])->id;
        }, 'value' => fake()->word(), 'result' => 'not_checked'];
    }
}
