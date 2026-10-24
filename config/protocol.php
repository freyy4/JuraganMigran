<?php

return [
    'enabled' => true,
    'new_application_mode' => env('PROTOCOL_NEW_APPLICATION_MODE', 'SHADOW'),
    'golden_hours' => [24, 48, 72],
    'result_hours' => 48,
    'rubric' => ['process' => 'Pemahaman proses', 'commitment' => 'Keseriusan mengikuti proses', 'training' => 'Kesiapan training dan aturan', 'family' => 'Dukungan keluarga', 'documents' => 'Kesiapan dokumen', 'practical' => 'Hambatan praktis / ekonomi', 'objections' => 'Pertanyaan yang belum selesai'],
    'blockers' => ['NO_RESPONSE' => 'Tidak bisa dihubungi', 'FAMILY' => 'Keluarga masih ragu', 'DOCUMENT' => 'Dokumen kurang / tidak valid', 'HEALTH' => 'Klarifikasi kesehatan', 'INTERVIEW_CAPACITY' => 'Kapasitas interviewer', 'APPOINTMENT_CAPACITY' => 'Kapasitas kunjungan', 'CLINIC_DELAY' => 'Hasil klinik terlambat', 'TRANSPORT' => 'Transport / ekonomi', 'LPK_CAPACITY' => 'Kapasitas LPK', 'PARTNER' => 'Respons mitra', 'SUPPORT' => 'Persetujuan dukungan', 'OTHER' => 'Kendala lain yang ditinjau'],
    'stages' => [
        'opt_in' => ['label' => 'Opt-in percakapan', 'queue' => 'LEAD_CS', 'permission' => 'protocol.lead', 'hours' => 4, 'action' => 'Konfirmasi minat dari percakapan masuk'],
        'trust' => ['label' => 'Trust & legalitas', 'queue' => 'LEAD_CS', 'permission' => 'protocol.lead', 'hours' => 24, 'action' => 'Bagikan Trust Pack disetujui dan konfirmasi pemahaman'],
        'value' => ['label' => 'Value & opportunity', 'queue' => 'LEAD_CS', 'permission' => 'protocol.lead', 'hours' => 24, 'action' => 'Jelaskan fakta lowongan aktif dan konfirmasi ingin lanjut'],
        'qualification' => ['label' => 'Kualifikasi awal', 'queue' => 'LEAD_CS', 'permission' => 'protocol.lead', 'hours' => 24, 'action' => 'Lengkapi evaluasi persyaratan lowongan dari profil'],
        'health' => ['label' => 'Health pre-screening', 'queue' => 'LEAD_CS', 'permission' => 'protocol.lead', 'hours' => 24, 'action' => 'Selesaikan screening awal melalui modul kesehatan'],
        'documents' => ['label' => 'Dokumen & family screening', 'queue' => 'LEAD_CS', 'permission' => 'protocol.lead', 'hours' => 48, 'action' => 'Identifikasi dokumen kurang dan kontak pemberi izin'],
        'interview' => ['label' => 'Senior CS interview', 'queue' => 'SENIOR_CS', 'permission' => 'protocol.senior', 'hours' => 24, 'action' => 'Lakukan wawancara dengan tujuh rubric'],
        'family' => ['label' => 'Family commitment', 'queue' => 'SENIOR_CS', 'permission' => 'protocol.senior', 'hours' => 48, 'action' => 'Tuntaskan komitmen keluarga sesuai aturan program'],
        'appointment' => ['label' => 'Office appointment', 'queue' => 'OPS', 'permission' => 'protocol.ops', 'hours' => 24, 'action' => 'Konfirmasi kunjungan, dokumen asli, transport dan PIC'],
        'custody_mcu' => ['label' => 'Dokumen asli + MCU', 'queue' => 'OPS_MEDICAL', 'permission' => 'protocol.ops', 'hours' => 24, 'action' => 'Konfirmasi kehadiran, custody dan jadwal MCU'],
        'waiting' => ['label' => 'Menunggu hasil MCU', 'queue' => 'MEDICAL', 'permission' => 'protocol.medical', 'hours' => 48, 'action' => 'Tindak lanjuti hasil klinik dan kabar CPMI'],
        'result' => ['label' => 'Hasil MCU', 'queue' => 'MEDICAL', 'permission' => 'protocol.medical', 'hours' => 24, 'action' => 'Konfirmasi hasil resmi dan tindak lanjut'],
        'golden' => ['label' => 'FIT Golden Window', 'queue' => 'FIT_TO_LPK', 'permission' => 'protocol.placement', 'hours' => 24, 'action' => 'Siapkan batch LPK, transport, dukungan dan handover'],
        'onboarding' => ['label' => 'LPK onboarding', 'queue' => 'PLACEMENT', 'permission' => 'protocol.placement', 'hours' => 48, 'action' => 'Konfirmasi masuk LPK dan jadwal check-in'],
        'country' => ['label' => 'Country process', 'queue' => 'PLACEMENT_OPS', 'permission' => 'protocol.placement', 'hours' => 72, 'action' => 'Lanjutkan template proses negara, termasuk TETO bila diwajibkan'],
    ],
];
