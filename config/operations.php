<?php

$read = ['holiday.read', 'mobility.read'];
$manage = ['holiday.read', 'holiday.manage', 'holiday.policy.manage', 'leave.request', 'leave.review', 'mobility.read', 'mobility.manage', 'transport.batch.manage', 'transport.route.manage', 'qrpass.issue', 'qrpass.scan', 'handover.manage', 'document.custody.read', 'document.custody.update'];
$tracking = ['tracking.destination.manage', 'tracking.eventmap.manage', 'tracking.destination.read', 'tracking.destination.create', 'tracking.destination.update', 'tracking.destination.test', 'tracking.event_map.manage', 'funnel.tracking.read', 'funnel.tracking.update', 'tracking.diagnostics.read'];

return [
    'permissions' => array_merge($manage, ['leave.override', 'tracking.secret.manage'], $tracking),
    'role_permissions' => ['cs' => array_merge($read, ['leave.request']), 'supervisor' => array_merge($manage, ['leave.override', 'tracking.diagnostics.read']), 'admin_operations' => $manage, 'marketing' => $tracking, 'management' => ['tracking.diagnostics.read'], 'super_admin' => array_merge($manage, ['leave.override', 'tracking.secret.manage'], $tracking)],
    'modes' => ['JM_PICKUP_SERVICE' => 'Jemput JM', 'SELF_TRAVEL' => 'Mandiri', 'PARTNER_TRANSPORT' => 'Transport Mitra', 'PUBLIC_TRANSPORT_GROUP' => 'Angkutan Umum Bersama', 'OTHER_APPROVED' => 'Lainnya Disetujui'],
    'purposes' => ['MCU' => 'Menuju MCU', 'PT' => 'Menuju PT / Sponsor', 'HANDOVER' => 'Serah-terima dokumen', 'LPK' => 'Masuk LPK', 'AIRPORT' => 'Menuju Bandara', 'HOLIDAY' => 'Libur / Kembali LPK', 'RETURN' => 'Perjalanan Pulang'],
    'eligibilities' => ['ALLOWED' => 'Boleh pulang', 'RESTRICTED' => 'Pulang dibatasi', 'NOT_ALLOWED' => 'Tidak diizinkan pulang', 'REQUIRES_REVIEW' => 'Perlu ditinjau', 'EXCEPTION_APPROVED' => 'Pengecualian disetujui'],
    'statuses' => ['PLANNED' => 'Direncanakan', 'APPROVED' => 'Disetujui', 'LEFT_LPK' => 'Keluar LPK', 'AT_HOME' => 'Di rumah', 'RETURN_SCHEDULED' => 'Kembali terjadwal', 'RETURNING' => 'Dalam perjalanan kembali', 'CHECKED_IN' => 'Sudah check-in', 'OVERDUE_RETURN' => 'Terlambat kembali', 'CANCELLED' => 'Dibatalkan', 'TRAVELLING' => 'Dalam perjalanan', 'DELAYED' => 'Terlambat', 'NO_SHOW' => 'Tidak hadir', 'CLOSED' => 'Selesai'],
    'events' => ['PAGE_VIEW', 'VIEW_VACANCY', 'FUNNEL_VIEW', 'CTA_CLICK', 'THANK_YOU_VIEW', 'FORM_STEP_COMPLETED', 'FORM_START', 'FORM_STEP_COMPLETE', 'FORM_SUBMIT', 'LEAD_CREATED', 'WHATSAPP_CLICK', 'QUALIFIED_LEAD', 'INTERVIEW_COMPLETED', 'MCU_SCHEDULED', 'MCU_REACHED', 'LPK_JOINED', 'FLIGHT_READY', 'DEPARTED', 'REFERRAL_ATTRIBUTED', 'CALLBACK_REQUESTED', 'APPOINTMENT_CONFIRMED'],
    'browser_events' => ['PAGE_VIEW', 'VIEW_VACANCY', 'FUNNEL_VIEW', 'CTA_CLICK', 'THANK_YOU_VIEW', 'FORM_STEP_COMPLETED', 'FORM_START', 'FORM_STEP_COMPLETE', 'WHATSAPP_CLICK'],
    'tracking' => ['meta_version' => env('META_GRAPH_VERSION', 'v25.0'), 'google_version' => env('GOOGLE_ADS_VERSION', 'v22'), 'max_attempts' => 5],
];
