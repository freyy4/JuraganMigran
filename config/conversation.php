<?php

return [
    'enabled' => env('CRM_INBOX_ENABLED', true),
    'graph_version' => env('META_GRAPH_API_VERSION', env('WHATSAPP_GRAPH_VERSION', 'v25.0')),
    'app_id' => env('META_APP_ID', env('WHATSAPP_APP_ID')),
    'signup_config_id' => env('WHATSAPP_SIGNUP_CONFIG_ID'),
    'app_secret' => env('META_APP_SECRET', env('WHATSAPP_APP_SECRET')),
    'verify_token' => env('META_WEBHOOK_VERIFY_SECRET', env('WHATSAPP_VERIFY_TOKEN')),
    'retention_days' => 7,
    'stages' => ['NEW' => 'Lead Baru', 'CONTACTED' => 'Kontak Awal', 'DISCUSSION' => 'Diskusi', 'QUALIFIED' => 'Qualified', 'CONVERTED' => 'Dikonversi', 'LOST' => 'Ditutup'],
    'transitions' => ['NEW' => ['CONTACTED', 'LOST'], 'CONTACTED' => ['DISCUSSION', 'LOST'], 'DISCUSSION' => ['QUALIFIED', 'LOST'], 'QUALIFIED' => ['DISCUSSION', 'LOST'], 'LOST' => ['NEW'], 'CONVERTED' => []],
    'lost_reasons' => ['Tidak tertarik', 'Tidak memenuhi syarat awal', 'Tidak dapat dihubungi', 'Pilih program lain', 'Waktu belum cocok', 'Keluarga tidak setuju', 'Duplikat', 'Spam', 'Lainnya'],
    'permissions' => ['WHATSAPP_CONNECTION_VIEW', 'WHATSAPP_CONNECTION_MANAGE', 'WHATSAPP_CONNECTION_TEST', 'WHATSAPP_CONNECTION_DISCONNECT', 'WHATSAPP_CONNECTION_ADVANCED', 'crm.settings.read', 'crm.inbox.read_assigned', 'crm.inbox.read_team', 'crm.conversation.reply', 'crm.conversation.takeover', 'crm.lead.read', 'crm.lead.edit', 'crm.lead.assign', 'crm.pipeline.read', 'crm.pipeline.move', 'crm.pipeline.manage', 'crm.tag.use', 'crm.tag.manage', 'crm.followup.manage', 'crm.whatsapp.manage', 'crm.routing.manage', 'crm.quick_reply.manage', 'crm.whatsapp_template.use', 'crm.whatsapp_template.manage', 'crm.audit.read'],
    'role_permissions' => [
        'cs' => ['crm.inbox.read_assigned', 'crm.conversation.reply', 'crm.lead.read', 'crm.lead.edit', 'crm.pipeline.read', 'crm.pipeline.move', 'crm.tag.use', 'crm.followup.manage', 'crm.whatsapp_template.use'],
        'supervisor' => ['WHATSAPP_CONNECTION_VIEW', 'crm.settings.read', 'crm.inbox.read_assigned', 'crm.inbox.read_team', 'crm.conversation.reply', 'crm.conversation.takeover', 'crm.lead.read', 'crm.lead.edit', 'crm.lead.assign', 'crm.pipeline.read', 'crm.pipeline.move', 'crm.tag.use', 'crm.followup.manage', 'crm.whatsapp_template.use', 'crm.audit.read'],
        'admin_operations' => ['WHATSAPP_CONNECTION_VIEW', 'WHATSAPP_CONNECTION_MANAGE', 'WHATSAPP_CONNECTION_TEST', 'crm.settings.read', 'crm.lead.read', 'crm.pipeline.read', 'crm.pipeline.manage', 'crm.tag.manage', 'crm.routing.manage', 'crm.quick_reply.manage'],
        'super_admin' => ['WHATSAPP_CONNECTION_VIEW', 'WHATSAPP_CONNECTION_MANAGE', 'WHATSAPP_CONNECTION_TEST', 'WHATSAPP_CONNECTION_DISCONNECT', 'WHATSAPP_CONNECTION_ADVANCED', 'crm.settings.read', 'crm.whatsapp.manage', 'crm.routing.manage', 'crm.whatsapp_template.manage'],
    ],
];
