<?php

namespace Database\Factories;

use App\Models\InterviewQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

class InterviewQuestionFactory extends Factory
{
    protected $model = InterviewQuestion::class;

    public function definition(): array
    {
        return ['question' => fake()->sentence(), 'input_type' => 'text', 'sequence' => 0, 'is_required' => true, 'is_active' => true];
    }
}
