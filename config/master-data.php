<?php

use App\Models\Country;
use App\Models\District;
use App\Models\DocumentType;
use App\Models\PipelineStage;
use App\Models\Province;
use App\Models\Regency;
use App\Models\RequirementDefinition;
use App\Models\Village;

return [
    'provinsi' => ['label' => 'Provinsi', 'model' => Province::class, 'parent_model' => Country::class, 'parent_field' => 'country_id', 'parent_label' => 'Negara', 'fields' => ['code' => 'Kode', 'name' => 'Nama']],
    'kabupaten-kota' => ['label' => 'Kabupaten / Kota', 'model' => Regency::class, 'parent_model' => Province::class, 'parent_field' => 'province_id', 'parent_label' => 'Provinsi', 'fields' => ['code' => 'Kode', 'name' => 'Nama']],
    'kecamatan' => ['label' => 'Kecamatan', 'model' => District::class, 'parent_model' => Regency::class, 'parent_field' => 'regency_id', 'parent_label' => 'Kabupaten / Kota', 'fields' => ['code' => 'Kode', 'name' => 'Nama']],
    'desa-kelurahan' => ['label' => 'Desa / Kelurahan', 'model' => Village::class, 'parent_model' => District::class, 'parent_field' => 'district_id', 'parent_label' => 'Kecamatan', 'fields' => ['code' => 'Kode', 'name' => 'Nama', 'postal_code' => 'Kode Pos']],
    'jenis-dokumen' => ['label' => 'Jenis Dokumen', 'model' => DocumentType::class, 'unique_code' => true, 'fields' => ['code' => 'Kode', 'name' => 'Nama', 'category' => 'Kategori', 'issuer_category' => 'Kategori Penerbit', 'max_file_size_kb' => 'Ukuran Maksimal File (KB)', 'allowed_file_types' => 'Format File', 'verification_help_text' => 'Petunjuk Verifikasi', 'has_expiry' => 'Memiliki Masa Berlaku', 'is_active' => 'Status Aktif']],
    'jenis-persyaratan' => ['label' => 'Jenis Persyaratan', 'model' => RequirementDefinition::class, 'unique_code' => true, 'fields' => ['code' => 'Kode', 'name' => 'Nama', 'category' => 'Kategori', 'data_type' => 'Tipe Data', 'unit' => 'Satuan', 'is_active' => 'Status Aktif']],
    'tahapan-proses' => ['label' => 'Tahapan Proses', 'model' => PipelineStage::class, 'unique_code' => true, 'fields' => ['code' => 'Kode', 'name' => 'Nama', 'sequence' => 'Urutan', 'sla_hours' => 'Batas Waktu (jam)', 'is_terminal' => 'Tahap Akhir', 'is_active' => 'Status Aktif']],
];
