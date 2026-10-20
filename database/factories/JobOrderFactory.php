<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Country;
use App\Models\JobOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class JobOrderFactory extends Factory
{
    protected $model = JobOrder::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('JO-########'), 'company_id' => Company::factory(), 'country_id' => Country::factory(), 'status' => 'draft', 'quota' => 10];
    }
}
