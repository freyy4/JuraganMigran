<?php

return [
    'readiness_weights' => ['documents' => 30, 'medical' => 25, 'interview' => 20, 'preferences' => 15, 'departure' => 10],
    'stages' => [
        'Lead' => ['lead', 'registration', 'pendaftaran'],
        'Screening' => ['screen', 'qualification', 'kualifikasi', 'interview', 'wawancara'],
        'Dokumen' => ['document', 'dokumen', 'passport', 'paspor', 'visa'],
        'Medical' => ['medical', 'mcu', 'kesehatan'],
        'Penempatan' => ['placement', 'penempatan', 'departure', 'keberangkatan', 'training', 'pelatihan', 'opp'],
        'Selesai' => ['completed', 'selesai', 'arrival', 'kedatangan'],
    ],
];
