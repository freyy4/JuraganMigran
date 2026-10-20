<?php

namespace Database\Seeders;

use App\Models\HealthScreeningTemplate;
use App\Models\HealthTemplateVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HealthDocumentOperationsSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'TB_HISTORY' => ['Riwayat penyakit', 'Apakah Anda pernah didiagnosis atau menjalani pengobatan TBC/TB?', 'Tahun/periode dan apakah pengobatan sudah selesai.'],
            'HEPATITIS_HISTORY' => ['Riwayat penyakit', 'Apakah Anda pernah diberi tahu tenaga kesehatan bahwa Anda memiliki riwayat hepatitis?', 'Jelaskan informasi yang diketahui dari tenaga kesehatan.'],
            'TATTOO_PRESENT' => ['Kondisi fisik', 'Apakah Anda memiliki tato?', 'Lokasi tato dan apakah terlihat.'],
            'VISION_ISSUE' => ['Kondisi fisik', 'Apakah Anda memiliki gangguan penglihatan yang diketahui?', 'Jenis gangguan, penggunaan kacamata, atau informasi lain yang diketahui.'],
            'COLOR_VISION_KNOWN' => ['Kondisi fisik', 'Apakah Anda pernah diberi tahu mengalami gangguan penglihatan warna/buta warna?', 'Informasi pemeriksaan yang diketahui.'],
            'VISIBLE_BIRTHMARK' => ['Kondisi fisik', 'Apakah ada tanda lahir yang terlihat jelas dan perlu dicatat untuk identifikasi/proses?', 'Lokasi untuk kebutuhan identifikasi.'],
            'SURGERY_OR_MAJOR_SCAR' => ['Riwayat tindakan', 'Apakah Anda pernah menjalani operasi atau memiliki bekas luka besar yang perlu dicatat?', 'Jenis/lokasi dan perkiraan tahun.'],
            'FRACTURE_HISTORY' => ['Riwayat tindakan', 'Apakah Anda pernah mengalami patah tulang?', 'Bagian tubuh, perkiraan tahun, dan keluhan saat ini.'],
            'HIV_KNOWN_STATUS' => ['Terbatas', 'Apakah ada informasi pemeriksaan HIV yang perlu disampaikan kepada petugas medis berwenang?', 'Hanya dengan dasar kebijakan dan persetujuan Compliance.'],
            'TPHA_VDRL_HISTORY' => ['Terbatas', 'Apakah Anda pernah mendapatkan hasil pemeriksaan sifilis/TPHA/VDRL yang dinyatakan perlu tindak lanjut oleh tenaga kesehatan?', 'Hanya dengan dasar kebijakan dan persetujuan Compliance.'],
            'ILLICIT_DRUG_HISTORY' => ['Terbatas', 'Apakah ada riwayat penggunaan narkotika/obat terlarang yang perlu Anda sampaikan untuk proses pemeriksaan kesehatan?', 'Akses terbatas sesuai kebijakan.'],
            'CURRENT_MENSTRUATION' => ['Situasional', 'Apakah saat ini sedang menstruasi?', 'Hanya bila relevan untuk persiapan pemeriksaan.'],
            'VAGINAL_DISCHARGE' => ['Situasional', 'Apakah saat ini ada keluhan keputihan yang ingin disampaikan untuk pemeriksaan kesehatan?', 'Hanya bila relevan untuk pemeriksaan.'],
        ];
        $ids = [];
        foreach ($catalog as $code => [$category, $label, $helper]) {
            $restricted = in_array($code, ['HIV_KNOWN_STATUS', 'TPHA_VDRL_HISTORY', 'ILLICIT_DRUG_HISTORY', 'CURRENT_MENSTRUATION', 'VAGINAL_DISCHARGE'], true);
            $yesNo = ['TIDAK' => 'Tidak', 'YA' => 'Ya', 'TIDAK_TAHU' => 'Tidak tahu'];
            $fields = match ($code) {
                'TB_HISTORY' => [['key' => 'period', 'label' => 'Tahun/periode pengobatan', 'type' => 'SHORT_TEXT_CONDITIONAL', 'required' => true], ['key' => 'treatment_finished', 'label' => 'Pengobatan sudah selesai?', 'type' => 'SINGLE_SELECT', 'options' => $yesNo, 'required' => true]],
                'TATTOO_PRESENT' => [['key' => 'location', 'label' => 'Lokasi tato', 'type' => 'SHORT_TEXT_CONDITIONAL', 'required' => true]],
                'VISION_ISSUE' => [['key' => 'issues', 'label' => 'Gangguan penglihatan yang diketahui', 'type' => 'MULTI_SELECT', 'required' => true, 'options' => ['RABUN_JAUH' => 'Rabun jauh', 'RABUN_DEKAT' => 'Rabun dekat', 'KACAMATA' => 'Menggunakan kacamata', 'LAINNYA' => 'Lainnya', 'TIDAK_TAHU' => 'Tidak tahu']]],
                'SURGERY_OR_MAJOR_SCAR' => [['key' => 'location', 'label' => 'Jenis / lokasi', 'type' => 'SHORT_TEXT_CONDITIONAL', 'required' => true], ['key' => 'year', 'label' => 'Perkiraan tahun', 'type' => 'NUMBER', 'required' => false]],
                'FRACTURE_HISTORY' => [['key' => 'body_part', 'label' => 'Bagian tubuh', 'type' => 'SHORT_TEXT_CONDITIONAL', 'required' => true], ['key' => 'year', 'label' => 'Perkiraan tahun', 'type' => 'NUMBER', 'required' => false], ['key' => 'current_complaint', 'label' => 'Ada keluhan saat ini?', 'type' => 'SINGLE_SELECT', 'options' => $yesNo, 'required' => true]],
                default => [],
            };
            $options = $code === 'ILLICIT_DRUG_HISTORY' ? ['TIDAK' => 'Tidak', 'YA' => 'Ya', 'TIDAK_BERSEDIA_MENJAWAB' => 'Tidak bersedia menjawab'] : ($code === 'TPHA_VDRL_HISTORY' ? [...$yesNo, 'BELUM_PERNAH_DIPERIKSA' => 'Belum pernah diperiksa'] : $yesNo);
            if (! DB::table('health_questions')->where('code', $code)->exists()) {
                DB::table('health_questions')->insert(['code' => $code, 'category' => $category, 'question' => $label, 'helper_text' => $helper, 'answer_type' => 'YES_NO_UNKNOWN', 'options' => json_encode($options), 'clarification_rule' => json_encode(['when' => ['YA'], 'fields' => $fields]), 'followup_rule' => json_encode(['when' => ['YA'], 'outcome' => 'NEEDS_CLARIFICATION']), 'applicability_rule' => in_array($code, ['CURRENT_MENSTRUATION', 'VAGINAL_DISCHARGE'], true) ? json_encode(['field' => 'gender', 'values' => ['female', 'perempuan', 'P']]) : null, 'restricted' => $restricted, 'is_required' => ! $restricted, 'is_active' => true, 'sequence' => count($ids), 'created_at' => now(), 'updated_at' => now()]);
            }
            if (! $restricted) {
                $ids[] = DB::table('health_questions')->where('code', $code)->value('id');
            }
        }
        if (! HealthScreeningTemplate::where('name', 'Pemeriksaan Awal Standar JM')->exists()) {
            $questions = DB::table('health_questions')->whereIn('id', $ids)->orderBy('sequence')->get()->map(function ($q): array {
                $row = (array) $q;
                foreach (['options', 'clarification_rule', 'followup_rule', 'applicability_rule'] as $field) {
                    $row[$field] = $q->$field ? json_decode($q->$field, true) : null;
                }

                return $row;
            })->all();
            $template = HealthScreeningTemplate::create(['name' => 'Pemeriksaan Awal Standar JM', 'scope_type' => 'GLOBAL', 'status' => 'published', 'draft_schema' => ['question_ids' => $ids]]);
            HealthTemplateVersion::create(['health_screening_template_id' => $template->id, 'version' => 1, 'snapshot' => ['questions' => $questions], 'published_at' => now()]);
        }
        $featurePermissions = ['document.view.file', 'health.template.read', 'health.template.manage_draft', 'health.template.publish', 'health.question.manage', 'health.prescreen.read', 'health.prescreen.write', 'health.prescreen.complete', 'health.prescreen.review', 'document.type.read', 'document.type.manage', 'document.requirement.read', 'document.requirement.manage', 'document.request_correction', 'document.exception.approve', 'document.followup'];
        foreach (config('governance.roles') as $code => $definition) {
            $role = DB::table('crm_roles')->where('code', $code)->first();
            if ($role) {
                DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...json_decode($role->permissions, true), ...array_values(array_intersect($definition[2], $featurePermissions))]))), 'updated_at' => now()]);
            }
        }
        $role = DB::table('crm_roles')->where('code', 'migration_owner')->first();
        if ($role) {
            DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...json_decode($role->permissions, true), ...$featurePermissions])))]);
        }
    }
}
