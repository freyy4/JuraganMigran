<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequirementDefinition extends Model
{
    use HasFactory;

    public const DATA_TYPES = ['string' => 'Teks', 'integer' => 'Bilangan Bulat', 'number' => 'Angka', 'decimal' => 'Desimal', 'boolean' => 'Ya / Tidak', 'date' => 'Tanggal', 'int' => 'Bilangan Bulat (int)', 'numeric' => 'Angka (numeric)', 'float' => 'Desimal (float)', 'bool' => 'Ya / Tidak (bool)'];

    protected $fillable = ['code', 'name', 'category', 'data_type', 'unit', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function vacancyRequirements(): HasMany
    {
        return $this->hasMany(VacancyRequirement::class);
    }

    public function inputType(): string
    {
        return match ($this->data_type) {
            'integer','int','number','numeric','decimal','float' => 'number','boolean','bool' => 'boolean','date' => 'date',default => 'text'
        };
    }

    public function operators(): array
    {
        $operators = ['eq' => 'Sama dengan', 'neq' => 'Tidak sama dengan'];

        return match ($this->inputType()) {
            'number' => [...$operators, 'gte' => 'Minimal', 'lte' => 'Maksimal', 'gt' => 'Lebih dari', 'lt' => 'Kurang dari', 'between' => 'Rentang'], 'date' => [...$operators, 'gte' => 'Pada atau setelah', 'lte' => 'Pada atau sebelum'], 'boolean' => $operators, default => [...$operators, 'contains' => 'Mengandung', 'in' => 'Salah satu dari']
        };
    }
}
