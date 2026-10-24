<?php

$permissions = [
    'protocol.lead', 'protocol.senior', 'protocol.ops', 'protocol.medical', 'protocol.placement', 'protocol.override', 'health.mcu.result.write',
    'site.read', 'site.edit', 'site.publish', 'site.globals', 'site.assets', 'site.health',
    'lead.read.basic', 'lead.read.contact', 'lead.update', 'lead.qualify', 'lead.assign', 'lead.convert_to_candidate', 'lead.export',
    'candidate.read.basic', 'candidate.read.timeline', 'candidate.read.contact', 'candidate.read.identity', 'candidate.read.location', 'candidate.update.profile', 'candidate.update.stage', 'candidate.assign', 'candidate.reassign', 'candidate.close', 'candidate.export',
    'document.read.metadata', 'document.view.file', 'document.upload', 'document.verify', 'document.reject', 'document.download', 'document.custody.read', 'document.custody.update',
    'health.template.read', 'health.template.manage_draft', 'health.template.publish', 'health.question.manage', 'health.prescreen.complete', 'health.prescreen.review', 'document.type.read', 'document.type.manage', 'document.requirement.read', 'document.requirement.manage', 'document.request_correction', 'document.exception.approve', 'document.followup',
    'health.prescreen.read', 'health.prescreen.write', 'health.mcu.summary.read', 'health.mcu.document.read', 'health.sensitive.reveal',
    'schedule.read', 'schedule.create', 'schedule.update', 'process.read', 'process.update', 'process.override',
    'finance.sponsor_receivable.read', 'finance.sponsor_receivable.write', 'finance.invoice.create', 'finance.payment.record', 'finance.settlement.read', 'finance.settlement.approve', 'finance.claim.read', 'finance.claim.approve', 'finance.exposure.read', 'finance.exposure.approve', 'finance.cpmi_benefit.read', 'finance.cpmi_benefit.write',
    'sponsor.read.basic', 'sponsor.agreement.read', 'sponsor.agreement.manage', 'job_order.read', 'job_order.manage',
    'incident.read', 'incident.create', 'incident.update', 'incident.escalate', 'incident.close', 'claim.read', 'claim.resolve', 'replacement.manage',
    'funnel.read', 'funnel.manage_draft', 'funnel.publish', 'funnel.responses.read', 'funnel.responses.export', 'marketing.analytics.read', 'marketing.campaign.manage',
    'admin.user.manage', 'admin.role.assign', 'admin.permission.manage', 'admin.master_data.manage', 'admin.cs_rotator.manage', 'admin.whatsapp_template.manage', 'admin.integration.manage', 'admin.system_config.manage', 'audit.read', 'audit.export', 'dashboard.read', 'report.read', 'report.export', 'access.break_glass.approve', 'access.delegation.manage',
];
$permissions = array_values(array_unique(array_merge($permissions, (require __DIR__.'/operations.php')['permissions'])));
$permissions = array_values(array_unique([...$permissions, ...config('conversation.permissions', []), ...(require __DIR__.'/whatsapp-qr.php')['permissions']]));
$system = ['health.template.read', 'health.template.manage_draft', 'health.question.manage', 'document.type.read', 'document.type.manage', 'document.requirement.read', 'document.requirement.manage', 'site.read', 'site.edit', 'site.publish', 'site.globals', 'site.assets', 'site.health', 'admin.user.manage', 'admin.role.assign', 'admin.permission.manage', 'admin.master_data.manage', 'admin.cs_rotator.manage', 'admin.whatsapp_template.manage', 'admin.integration.manage', 'admin.system_config.manage', 'audit.read', 'audit.export', 'dashboard.read', 'access.break_glass.approve'];

$configuration = [
    'permissions' => $permissions,
    'scopes' => ['SELF', 'ASSIGNED', 'TEAM', 'VACANCY', 'SPONSOR', 'COUNTRY', 'OPERATIONAL_ALL', 'AGGREGATE_ONLY', 'TECHNICAL_ONLY'],
    'roles' => [
        'cpmi' => ['CPMI', 'SELF', []],
        'cs' => ['CS', 'ASSIGNED', ['protocol.lead', 'health.prescreen.read', 'health.prescreen.write', 'health.prescreen.complete', 'document.request_correction', 'document.followup', 'dashboard.read', 'lead.read.basic', 'lead.read.contact', 'lead.update', 'lead.qualify', 'lead.convert_to_candidate', 'candidate.read.basic', 'candidate.read.contact', 'candidate.read.timeline', 'candidate.update.stage', 'document.read.metadata', 'document.upload', 'document.view.file', 'document.download', 'schedule.read', 'schedule.create', 'schedule.update', 'process.read', 'process.update', 'incident.create']],
        'supervisor' => ['Supervisor', 'TEAM', ['protocol.lead', 'protocol.senior', 'protocol.override', 'health.prescreen.read', 'health.prescreen.write', 'health.prescreen.complete', 'health.prescreen.review', 'document.verify', 'document.request_correction', 'document.exception.approve', 'document.followup', 'dashboard.read', 'candidate.read.basic', 'candidate.read.contact', 'candidate.read.timeline', 'candidate.update.stage', 'candidate.assign', 'candidate.reassign', 'candidate.close', 'lead.read.basic', 'lead.read.contact', 'lead.assign', 'lead.qualify', 'lead.convert_to_candidate', 'document.read.metadata', 'document.upload', 'document.view.file', 'schedule.read', 'schedule.create', 'schedule.update', 'process.read', 'process.update', 'report.read', 'incident.read', 'incident.create', 'incident.update', 'incident.escalate', 'access.delegation.manage']],
        'admin_operations' => ['Admin Operations', 'OPERATIONAL_ALL', ['protocol.ops', 'health.template.read', 'health.template.manage_draft', 'health.question.manage', 'document.type.read', 'document.type.manage', 'document.requirement.read', 'document.requirement.manage', 'site.read', 'site.edit', 'site.assets', 'dashboard.read', 'sponsor.read.basic', 'job_order.read', 'job_order.manage', 'funnel.read', 'funnel.manage_draft', 'funnel.publish', 'funnel.responses.read', 'lead.read.basic', 'admin.cs_rotator.manage', 'admin.whatsapp_template.manage', 'admin.master_data.manage', 'process.read']],
        'finance' => ['Finance', 'OPERATIONAL_ALL', array_merge(['dashboard.read', 'report.read', 'report.export'], array_values(array_filter($permissions, fn ($key) => str_starts_with($key, 'finance.') || str_starts_with($key, 'sponsor.agreement.'))))],
        'marketing' => ['Marketing', 'OPERATIONAL_ALL', ['site.read', 'site.edit', 'site.assets', 'dashboard.read', 'funnel.read', 'funnel.manage_draft', 'funnel.responses.read', 'funnel.responses.export', 'marketing.analytics.read', 'marketing.campaign.manage']],
        'compliance' => ['Compliance / Risk', 'OPERATIONAL_ALL', ['health.template.read', 'health.template.manage_draft', 'health.template.publish', 'health.question.manage', 'health.prescreen.review', 'document.requirement.read', 'document.requirement.manage', 'document.request_correction', 'document.exception.approve', 'document.view.file', 'dashboard.read', 'candidate.read.basic', 'candidate.read.timeline', 'document.read.metadata', 'document.verify', 'document.reject', 'document.custody.read', 'incident.read', 'incident.create', 'incident.update', 'incident.escalate', 'incident.close', 'health.prescreen.read', 'health.sensitive.reveal', 'report.read']],
        'management' => ['Management', 'AGGREGATE_ONLY', ['health.prescreen.read', 'site.read', 'dashboard.read', 'report.read', 'marketing.analytics.read']],
        'super_admin' => ['Super Admin', 'TECHNICAL_ONLY', $system],
    ],
];

foreach ((require __DIR__.'/operations.php')['role_permissions'] as $role => $extra) {
    if (isset($configuration['roles'][$role])) {
        $configuration['roles'][$role][2] = array_values(array_unique(array_merge($configuration['roles'][$role][2], $extra)));
    }
}

foreach (config('conversation.role_permissions', []) as $role => $extra) {
    $configuration['roles'][$role][2] = array_values(array_unique([...$configuration['roles'][$role][2], ...$extra]));
}

return $configuration;
