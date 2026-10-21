<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccessPolicy
{
    public const GROUP_PERMISSIONS = [
        'Dasbor' => ['dashboard.read'], 'CRM' => ['candidate.read.basic', 'candidate.read.contact', 'candidate.read.timeline', 'candidate.update.profile', 'candidate.update.stage', 'candidate.assign', 'candidate.reassign', 'candidate.close', 'process.read', 'process.update', 'lead.read.basic', 'lead.read.contact', 'lead.update'],
        'Perekrutan' => ['sponsor.read.basic', 'job_order.read', 'job_order.manage', 'marketing.campaign.manage'],
        'Seleksi' => ['process.read', 'process.update', 'schedule.read', 'schedule.create', 'schedule.update', 'health.prescreen.read', 'health.prescreen.write', 'health.sensitive.reveal', 'health.mcu.summary.read'],
        'Dokumen' => ['document.read.metadata', 'document.view.file', 'document.upload', 'document.verify', 'document.reject', 'document.download', 'document.custody.read', 'document.custody.update'],
        'Pelatihan' => ['process.read', 'process.update', 'schedule.read', 'schedule.create', 'schedule.update'],
        'Keberangkatan' => ['schedule.read', 'schedule.create', 'schedule.update', 'process.read', 'process.update'],
        'Keuangan' => ['finance.sponsor_receivable.read', 'finance.sponsor_receivable.write', 'finance.invoice.create', 'finance.payment.record', 'finance.settlement.read', 'finance.cpmi_benefit.read', 'finance.cpmi_benefit.write', 'sponsor.agreement.read', 'sponsor.agreement.manage'],
        'Pemasaran' => ['marketing.analytics.read', 'marketing.campaign.manage', 'lead.read.basic', 'lead.update'],
        'Laporan' => ['report.read', 'report.export'], 'Data Master' => ['admin.master_data.manage'],
        'Pengaturan' => ['admin.user.manage', 'admin.role.assign', 'admin.permission.manage', 'admin.integration.manage', 'admin.system_config.manage', 'admin.whatsapp_template.manage', 'audit.read'],
    ];

    public function expand(array $permissions): array
    {
        return array_values(array_unique(collect($permissions)->flatMap(fn ($key) => self::GROUP_PERMISSIONS[$key] ?? [$key])->filter(fn ($key) => in_array($key, config('governance.permissions'), true))->all()));
    }

    public function policies(User $user, string $permission): array
    {
        if (! $user->is_active) {
            return [];
        }
        $policies = [];
        $roles = DB::table('crm_roles')->where('id', $user->crm_role_id)->where('active', true)->get();
        foreach ($roles as $role) {
            if ($role->code === 'super_admin' && ! in_array($permission, ['health.prescreen.read', 'health.prescreen.write', 'health.prescreen.complete', 'health.prescreen.review', 'health.sensitive.reveal', 'health.mcu.document.read', 'health.mcu.summary.read', 'health.template.publish', 'document.view.file', 'document.download', 'document.verify', 'document.request_correction', 'document.reject', 'document.exception.approve'], true) && in_array($permission, config('governance.permissions'), true)) {
                $policies[] = ['scope' => 'OPERATIONAL_ALL', 'refs' => []];

                continue;
            }
            if (in_array($permission, $this->expand(json_decode($role->permissions, true)), true)) {
                $policies[] = ['scope' => $role->scope_type, 'refs' => json_decode($role->scope_refs ?? '[]', true)];
            }
        }
        $assignments = DB::table('user_role_assignments as assignment')->join('crm_roles as role', 'role.id', '=', 'assignment.role_id')->where('assignment.user_id', $user->id)->where('assignment.active', true)->where('role.active', true)->where('assignment.valid_from', '<=', now())->where(fn ($q) => $q->whereNull('assignment.valid_until')->orWhere('assignment.valid_until', '>', now()))->select('assignment.id', 'role.permissions', 'role.scope_type', 'role.scope_refs')->get();
        foreach ($assignments as $assignment) {
            if (! in_array($permission, $this->expand(json_decode($assignment->permissions, true)), true)) {
                continue;
            }
            $grants = DB::table('scope_grants')->where('assignment_id', $assignment->id)->get();
            if ($grants->isEmpty()) {
                $policies[] = ['scope' => $assignment->scope_type, 'refs' => json_decode($assignment->scope_refs ?? '[]', true)];
            }
            foreach ($grants as $grant) {
                $policies[] = ['scope' => $grant->scope_type, 'refs' => json_decode($grant->scope_refs ?? '[]', true)];
            }
        }

        return $policies;
    }

    public function has(User $user, string $permission): bool
    {
        if (count($this->policies($user, $permission)) > 0) {
            return true;
        }

        return DB::table('access_grants')->where('user_id', $user->id)->where('type', 'break_glass')->where('status', 'approved')->where('valid_from', '<=', now())->where('valid_until', '>', now())->get()->contains(fn ($grant) => in_array($permission, json_decode($grant->permissions, true), true));
    }

    public function scopeApplications(object $query, User $user, string $permission, string $alias = 'applications', bool $aggregate = false): object
    {
        $policies = $this->policies($user, $permission);
        $query->where(function ($scope) use ($policies, $user, $alias, $aggregate, $permission): void {
            $scope->whereRaw('1=0');
            foreach ($policies as $policy) {
                $refs = $policy['refs'];
                switch ($policy['scope']) {
                    case 'OPERATIONAL_ALL': $scope->orWhereRaw('1=1');
                        break;
                    case 'AGGREGATE_ONLY': if ($aggregate) {
                        $scope->orWhereRaw('1=1');
                    }break;
                    case 'ASSIGNED': $scope->orWhere($alias.'.assigned_cs_id', $user->id);
                        break;
                    case 'SELF': $scope->orWhereIn($alias.'.candidate_id', DB::table('portal_accounts')->where('user_id', $user->id)->select('candidate_id'));
                        break;
                    case 'TEAM':
                        if ($user->team_id) {
                            $scope->orWhereIn($alias.'.assigned_cs_id', DB::table('users')->where('team_id', $user->team_id)->select('id'));
                        }
                        break;
                    case 'VACANCY': $scope->orWhereIn($alias.'.vacancy_id', $refs);
                        break;
                    case 'SPONSOR': case 'COUNTRY':
                        $column = $policy['scope'] === 'SPONSOR' ? 'job_orders.company_id' : 'job_orders.country_id';
                        $scope->orWhereIn($alias.'.vacancy_id', DB::table('vacancies')->join('job_orders', 'job_orders.id', '=', 'vacancies.job_order_id')->whereIn($column, $refs)->select('vacancies.id'));
                        break;
                }
            }
            $grants = DB::table('access_grants')->where('user_id', $user->id)->where('status', 'approved')->where('valid_from', '<=', now())->where('valid_until', '>', now())->get();
            foreach ($grants as $grant) {
                if ($grant->type === 'break_glass' && in_array($permission, json_decode($grant->permissions, true), true) && $grant->candidate_id) {
                    $scope->orWhere($alias.'.candidate_id', $grant->candidate_id);
                }
                if ($grant->type === 'delegation' && $grant->from_user_id && in_array($permission, json_decode($grant->permissions, true), true) && $this->has($user, $permission)) {
                    $scope->orWhere($alias.'.assigned_cs_id', $grant->from_user_id);
                }
            }
        });

        return $query;
    }

    public function applicationIds(User $user, string $permission, bool $aggregate = false): object
    {
        return $this->scopeApplications(DB::table('applications')->whereNull('applications.deleted_at'), $user, $permission, 'applications', $aggregate)->select('applications.id');
    }

    public function candidateIds(User $user, string $permission): object
    {
        $query = DB::table('applications')->whereNull('applications.deleted_at');

        return $this->scopeApplications($query, $user, $permission)->select('applications.candidate_id');
    }

    public function companyIds(User $user, string $permission): object
    {
        $query = DB::table('companies');
        $policies = $this->policies($user, $permission);
        $query->where(function ($scope) use ($policies, $user, $permission): void {
            $scope->whereRaw('1=0');
            foreach ($policies as $policy) {
                if ($policy['scope'] === 'OPERATIONAL_ALL') {
                    $scope->orWhereRaw('1=1');
                } elseif ($policy['scope'] === 'SPONSOR') {
                    $scope->orWhereIn('companies.id', $policy['refs']);
                } else {
                    $scope->orWhereIn('companies.id', DB::table('job_orders')->join('vacancies', 'vacancies.job_order_id', '=', 'job_orders.id')->join('applications', 'applications.vacancy_id', '=', 'vacancies.id')->whereIn('applications.id', $this->applicationIds($user, $permission))->select('job_orders.company_id'));
                }
            }
        });

        return $query->select('companies.id');
    }

    public function vacancyIds(User $user, string $permission, bool $aggregate = false): object
    {
        $query = DB::table('vacancies');
        $policies = $this->policies($user, $permission);
        $query->where(function ($scope) use ($policies, $user, $permission, $aggregate): void {
            $scope->whereRaw('1=0');
            foreach ($policies as $policy) {
                if ($policy['scope'] === 'OPERATIONAL_ALL' || ($aggregate && $policy['scope'] === 'AGGREGATE_ONLY')) {
                    $scope->orWhereRaw('1=1');
                } elseif ($policy['scope'] === 'VACANCY') {
                    $scope->orWhereIn('vacancies.id', $policy['refs']);
                } elseif (in_array($policy['scope'], ['SPONSOR', 'COUNTRY'], true)) {
                    $scope->orWhereIn('vacancies.job_order_id', DB::table('job_orders')->whereIn($policy['scope'] === 'SPONSOR' ? 'company_id' : 'country_id', $policy['refs'])->select('id'));
                } else {
                    $scope->orWhereIn('vacancies.id', DB::table('applications')->whereIn('applications.id', $this->applicationIds($user, $permission, $aggregate))->select('vacancy_id'));
                }
            }
        });

        return $query->select('vacancies.id');
    }

    public function scopeFinance(object $query, string $module, string $alias = 'record'): object
    {
        $user = auth()->user();
        $permission = request()->attributes->get('access_permission') ?? 'finance.sponsor_receivable.read';
        if (! $user) {
            return $query->whereRaw('1=0');
        }
        if (in_array($module, ['receivables', 'benefits', 'obligations'])) {
            $query->whereIn($alias.'.application_id', $this->applicationIds($user, $permission));
        } elseif ($module === 'sponsor-payments') {
            $query->whereIn($alias.'.receivable_id', DB::table('sponsor_receivables')->whereIn('application_id', $this->applicationIds($user, $permission))->select('id'));
        } elseif ($module === 'agreements') {
            $query->whereIn($alias.'.company_id', $this->companyIds($user, $permission));
        } elseif ($module === 'milestones') {
            $query->whereIn($alias.'.agreement_id', DB::table('commercial_agreements')->whereIn('company_id', $this->companyIds($user, $permission))->select('id'));
        }

        return $query;
    }

    public function canCandidate(User $user, int $candidate, string $permission): bool
    {
        return $this->scopeApplications(DB::table('applications')->where('applications.candidate_id', $candidate)->whereNull('applications.deleted_at'), $user, $permission)->exists()
            || collect($this->policies($user, $permission))->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL');
    }

    public function audit(?User $user, string $action, array $metadata = []): void
    {
        DB::table('audit_logs')->insert(['user_id' => $user?->id, 'action' => $action, 'new_values' => json_encode($metadata), 'ip_address' => request()->ip(), 'user_agent' => mb_substr(request()->userAgent() ?? '', 0, 1000), 'created_at' => now()]);
    }

    public function routePermission(string $name, bool $write = false): ?string
    {
        $name = str_replace('.api-', '.', $name);
        $prefix = explode('.', $name)[0];
        $action = Str::afterLast($name, '.');
        if ($name === 'logout') {
            return null;
        }
        if ($prefix === 'whatsapp-qr') {
            return match ($action) {
                'migrate' => 'WHATSAPP_CONNECTION_MANAGE',
                'index', 'status' => 'WHATSAPP_CHANNEL_VIEW',
                'image', 'create', 'start' => 'WHATSAPP_CHANNEL_CONNECT_QR',
                'rescan' => 'WHATSAPP_CHANNEL_RESCAN',
                'disconnect', 'remove' => 'WHATSAPP_CHANNEL_DISCONNECT',
                'routing' => 'WHATSAPP_CHANNEL_MANAGE_ROUTING',
                default => 'WHATSAPP_CHANNEL_DIAGNOSTICS',
            };
        }
        if ($prefix === 'meta-connect') {
            return match ($action) {
                'preflight' => 'WHATSAPP_CONNECTION_VIEW',
                'health', 'test-message', 'tests' => 'WHATSAPP_CONNECTION_TEST',
                'disconnect' => 'WHATSAPP_CONNECTION_DISCONNECT',
                default => 'WHATSAPP_CONNECTION_MANAGE',
            };
        }
        if ($prefix === 'crm') {
            return match ($action) {
                'inbox', 'read', 'attachment' => 'crm.inbox.read_assigned', 'send', 'close', 'claim' => 'crm.conversation.reply',
                'assign' => 'crm.lead.assign', 'takeover' => 'crm.conversation.takeover',
                'campaign-attribution' => 'marketing.analytics.read',
                'export-download', 'export-preview' => 'lead.export',
                'pipeline', 'saved-view', 'bulk', 'bulk-job', 'card-layout' => 'crm.pipeline.read', 'move' => 'crm.pipeline.move',
                'profile', 'followups', 'attribution', 'duplicates', 'duplicate-compare' => 'crm.lead.read', 'create-lead', 'archive', 'duplicate-resolve', 'import' => 'crm.lead.edit',
                'tag' => 'crm.tag.use', 'followup', 'finish-followup' => 'crm.followup.manage',
                'convert' => 'lead.convert_to_candidate', 'settings' => 'crm.settings.read',
                'connect-start', 'connect-callback', 'channel', 'register-channel', 'retry-event' => 'WHATSAPP_CONNECTION_ADVANCED',
                'test-channel' => 'WHATSAPP_CONNECTION_TEST',
                'save-stage', 'create-stage', 'reorder-stages' => 'crm.pipeline.manage',
                'sync-templates' => 'crm.whatsapp_template.manage', 'save-tag' => 'crm.tag.manage',
                'save-agent' => 'crm.routing.manage',
                'save-quick-reply' => 'crm.quick_reply.manage', 'retry-message', 'availability' => 'crm.conversation.reply',
                default => null,
            };
        }
        if ($prefix === 'holiday-transport') {
            return match ($action) {
                'index' => 'holiday.read','holiday','holiday-update' => 'holiday.manage','policy' => 'holiday.policy.manage','leave' => 'leave.request','review' => 'leave.review',default => 'holiday.read'
            };
        }
        if ($prefix === 'mobility') {
            return match ($action) {
                'show','show-list' => 'mobility.read','point','point-update' => 'transport.route.manage','batch','batch-update','manifest' => 'transport.batch.manage','issue','issue-body','revoke' => 'qrpass.issue','check-in' => 'qrpass.scan','handover', 'handover-body', 'handover-update' => 'handover.manage',default => 'mobility.manage'
            };
        }
        if ($prefix === 'funnel-tracking') {
            return $action === 'show' ? 'funnel.tracking.read' : 'funnel.tracking.update';
        }
        if ($prefix === 'tracking-settings') {
            return match ($action) {
                'store' => 'tracking.destination.create', 'update' => 'tracking.destination.update', 'test' => 'tracking.destination.test', 'mapping' => 'tracking.event_map.manage', 'diagnostics' => 'tracking.diagnostics.read', default => 'tracking.destination.read',
            };
        }
        if ($prefix === 'tracking') {
            return match ($name) {
                'tracking.funnel' => 'funnel.tracking.update', 'tracking.destination.store','tracking.destination.update','tracking.retry','tracking.integrations' => 'tracking.destination.manage','tracking.mapping','tracking.mapping-update','tracking.test' => 'tracking.eventmap.manage',default => 'tracking.diagnostics.read'
            };
        }
        if ($prefix === 'site-builder') {
            return match ($action) {
                'index','preview','live-preview' => 'site.read','publish','archive','slug' => 'site.publish',default => 'site.edit'
            };
        }
        if ($prefix === 'site-assets') {
            return 'site.assets';
        }
        if ($prefix === 'site-vacancies') {
            return 'job_order.manage';
        }
        if ($prefix === 'site-health') {
            return 'site.health';
        }
        if ($prefix === 'site-analytics') {
            return 'marketing.analytics.read';
        }
        if ($prefix === 'settings') {
            $module = explode('.', $name)[1] ?? '';

            return match ($module) {
                'users' => 'admin.user.manage','roles' => 'admin.permission.manage','automation','general' => 'admin.system_config.manage','templates' => 'admin.whatsapp_template.manage','integrations' => 'admin.integration.manage','activity' => 'audit.read',default => 'admin.permission.manage'
            };
        }
        if ($prefix === 'governance') {
            return match ($action) {
                'delegation' => 'access.delegation.manage','grantRequest','index' => 'dashboard.read','approve' => 'access.break_glass.approve',default => 'admin.permission.manage'
            };
        }
        if ($prefix === 'reports') {
            return $write || request('export') ? 'report.export' : 'report.read';
        }
        if ($prefix === 'funnels') {
            return match ($action) {
                'publish','rollback' => 'funnel.publish','responses' => 'funnel.responses.read','export' => 'funnel.responses.export','analytics' => 'marketing.analytics.read','index','builder','preview','live-preview','readiness','versions' => 'funnel.read',default => 'funnel.manage_draft'
            };
        }
        if ($prefix === 'acquisition') {
            return match ($action) {
                'groups','saveGroup','member','mapping' => 'admin.cs_rotator.manage','templates','saveTemplate' => 'admin.whatsapp_template.manage','convert' => 'lead.convert_to_candidate','reassign' => 'lead.assign','qualify' => 'lead.qualify','followup','whatsapp','schedule' => 'lead.update',default => 'lead.read.basic'
            };
        }
        if ($prefix === 'portal-admin') {
            return $action === 'invite' ? 'candidate.update.profile' : 'candidate.read.basic';
        }
        if ($prefix === 'candidates') {
            if (in_array($action, ['create-application', 'store-application'], true)) {
                return 'candidate.assign';
            }

            return in_array($action, ['index', 'show']) ? 'candidate.read.basic' : ($action === 'destroy' ? 'candidate.close' : 'candidate.update.profile');
        }
        if ($prefix === 'applications') {
            return match ($action) {
                'assign-cs' => 'candidate.reassign','update-status' => 'candidate.close',default => 'candidate.read.basic'
            };
        }
        if ($prefix === 'pipeline') {
            return match ($action) {
                'save-view', 'delete-view', 'bulk', 'event' => 'process.read', 'message', 'priority' => 'process.update', 'contact' => 'candidate.read.contact', default => $write ? 'candidate.update.stage' : 'process.read'
            };
        }
        if (in_array($prefix, ['tasks', 'follow-ups', 'blockers'])) {
            return $write ? 'process.update' : 'process.read';
        }
        if ($prefix === 'health-templates') {
            return match ($action) {
                'publish' => 'health.template.publish', 'question' => 'health.question.manage', default => $write ? 'health.template.manage_draft' : 'health.template.read'
            };
        }
        if ($prefix === 'document-requirements') {
            return $write ? 'document.requirement.manage' : 'document.requirement.read';
        }
        if ($prefix === 'document-operations') {
            return match ($action) {
                'approve-exception' => 'document.exception.approve', 'reminder', 'followups', 'whatsapp' => 'document.followup', 'owner', 'action', 'exception' => 'document.upload', 'preview', 'workspace' => 'document.view.file', default => 'document.read.metadata'
            };
        }
        if ($prefix === 'health-screenings') {
            if ($action === 'review') {
                return 'health.prescreen.review';
            }

            return $write ? 'health.prescreen.write' : 'health.prescreen.read';
        }
        if ($prefix === 'medical-events') {
            return $write ? 'health.prescreen.write' : 'health.mcu.summary.read';
        }
        if (in_array($prefix, ['documents', 'document-verifications', 'missing-documents', 'expired-documents'])) {
            return match ($action) {
                'download' => 'document.download','upload' => 'document.upload','review' => match (request('status')) {
                    'needs_correction' => 'document.request_correction', 'rejected' => 'document.reject', default => 'document.verify'
                },default => 'document.read.metadata'
            };
        }
        if ($prefix === 'document-custodies') {
            if ($action === 'evidence') {
                return 'document.view.file';
            }

            return $write ? 'document.custody.update' : 'document.custody.read';
        }
        if (in_array($prefix, ['agreements', 'milestones'])) {
            return $write ? 'sponsor.agreement.manage' : 'sponsor.agreement.read';
        }
        if (in_array($prefix, ['receivables', 'sponsor-payments', 'obligations', 'settlements', 'finance-file'])) {
            return $write ? ($prefix === 'sponsor-payments' || $action === 'pay' ? 'finance.payment.record' : 'finance.sponsor_receivable.write') : ($prefix === 'settlements' ? 'finance.settlement.read' : 'finance.sponsor_receivable.read');
        }
        if ($prefix === 'benefits') {
            return $write ? 'finance.cpmi_benefit.write' : 'finance.cpmi_benefit.read';
        }
        if (in_array($prefix, ['campaigns', 'landing-pages'])) {
            return 'marketing.campaign.manage';
        }
        if ($prefix === 'marketing-analytics') {
            return 'marketing.analytics.read';
        }
        if (in_array($prefix, ['lead-sources', 'referrals'])) {
            return $write ? 'lead.update' : 'lead.read.basic';
        }
        if (in_array($prefix, ['master-data', 'master-reference', 'master-workflows', 'countries'])) {
            return 'admin.master_data.manage';
        }
        if ($prefix === 'companies') {
            return $write ? 'job_order.manage' : 'sponsor.read.basic';
        }
        if (in_array($prefix, ['vacancies', 'job-orders'])) {
            return $write ? 'job_order.manage' : 'job_order.read';
        }
        if (in_array($prefix, ['interviews', 'flights', 'opp', 'departures', 'arrivals', 'training-batches'])) {
            return $write ? 'schedule.update' : 'schedule.read';
        }
        if (in_array($prefix, ['qualifications', 'readiness', 'training', 'training-leaves', 'training-progress'])) {
            return $write ? 'process.update' : 'process.read';
        }
        if (in_array($prefix, ['dashboard', 'cockpit'])) {
            return 'dashboard.read';
        }

        return null;
    }

    public function mask(?string $value): string
    {
        if (! $value) {
            return '—';
        }

        return mb_substr($value, 0, 2).'••••'.mb_substr($value, -4);
    }
}
