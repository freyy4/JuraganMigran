<?php

namespace Database\Factories;

use App\Models\Candidate;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

class CandidateFactory extends Factory
{
    protected $model = Candidate::class;

    public function definition(): array
    {
        return ['person_id' => Person::factory(), 'candidate_code' => 'CPMI-'.fake()->unique()->numerify('########'), 'status' => 'lead', 'registered_at' => now()];
    }
}
