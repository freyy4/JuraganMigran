<?php

namespace App\Http\Middleware;

use App\Http\Controllers\FinanceController;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AccessPolicy;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CrmAccess
{
    public function __construct(private AccessPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->withErrors(['email' => 'Akun tidak aktif. Hubungi administrator.']);
        }
        if (DB::table('portal_accounts')->where('user_id', $user->id)->exists() && ! $request->routeIs('logout')) {
            abort(403);
        }
        $name = $request->route()->getName() ?? '';
        if ((str_starts_with($name, 'meta-connect.') || str_starts_with($name, 'whatsapp-qr.'))) {
            abort_unless(config('conversation.enabled'), 404);
        }
        if (str_starts_with($name, 'crm.')) {
            abort_unless(config('conversation.enabled'), 404);
            $stages = DB::table('crm_stages')->orderBy('display_order')->orderBy('id')->get();
            config(['conversation.stages' => $stages->pluck('name', 'code')->all(), 'conversation.stage_colors' => $stages->pluck('color_token', 'code')->all(), 'conversation.transitions' => $stages->mapWithKeys(fn ($stage) => [$stage->code => json_decode($stage->transitions, true)])->all()]);
        }
        $write = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS']) || $request->routeIs('*.create', '*.edit');
        $permission = $this->policy->routePermission($name, $write);
        if ($name === 'modules.show') {
            abort_unless(collect(config('navigation'))->flatMap(fn ($group) => $group['items'])->contains('key', $request->route('module')), 404);
            foreach (config('navigation') as $group) {
                foreach ($group['items'] as $item) {
                    if ($item['key'] === $request->route('module')) {
                        $permission = $this->policy->routePermission($item['route']);
                    }
                }
            }
        }
        if (str_starts_with($name, 'master-data.') && $request->route('master') === 'jenis-dokumen') {
            $permission = $write ? 'document.type.manage' : 'document.type.read';
        }
        $request->attributes->set('access_permission', $permission);
        if ($permission && ! $this->policy->has($user, $permission)) {
            $this->deny($user, $permission);
        }
        if (! $permission && ! $request->routeIs('logout')) {
            $this->deny($user, 'unmapped_route');
        }
        if ($request->routeIs('health-screenings.legacy') && ! $this->policy->has($user, 'health.sensitive.reveal')) {
            $this->deny($user, 'health.sensitive.reveal');
        }
        if ($request->routeIs('reports.progress.*', 'reports.blockers.*') && ! $this->policy->has($user, 'candidate.read.basic')) {
            $this->deny($user, 'candidate.read.basic');
        }
        if ($request->routeIs('reports.finance.*') && ! $this->policy->has($user, 'finance.settlement.read')) {
            $this->deny($user, 'finance.settlement.read');
        }
        if ($request->routeIs('candidates.create', 'candidates.edit', 'candidates.store', 'candidates.update')) {
            foreach (['candidate.read.identity', 'candidate.read.contact', 'candidate.read.location'] as $sensitive) {
                if (! $user->hasPermission($sensitive)) {
                    $this->deny($user, $sensitive);
                }
            }
        }
        if ($request->routeIs('documents.download', 'documents.review', 'document-operations.preview', 'document-operations.workspace')) {
            $document = $request->route('document');
            $classification = $document?->documentType?->sensitive_class ?? 'DOCUMENT';
            $needed = match ($classification) {
                'HEALTH' => 'health.mcu.document.read','IDENTITY' => 'candidate.read.identity',default => 'document.view.file'
            };
            if (! $user->hasPermission($needed)) {
                $this->deny($user, $needed);
            }
        }
        if ($request->routeIs('documents.review') && $request->input('status') === 'rejected' && ! $user->hasPermission('document.reject')) {
            $this->deny($user, 'document.reject');
        }
        $this->checkResources($request, $user, $permission);
        $response = $next($request);
        $sensitiveRead = DB::table('access_grants')->where('user_id', $user->id)->where('type', 'break_glass')->where('status', 'approved')->where('valid_from', '<=', now())->where('valid_until', '>', now())->exists() || $request->routeIs('documents.download', 'document-operations.preview', 'document-operations.workspace', 'document-custodies.evidence', 'health-screenings.show', 'finance-file.*', 'funnels.export') || $request->filled('export');
        if (($write || $sensitiveRead) && $response->getStatusCode() < 400 && ! $request->session()->has('errors')) {
            $this->policy->audit($user, $name, ['permission' => $permission, 'method' => $request->method(), 'parameters' => collect($request->route()->parameters())->map(fn ($value) => $value instanceof Model ? $value->getKey() : $value)->all()]);
        }

        return $response;
    }

    private function deny(User $user, string $permission): never
    {
        $this->policy->audit($user, 'access.denied', ['permission' => $permission, 'route' => request()->route()?->getName()]);
        abort(403, 'Anda tidak memiliki akses.');
    }

    private function checkResources(Request $request, User $user, ?string $permission): void
    {
        if (! $permission || str_starts_with($permission, 'admin.') || str_starts_with($permission, 'audit.') || str_starts_with($permission, 'access.')) {
            return;
        }
        if (collect($this->policy->policies($user, $permission))->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL')) {
            return;
        }
        $applications = [];
        $candidates = [];
        foreach ($request->route()->parameters() as $value) {
            if ($value instanceof Application) {
                $applications[] = $value->id;
            } elseif ($value instanceof Candidate) {
                $candidates[] = $value->id;
            } elseif ($value instanceof Model) {
                if ($value->getAttribute('application_id')) {
                    $applications[] = $value->application_id;
                } elseif ($value->getAttribute('candidate_id')) {
                    $candidates[] = $value->candidate_id;
                }
            }
        }
        foreach (['application_id', 'candidate_id', 'candidate_document_id', 'training_enrollment_id', 'receivable_id'] as $key) {
            if (! $request->filled($key)) {
                continue;
            }
            if (! is_scalar($request->input($key))) {
                $this->deny($user, $permission);
            }
            $id = (int) $request->input($key);
            if ($key === 'application_id') {
                $applications[] = $id;
            } elseif ($key === 'candidate_id') {
                $candidates[] = $id;
            } else {
                $table = match ($key) {
                    'candidate_document_id' => 'candidate_documents','training_enrollment_id' => 'training_enrollments',default => 'sponsor_receivables'
                };
                $row = DB::table($table)->find($id);
                if ($row?->application_id) {
                    $applications[] = $row->application_id;
                } elseif ($row?->candidate_id) {
                    $candidates[] = $row->candidate_id;
                }
            }
        }
        $module = $request->route()->defaults['module'] ?? null;
        $record = $request->route('record');
        if ($record && isset(FinanceController::MODULES[$module ?? '']) && $module !== 'settlements') {
            $row = DB::table(FinanceController::MODULES[$module][0])->find($record);
            if ($row?->application_id) {
                $applications[] = $row->application_id;
            }
        }
        foreach (['custody' => 'original_document_custodies', 'event' => 'travel_events', 'leave' => 'training_leave_requests', 'history' => 'document_custody_histories'] as $key => $table) {
            if (! $request->route($key)) {
                continue;
            }
            $row = DB::table($table)->find($request->route($key));
            if ($key === 'history' && $row) {
                $row = DB::table('original_document_custodies')->find($row->custody_id);
            }
            if ($key === 'leave' && $row) {
                $row = DB::table('training_enrollments')->find($row->training_enrollment_id);
            }
            if ($row?->application_id) {
                $applications[] = $row->application_id;
            }
            if ($key === 'custody' && $row && ! $row->application_id) {
                $document = DB::table('candidate_documents')->find($row->candidate_document_id);
                if ($document) {
                    $candidates[] = $document->candidate_id;
                }
            }
        }
        foreach ($applications as $id) {
            if (! $this->policy->applicationIds($user, $permission)->where('applications.id', $id)->exists()) {
                $this->deny($user, $permission);
            }
        }
        foreach ($candidates as $id) {
            if (! $this->policy->canCandidate($user, (int) $id, $permission)) {
                $this->deny($user, $permission);
            }
        }
    }
}
