<?php

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

class PersonFactory extends Factory
{
    protected $model = Person::class;

    public function definition(): array
    {
        return ['full_name' => fake()->name(), 'nik' => fake()->unique()->numerify('################'), 'whatsapp' => fake()->numerify('081#########'), 'birth_date' => '1995-01-01', 'education_level' => 'SMA'];
    }
}
