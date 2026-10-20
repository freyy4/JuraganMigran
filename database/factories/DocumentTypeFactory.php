<?php

namespace Database\Factories;

use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('DOC-########'), 'name' => fake()->words(2, true), 'has_expiry' => true, 'is_active' => true];
    }
}
