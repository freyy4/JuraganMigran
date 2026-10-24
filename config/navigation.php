<?php

$groups = [
    ['label' => 'Dasbor', 'items' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'permission' => 'dashboard.read'],
        ['key' => 'finance-cockpit', 'label' => 'Dashboard Keuangan', 'route' => 'cockpit.index', 'active' => 'cockpit.index', 'parameters' => ['role' => 'finance'], 'permission' => 'finance.sponsor_receivable.read'],
    ]],
    ['label' => 'CRM', 'items' => [
        ['key' => 'protocol-queue', 'label' => 'Trust → Commitment', 'route' => 'protocol.index', 'active' => 'protocol.*', 'permission' => 'process.read'],
        ...config('conversation.enabled') ? [
            ['key' => 'crm-inbox', 'label' => 'Inbox', 'route' => 'crm.inbox', 'active' => 'crm.inbox'],
            ['key' => 'crm-followup', 'label' => 'Lead Follow Up', 'route' => 'crm.followups', 'active' => 'crm.followups'],
            ['key' => 'crm-pipeline', 'label' => 'Pipeline Lead', 'route' => 'crm.pipeline', 'active' => 'crm.pipeline'],
            ['key' => 'crm-settings', 'label' => 'Pengaturan CRM', 'route' => 'crm.settings', 'active' => 'crm.settings'],
        ] : [],
        ['key' => 'prd-leads', 'label' => 'Lead Saya', 'route' => 'acquisition.leads', 'active' => 'acquisition.leads'],
        ['key' => 'prd-requests', 'label' => 'Permintaan CPMI', 'route' => 'portal-admin.requests', 'active' => 'portal-admin.*'],
        ['key' => 'menu-1-0', 'label' => 'Calon Pekerja', 'route' => 'candidates.index', 'active' => 'candidates.*'],
        ['key' => 'menu-1-1', 'label' => 'Lamaran', 'route' => 'applications.index', 'active' => 'applications.*'],
        ['key' => 'menu-1-2', 'label' => 'Alur Proses', 'route' => 'pipeline.index', 'active' => 'pipeline.*'],
        ['key' => 'menu-1-3', 'label' => 'Tugas', 'route' => 'tasks.index', 'active' => 'tasks.*'],
        ['key' => 'menu-1-4', 'label' => 'Tindak Lanjut', 'route' => 'follow-ups.index', 'active' => 'follow-ups.*'],
        ['key' => 'menu-1-5', 'label' => 'Kendala', 'route' => 'blockers.index', 'active' => 'blockers.*'],
    ]],
    ['label' => 'Perekrutan', 'items' => [
        ['key' => 'menu-2-3', 'label' => 'Kampanye', 'route' => 'campaigns.index', 'active' => 'campaigns.*', 'hidden' => true],
        ['key' => 'menu-2-4', 'label' => 'Halaman Pendaftaran', 'route' => 'landing-pages.index', 'active' => 'landing-pages.*', 'hidden' => true],
        ['key' => 'menu-2-0', 'label' => 'Mitra / Sponsor', 'route' => 'companies.index', 'active' => 'companies.*'],
        ['key' => 'menu-2-1', 'label' => 'Permintaan Tenaga Kerja', 'route' => 'job-orders.index', 'active' => 'job-orders.*'],
        ['key' => 'menu-2-2', 'label' => 'Lowongan', 'route' => 'vacancies.index', 'active' => 'vacancies.*'],
    ]],
    ['label' => 'Seleksi', 'items' => [
        ['key' => 'menu-3-0', 'label' => 'Kualifikasi', 'route' => 'qualifications.index', 'active' => 'qualifications.*'],
        ['key' => 'menu-3-1', 'label' => 'Wawancara', 'route' => 'interviews.index', 'active' => 'interviews.*'],
        ['key' => 'menu-3-2', 'label' => 'Pemeriksaan Awal Kesehatan', 'route' => 'health-screenings.index', 'active' => 'health-screenings.*'],
        ['key' => 'menu-3-3', 'label' => 'MCU', 'route' => 'medical-events.index', 'active' => 'medical-events.*'],
    ]],
    ['label' => 'Dokumen', 'items' => [
        ['key' => 'menu-4-0', 'label' => 'Dokumen Calon Pekerja', 'route' => 'documents.index', 'active' => 'documents.*'],
        ['key' => 'menu-4-1', 'label' => 'Verifikasi Dokumen', 'route' => 'document-verifications.index', 'active' => 'document-verifications.*'],
        ['key' => 'menu-4-2', 'label' => 'Dokumen Kurang', 'route' => 'missing-documents.index', 'active' => 'missing-documents.*'],
        ['key' => 'menu-4-3', 'label' => 'Dokumen Kedaluwarsa', 'route' => 'expired-documents.index', 'active' => 'expired-documents.*'],
        ['key' => 'menu-4-4', 'label' => 'Dokumen Asli', 'route' => 'document-custodies.index', 'active' => 'document-custodies.*'],
    ]],
    ['label' => 'Pelatihan', 'items' => [
        ['key' => 'holiday-transport', 'label' => 'Libur LPK & Transport', 'route' => 'holiday-transport.index', 'active' => 'holiday-transport.*'],
        ['key' => 'menu-5-0', 'label' => 'LPK', 'route' => 'companies.index', 'active' => 'companies.*', 'parameters' => ['type' => 'lpk']],
        ['key' => 'menu-5-1', 'label' => 'Angkatan / Kelas', 'route' => 'training-batches.index', 'active' => 'training-batches.*'],
        ['key' => 'menu-5-2', 'label' => 'Peserta Pelatihan', 'route' => 'training.index', 'active' => 'training.*'],
        ['key' => 'menu-5-3', 'label' => 'Izin / Ketidakhadiran', 'route' => 'training-leaves.index', 'active' => 'training-leaves.*'],
        ['key' => 'menu-5-4', 'label' => 'Perkembangan Pelatihan', 'route' => 'training-progress.index', 'active' => 'training-progress.*'],
    ]],
    ['label' => 'Keberangkatan', 'items' => [
        ['key' => 'menu-6-0', 'label' => 'Kesiapan Keberangkatan', 'route' => 'readiness.index', 'active' => 'readiness.*'],
        ['key' => 'menu-6-1', 'label' => 'OPP', 'route' => 'opp.index', 'active' => 'opp.*'],
        ['key' => 'menu-6-2', 'label' => 'Jadwal Penerbangan', 'route' => 'flights.index', 'active' => 'flights.*'],
        ['key' => 'menu-6-3', 'label' => 'Keberangkatan', 'route' => 'departures.index', 'active' => 'departures.*'],
        ['key' => 'menu-6-4', 'label' => 'Kedatangan', 'route' => 'arrivals.index', 'active' => 'arrivals.*'],
    ]],
    ['label' => 'Keuangan', 'items' => [
        ['key' => 'menu-7-0', 'label' => 'Perjanjian Mitra', 'route' => 'agreements.index', 'active' => 'agreements.*'],
        ['key' => 'menu-7-1', 'label' => 'Tahapan Pembayaran', 'route' => 'milestones.index', 'active' => 'milestones.*'],
        ['key' => 'menu-7-2', 'label' => 'Tagihan Mitra', 'route' => 'receivables.index', 'active' => 'receivables.*'],
        ['key' => 'menu-7-3', 'label' => 'Pembayaran Mitra', 'route' => 'sponsor-payments.index', 'active' => 'sponsor-payments.*'],
        ['key' => 'menu-7-4', 'label' => 'Hak CPMI', 'route' => 'benefits.index', 'active' => 'benefits.*'],
        ['key' => 'menu-7-5', 'label' => 'Kewajiban CPMI', 'route' => 'obligations.index', 'active' => 'obligations.*'],
        ['key' => 'menu-7-6', 'label' => 'Penyelesaian Keuangan', 'route' => 'settlements.index', 'active' => 'settlements.*'],
    ]],
    ['label' => 'Website & Funnel', 'items' => [
        ['key' => 'site-builder', 'label' => 'JM Site Builder', 'route' => 'site-builder.index', 'active' => 'site-builder.*'],
        ['key' => 'site-assets', 'label' => 'Aset Website', 'route' => 'site-assets.index', 'active' => 'site-assets.*'],
        ['key' => 'site-analytics', 'label' => 'Analitik Website', 'route' => 'site-analytics.index', 'active' => 'site-analytics.*'],
        ['key' => 'prd-funnels', 'label' => 'Funnel Builder', 'route' => 'funnels.index', 'active' => 'funnels.*'],
    ]],
    ['label' => 'Pemasaran', 'items' => [
        ['key' => 'tracking-events', 'label' => 'Event & Pelacakan Iklan', 'route' => 'tracking.index', 'active' => 'tracking.*'],
        ['key' => 'menu-8-0', 'label' => 'Kampanye', 'route' => 'campaigns.index', 'active' => 'campaigns.*'],
        ['key' => 'menu-8-1', 'label' => 'Halaman Pendaftaran', 'route' => 'landing-pages.index', 'active' => 'landing-pages.*'],
        ['key' => 'menu-8-2', 'label' => 'Sumber Pendaftar', 'route' => 'lead-sources.index', 'active' => 'lead-sources.*'],
        ['key' => 'menu-8-3', 'label' => 'Rujukan', 'route' => 'referrals.index', 'active' => 'referrals.*'],
        ['key' => 'menu-8-4', 'label' => 'Analitik', 'route' => 'marketing-analytics.index', 'active' => 'marketing-analytics.*'],
    ]],
    ['label' => 'Laporan', 'items' => [
        ['key' => 'menu-9-0', 'label' => 'Alur Proses', 'route' => 'reports.pipeline.index', 'active' => 'reports.pipeline.*'],
        ['key' => 'menu-9-1', 'label' => 'Perkembangan CPMI', 'route' => 'reports.progress.index', 'active' => 'reports.progress.*'],
        ['key' => 'menu-9-2', 'label' => 'Kendala', 'route' => 'reports.blockers.index', 'active' => 'reports.blockers.*'],
        ['key' => 'menu-9-3', 'label' => 'Kinerja CS', 'route' => 'reports.cs.index', 'active' => 'reports.cs.*'],
        ['key' => 'menu-9-4', 'label' => 'Kinerja Mitra', 'route' => 'reports.partners.index', 'active' => 'reports.partners.*'],
        ['key' => 'menu-9-5', 'label' => 'Kinerja Lowongan', 'route' => 'reports.vacancies.index', 'active' => 'reports.vacancies.*'],
        ['key' => 'menu-9-6', 'label' => 'Keuangan', 'route' => 'reports.finance.index', 'active' => 'reports.finance.*'],
    ]],
    ['label' => 'Data Master', 'items' => [
        ['key' => 'menu-10-0', 'label' => 'Negara', 'route' => 'countries.index', 'active' => 'countries.*'],
        ['key' => 'menu-10-5', 'label' => 'Posisi Pekerjaan', 'route' => 'master-reference.index', 'active' => 'master-reference.*', 'parameters' => ['reference' => 'posisi-pekerjaan']],
        ['key' => 'menu-10-6', 'label' => 'Jenis Perusahaan', 'route' => 'master-reference.index', 'active' => 'master-reference.*', 'parameters' => ['reference' => 'jenis-perusahaan']],
        ['key' => 'menu-10-7', 'label' => 'Mitra / Sponsor', 'route' => 'companies.index', 'active' => 'companies.*'],
        ['key' => 'menu-10-8', 'label' => 'LPK', 'route' => 'companies.index', 'active' => 'companies.*', 'parameters' => ['type' => 'lpk']],
        ['key' => 'menu-10-9', 'label' => 'Klinik', 'route' => 'companies.index', 'active' => 'companies.*', 'parameters' => ['type' => 'clinic']],
        ['key' => 'health-templates', 'label' => 'Template Pemeriksaan Kesehatan', 'route' => 'health-templates.index', 'active' => 'health-templates.*'],
        ['key' => 'document-requirements', 'label' => 'Kebutuhan Dokumen', 'route' => 'document-requirements.index', 'active' => 'document-requirements.*'],
        ['key' => 'menu-10-10', 'label' => 'Jenis Dokumen', 'route' => 'master-data.index', 'active' => 'master-data.*', 'parameters' => ['master' => 'jenis-dokumen']],
        ['key' => 'menu-10-11', 'label' => 'Jenis Persyaratan', 'route' => 'master-data.index', 'active' => 'master-data.*', 'parameters' => ['master' => 'jenis-persyaratan']],
        ['key' => 'menu-10-12', 'label' => 'Tahapan Proses', 'route' => 'master-data.index', 'active' => 'master-data.*', 'parameters' => ['master' => 'tahapan-proses']],
        ['key' => 'menu-10-13', 'label' => 'Jenis Tugas', 'route' => 'master-reference.index', 'active' => 'master-reference.*', 'parameters' => ['reference' => 'jenis-tugas']],
        ['key' => 'menu-10-14', 'label' => 'Kategori Kendala', 'route' => 'master-reference.index', 'active' => 'master-reference.*', 'parameters' => ['reference' => 'kategori-kendala']],
    ]],
    ['label' => 'Pengaturan', 'items' => [
        ['key' => 'tracking-settings', 'label' => 'Tracking & Ads', 'route' => 'tracking-settings.index', 'active' => 'tracking-settings.*'],
        ['key' => 'system-cockpit', 'label' => 'System Cockpit', 'route' => 'cockpit.index', 'active' => 'cockpit.index', 'parameters' => ['role' => 'system'], 'permission' => 'admin.system_config.manage'],
        ['key' => 'site-health', 'label' => 'Kesehatan Website & SEO', 'route' => 'site-health.index', 'active' => 'site-health.*'],
        ['key' => 'prd-scopes', 'label' => 'Izin & Cakupan Data', 'route' => 'governance.index', 'active' => 'governance.*'],
        ['key' => 'prd-rotator', 'label' => 'CS Rotator', 'route' => 'acquisition.groups', 'active' => 'acquisition.groups'],
        ['key' => 'prd-whatsapp', 'label' => 'Template WhatsApp', 'route' => 'acquisition.templates', 'active' => 'acquisition.templates'],
        ['key' => 'menu-11-0', 'label' => 'Pengguna', 'route' => 'settings.users.index', 'active' => 'settings.users.*'],
        ['key' => 'menu-11-1', 'label' => 'Peran & Hak Akses', 'route' => 'settings.roles.index', 'active' => 'settings.roles.*'],
        ['key' => 'menu-11-2', 'label' => 'Otomasi', 'route' => 'settings.automation.index', 'active' => 'settings.automation.*'],
        ['key' => 'menu-11-3', 'label' => 'Template Notifikasi', 'route' => 'settings.templates.index', 'active' => 'settings.templates.*'],
        ['key' => 'menu-11-4', 'label' => 'Integrasi', 'route' => 'settings.integrations.index', 'active' => 'settings.integrations.*'],
        ['key' => 'menu-11-5', 'label' => 'Riwayat Aktivitas', 'route' => 'settings.activity.index', 'active' => 'settings.activity.*'],
        ['key' => 'menu-11-6', 'label' => 'Pengaturan Umum', 'route' => 'settings.general.index', 'active' => 'settings.general.*'],
    ]],
];

foreach ($groups as &$group) {
    foreach ($group['items'] as &$item) {
        if ($item['route'] === null) {
            $item['route'] = 'modules.show';
            $item['active'] = 'modules.show';
            $item['parameters'] = ['module' => $item['key']];
        }
    }
    unset($item);
}
unset($group);

return $groups;
