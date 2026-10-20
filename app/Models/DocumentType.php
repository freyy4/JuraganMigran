<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'category', 'has_expiry', 'is_active', 'issuer_category', 'allowed_file_types', 'max_file_size_kb', 'verification_help_text'];

    protected static function booted(): void
    {
        static::saving(function (self $type): void {
            $type->max_file_size_kb ??= 10240;
            $label = strtolower($type->code.' '.$type->name);
            if (preg_match('/mcu|medical|kesehatan|health/', $label)) {
                $type->sensitive_class = 'HEALTH';
            } elseif (preg_match('/ktp|nik|passport|paspor|kartu keluarga|akta/', $label)) {
                $type->sensitive_class = 'IDENTITY';
            }
        });
    }

    protected function casts(): array
    {
        return ['allowed_file_types' => 'array', 'has_expiry' => 'boolean', 'is_active' => 'boolean'];
    }
}
