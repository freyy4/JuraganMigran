<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\HealthInterview;
use App\Models\User;
use App\Rules\SafePassphrase;
use App\Services\AccessPolicy;
use App\Services\AuthenticationSecurity;
use App\Services\CrmWorkspace;
use App\Services\CsRouter;
use App\Services\DocumentRequirementEngine;
use App\Services\DocumentScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalController extends Controller
{
    public const REQUESTS = ['callback' => 'Minta dihubungi', 'profile_correction' => 'Koreksi data pribadi', 'document_return' => 'Pengembalian dokumen asli', 'reschedule' => 'Ubah jadwal', 'training_leave' => 'Izin pelatihan', 'difficulty' => 'Kesulitan proses / dokumen'];

    public const STATUSES = ['SUBMITTED' => 'Diajukan', 'REVIEWING' => 'Sedang Diproses', 'NEED_INFO' => 'Perlu Informasi Tambahan', 'APPROVED' => 'Disetujui', 'REJECTED' => 'Tidak Disetujui', 'DONE' => 'Selesai'];

    public function login(): View
    {
        return view('portal.login', ['staffSession' => auth()->check() && ! DB::table('portal_accounts')->where('user_id', auth()->id())->exists(), 'reactData' => ['page' => 'login', 'routes' => ['login' => route('portal.login.store'), 'home' => route('portal.home')], 'email' => old('email', ''), 'error' => session('errors')?->first()]]);
    }

    public function authenticate(Request $request): RedirectResponse|JsonResponse
    {
        $request->merge(['identifier' => $request->input('identifier', $request->input('email'))]);
        $data = $request->validate(['identifier' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:4096']]);
        try {
            $result = app(AuthenticationSecurity::class)->primary($request, 'cpmi', $data['identifier'], $data['password']);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                $limited = str_contains($exception->getMessage(), 'Terlalu banyak');

                return response()->json(['ok' => false, 'code' => $limited ? 'RATE_LIMITED' : 'INVALID_CREDENTIALS', 'message' => $exception->getMessage()], $limited ? 429 : 401);
            }
            throw $exception;
        }

        $redirect = $result['next'];
        if ($request->routeIs('auth.cpmi.login')) {
            $result['next'] = parse_url($result['next'], PHP_URL_PATH);
        }

        return $request->expectsJson() ? response()->json($result + ['redirect' => $redirect])->header('Cache-Control', 'private, no-store') : redirect($redirect);
    }

    public function invite(Request $request, Candidate $candidate): RedirectResponse
    {
        $data = $request->validate(['contact_verified' => ['accepted']]);
        $phone = CsRouter::phone($candidate->person?->whatsapp ?? '');
        $token = Str::random(64);
        DB::transaction(function () use ($candidate, $request, $token): void {
            Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            DB::table('portal_invitations')->where('candidate_id', $candidate->id)->whereNull('used_at')->update(['expires_at' => now()]);
            DB::table('portal_invitations')->insert(['candidate_id' => $candidate->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'contact_verified' => true, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('portal_invitation', route('portal.invitation', $token))->with('success', 'Undangan sekali pakai dibuat, berlaku 24 jam. Berikan melalui kanal kontak CPMI yang telah diverifikasi.');
    }

    public function invitation(string $token): View
    {
        $this->validInvitation($token);

        return view('portal.activate', compact('token'));
    }

    private function validInvitation(string $token): object
    {
        abort_unless(preg_match('/^[a-zA-Z0-9]{64}$/', $token), 404);

        return DB::table('portal_invitations')->where('token_hash', hash('sha256', $token))->whereNull('used_at')->where('expires_at', '>', now())->where('contact_verified', true)->first() ?? abort(404, 'Undangan tidak tersedia atau kedaluwarsa.');
    }

    private function portalEmail(Candidate $candidate, ?User $user = null): string
    {
        $name = Str::slug($candidate->person?->full_name ?? '', '');
        $name = substr($name !== '' ? $name : 'cpmi'.$candidate->id, 0, 50);
        $suffix = 1;
        do {
            $email = $name.($suffix > 1 ? '.'.$suffix : '').'@juraganmigran.my.id';
            $suffix++;
        } while (User::where('email', $email)->when($user, fn ($query) => $query->where('id', '!=', $user->id))->exists());

        return $email;
    }

    public function activate(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', new SafePassphrase, 'max:255', 'confirmed'], 'consent' => ['accepted']]);
        $user = DB::transaction(function () use ($token, $data): User {
            $invitation = $this->validInvitation($token);
            $invitation = DB::table('portal_invitations')->where('id', $invitation->id)->lockForUpdate()->first();
            abort_if($invitation->used_at || $invitation->expires_at <= now(), 404);
            $candidate = Candidate::withoutGlobalScope('access')->with('person')->findOrFail($invitation->candidate_id);
            $phone = CsRouter::phone($candidate->person?->whatsapp ?? '');
            if ($candidate->person?->nik && hash_equals($candidate->person->nik, $data['password'])) {
                throw ValidationException::withMessages(['password' => 'Gunakan kata sandi selain nomor identitas.']);
            }
            $account = DB::table('portal_accounts')->where('candidate_id', $candidate->id)->first();
            $user = $account ? User::findOrFail($account->user_id) : new User(['name' => $candidate->person?->full_name ?? 'CPMI', 'email' => $this->portalEmail($candidate), 'crm_role_id' => DB::table('crm_roles')->where('code', 'cpmi')->value('id')]);
            if ($account && str_ends_with($user->email, '@portal.invalid')) {
                $user->email = $this->portalEmail($candidate, $user);
            }
            $user->password = $data['password'];
            $user->is_active = true;
            $user->remember_token = Str::random(60);
            $user->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('portal_accounts')->updateOrInsert(['candidate_id' => $candidate->id], ['user_id' => $user->id, 'normalized_phone' => $phone, 'status' => 'active', 'phone_verified_at' => now(), 'activated_at' => now(), 'last_login_at' => now(), 'consent_version' => 'JM-PRIVACY-1', 'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('portal_invitations')->where('candidate_id', $candidate->id)->whereNull('used_at')->update(['used_at' => now()]);
            app(AccessPolicy::class)->audit($user, 'portal.invitation.activated', ['candidate_id' => $candidate->id, 'consent_version' => 'JM-PRIVACY-1']);

            return $user;
        });
        app(AuthenticationSecurity::class)->finish($request, $user, 'cpmi');

        return to_route('portal.home');
    }

    private function candidate(Request $request): Candidate
    {
        return Candidate::with('person')->findOrFail($request->attributes->get('portal_account')->candidate_id);
    }

    private function application(Candidate $candidate): ?Application
    {
        return $candidate->applications()->whereNotIn('status', ['closed', 'cancelled', 'rejected'])->with(['vacancy.jobOrder.country', 'currentStage', 'assignedCs'])->latest('id')->first();
    }

    private function documentData(?Application $application): array
    {
        if (! $application) {
            return [];
        }

        return app(DocumentRequirementEngine::class)->rows($application)->map(function ($row): array {
            $requirement = $row['rule'];
            $document = $row['document'];
            $status = $row['status'];
            $label = match ($status) {
                'uploaded', 'reviewed' => 'Sedang diverifikasi', 'needs_correction' => 'Perlu diperbaiki', 'rejected' => 'Dokumen tidak sesuai', 'expired' => 'Perlu diperbarui', 'not_required_yet' => 'Belum diperlukan', 'missing' => 'Belum diunggah', default => DocumentRequirementEngine::LABELS[$status] ?? $status,
            };

            return ['requirement_id' => $requirement->source_key, 'name' => $requirement->documentType->name, 'required' => (bool) $requirement->is_required, 'allowed_file_types' => $requirement->documentType->allowed_file_types ?: ['pdf', 'jpg', 'jpeg', 'png'], 'max_file_size_kb' => min(5120, $requirement->documentType->max_file_size_kb), 'expiry_required' => (bool) ($requirement->documentType->has_expiry && str_starts_with($requirement->source_key, 'rule-') && $requirement->expiry_policy === 'VALID'), 'has_expiry' => (bool) $requirement->documentType->has_expiry, 'status' => $label, 'status_code' => $status, 'safe_reason' => in_array($status, ['needs_correction', 'rejected'], true) ? $document?->rejection_reason : null, 'document_id' => $document?->id, 'updated_at' => $document?->updated_at?->toIso8601String(), 'url' => $document && ($requirement->documentType->sensitive_class !== 'HEALTH' || $document->uploaded_by === auth()->id()) ? URL::temporarySignedRoute('portal.document.file', now()->addMinutes(5), ['document' => $document->id]) : null];
        })->all();
    }

    private function schedules(?Application $application): array
    {
        if (! $application) {
            return [];
        }
        $schedules = collect();
        foreach ([['interviews', 'Interview', 'scheduled_at'], ['medical_events', 'MCU', 'scheduled_at'], ['travel_events', 'Persiapan keberangkatan', 'scheduled_at'], ['flight_details', 'Penerbangan', 'departure_at']] as [$table,$label,$date]) {
            $query = DB::table($table)->where('application_id', $application->id)->whereNotNull($date)->where($date, '>=', now());
            if ($table !== 'flight_details') {
                $query->where('status', 'scheduled');
            }
            foreach ($query->orderBy($date)->get() as $row) {
                $title = $table === 'travel_events' ? match ($row->event_type) {
                    'OPP' => 'Pembekalan keberangkatan','arrival' => 'Kedatangan',default => 'Keberangkatan'
                } : $label;
                $schedules->push(['id' => $table.'-'.$row->id, 'title' => $title, 'at' => $row->$date, 'location' => $table === 'flight_details' ? ($row->departure_airport ?? '') : ($table === 'medical_events' && $row->clinic_id ? DB::table('companies')->where('id', $row->clinic_id)->value('name') : ''), 'instructions' => 'Siapkan dokumen identitas dan konfirmasikan kebutuhan kepada CS.']);
            }
        }
        foreach (DB::table('training_enrollments')->join('training_batches', 'training_batches.id', '=', 'training_enrollments.batch_id')->where('training_enrollments.application_id', $application->id)->whereIn('training_enrollments.status', ['scheduled', 'ongoing'])->select('training_batches.id', 'training_batches.name', 'training_batches.start_date')->get() as $row) {
            if ($row->start_date && $row->start_date >= now('Asia/Jakarta')->toDateString()) {
                $schedules->push(['id' => 'training-'.$row->id, 'title' => 'Pelatihan: '.$row->name, 'at' => $row->start_date, 'location' => '', 'instructions' => 'Konfirmasikan jadwal dan perlengkapan kepada CS.']);
            }
        }

        return $schedules->sortBy('at')->values()->all();
    }

    public function page(Request $request, string $page = 'home'): View|JsonResponse
    {
        $candidate = $this->candidate($request);
        $application = $this->application($candidate);
        $documents = $this->documentData($application);
        $stages = DB::table('pipeline_stages')->where('is_active', true)->orderBy('sequence')->get();
        $history = $application ? DB::table('application_stage_histories')->where('application_id', $application->id)->pluck('to_stage_id')->all() : [];
        $timeline = $stages->map(fn ($stage) => ['name' => $stage->name, 'status' => $application?->current_stage_id === $stage->id ? 'Sedang Diproses' : (in_array($stage->id, $history, true) ? 'Selesai' : 'Berikutnya')])->all();
        $requests = DB::table('cpmi_requests')->where('candidate_id', $candidate->id)->select('id', 'type', 'status', 'public_note', 'created_at')->latest('id')->get();
        $notifications = DB::table('portal_notifications')->where('candidate_id', $candidate->id)->select('id', 'category', 'title', 'body_safe', 'deep_link', 'read_at', 'created_at')->latest('id')->get();
        $health = $application ? HealthInterview::where('application_id', $application->id)->latest('id')->first() : null;
        $safe = ['health_status' => $health?->completed_at ? 'Sudah dilakukan. Hubungi CS untuk langkah berikutnya.' : 'Belum selesai. Hubungi CS untuk jadwal pemeriksaan.', 'name' => $candidate->person?->full_name, 'vacancy' => $application?->vacancy?->title, 'country' => $application?->vacancy?->jobOrder?->country?->name, 'stage' => $application?->currentStage?->name ?? 'Verifikasi Awal', 'documents' => $documents, 'schedules' => $this->schedules($application), 'process' => $timeline, 'requests' => $requests, 'notifications' => $notifications, 'cs_name' => $application?->assignedCs?->name];
        $account = $request->attributes->get('portal_account');
        $safe['profile'] = [
            'name' => $safe['name'], 'whatsapp' => $candidate->person?->whatsapp,
            'email' => $candidate->person?->email, 'login_email' => $request->user()->email,
            'domicile' => $candidate->person?->address, 'consent_version' => $account->consent_version,
            'last_login_at' => $account->last_login_at,
            'in_app_notifications' => (bool) $account->in_app_notifications,
            'whatsapp_notifications' => (bool) $account->whatsapp_notifications,
        ];
        if ($request->is('api/portal/*')) {
            $profile = $safe['profile'];

            return response()->json(match ($page) {
                'documents' => $documents,'schedules' => $safe['schedules'],'process' => $timeline,'requests' => $requests,'notifications' => $notifications,'profile','me' => $profile,default => $safe
            });
        }

        return view('portal.page', ['reactData' => $this->reactData($page, $safe), 'page' => $page, 'data' => $safe, 'profile' => $candidate->person, 'account' => $request->attributes->get('portal_account'), 'requestTypes' => self::REQUESTS, 'requestStatuses' => self::STATUSES, 'uploadKey' => (string) Str::uuid(), 'requestKey' => (string) Str::uuid()]);
    }

    private function reactData(string $page, array $data): array
    {
        $pages = [];
        foreach (['home', 'process', 'documents', 'schedules', 'requests', 'help', 'profile', 'privacy', 'notifications'] as $destination) {
            $pages[$destination] = route('portal.'.$destination);
        }

        return ['page' => $page, 'data' => $data, 'requestTypes' => self::REQUESTS, 'requestStatuses' => self::STATUSES, 'routes' => [
            'pages' => $pages, 'summary' => route('portal.api.summary'),
            'upload' => route('portal.api.upload', ['requirement' => '__ID__']),
            'request' => route('portal.api.request'), 'profile' => route('portal.api.profile.update'),
            'read' => route('portal.api.read', ['notification' => '__ID__']), 'contact' => route('portal.api.contact'),
            'preferences' => route('portal.preferences'), 'revoke' => route('portal.revokeSessions'),
            'logout' => route('portal.logout'), 'login' => route('portal.login'), 'travel' => route('portal.holiday'),
        ]];
    }

    public function upload(Request $request, string $requirement): RedirectResponse|JsonResponse
    {
        $candidate = $this->candidate($request);
        $application = $this->application($candidate) ?? abort(404);
        $row = app(DocumentRequirementEngine::class)->requirements($application)->first(fn ($row) => $row->source_key === $requirement) ?? abort(404);
        $data = $request->validate(['expires_at' => [($row->documentType->has_expiry && str_starts_with($row->source_key, 'rule-') && $row->expiry_policy === 'VALID') ? 'required' : 'nullable', 'date_format:Y-m-d'], 'file' => ['required', 'file', 'mimes:'.implode(',', $row->documentType->allowed_file_types ?: ['pdf', 'jpg', 'jpeg', 'png']), 'max:'.min(5120, $row->documentType->max_file_size_kb)], 'upload_key' => ['required', 'uuid']]);
        $prior = CandidateDocument::where('portal_upload_key', $data['upload_key'])->where('candidate_id', $candidate->id)->first();
        if (! $prior) {
            app(DocumentScanner::class)->inspect($request->file('file'));
            $path = $request->file('file')->store('', 'documents');
            try {
                DB::transaction(function () use ($data, $path, $candidate, $application, $row, $request): void {
                    $locked = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
                    abort_unless(app(DocumentRequirementEngine::class)->requirements($locked)->contains(fn ($requirement) => $requirement->source_key === $row->source_key && $requirement->document_type_id === $row->document_type_id), 422);
                    if (CandidateDocument::where('portal_upload_key', $data['upload_key'])->exists()) {
                        Storage::disk('documents')->delete($path);

                        return;
                    }
                    $version = (CandidateDocument::withTrashed()->where('candidate_id', $candidate->id)->where('application_id', $application->id)->where('document_type_id', $row->document_type_id)->max('version') ?? 0) + 1;
                    CandidateDocument::where('candidate_id', $candidate->id)->where('application_id', $application->id)->where('document_type_id', $row->document_type_id)->update(['status' => 'superseded']);
                    DB::table('document_actions')->where('application_id', $application->id)->where('document_type_id', $row->document_type_id)->update(['owner' => 'INTERNAL_REVIEW', 'assigned_to' => $application->assigned_cs_id, 'due_at' => now()->addHours(config('health-documents.review_sla_hours', 48)), 'updated_at' => now()]);
                    $document = CandidateDocument::create(['expires_at' => $data['expires_at'] ?? null, 'action_assigned_to' => $application->assigned_cs_id, 'action_due_at' => now()->addHours(config('health-documents.review_sla_hours', 48)), 'candidate_id' => $candidate->id, 'application_id' => $application->id, 'document_type_id' => $row->document_type_id, 'file_path' => $path, 'version' => $version, 'status' => 'uploaded', 'uploaded_by' => $request->user()->id]);
                    if (in_array($locked->status, ['ready', 'ready_for_departure', 'departure_ready'], true) && ! $locked->isReadyForDeparture()) {
                        $locked->update(['status' => 'active']);
                    }
                    DB::table('candidate_documents')->where('id', $document->id)->update(['portal_upload_key' => $data['upload_key']]);
                    app(AccessPolicy::class)->audit($request->user(), 'portal.document.upload', ['document_id' => $document->id]);
                });
            } catch (\Throwable $exception) {
                Storage::disk('documents')->delete($path);
                throw $exception;
            }
        }
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Dokumen diterima dan sedang diverifikasi.']);
        }

        return to_route('portal.documents')->with('success', 'Dokumen diterima. Tim kami akan memverifikasi.');
    }

    public function file(Request $request, CandidateDocument $document): StreamedResponse
    {
        abort_if($document->documentType?->sensitive_class === 'HEALTH' && $document->uploaded_by !== $request->user()->id, 404);
        abort_unless($request->hasValidSignature() && $document->candidate_id === $request->attributes->get('portal_account')->candidate_id && $document->file_path && Storage::disk('documents')->exists($document->file_path), 404);
        app(AccessPolicy::class)->audit($request->user(), 'portal.document.view', ['document_id' => $document->id]);

        return Storage::disk('documents')->download($document->file_path, 'dokumen.'.pathinfo($document->file_path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function requestHelp(Request $request): RedirectResponse|JsonResponse
    {
        $candidate = $this->candidate($request);
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(self::REQUESTS))], 'description' => ['required', 'string', 'max:3000'], 'idempotency_key' => ['required', 'uuid']]);
        $existing = DB::table('cpmi_requests')->where('idempotency_key', $data['idempotency_key'])->first();
        abort_if($existing && $existing->candidate_id !== $candidate->id, 404);
        if (! $existing) {
            DB::table('cpmi_requests')->insert(['candidate_id' => $candidate->id, 'type' => $data['type'], 'payload' => json_encode(['description' => $data['description']]), 'idempotency_key' => $data['idempotency_key'], 'status' => 'SUBMITTED', 'created_at' => now(), 'updated_at' => now()]);
            app(AccessPolicy::class)->audit($request->user(), 'portal.request.created', ['type' => $data['type']]);
        }
        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return to_route('portal.requests')->with('success', 'Permintaan diterima. Tim kami akan meninjau.');
    }

    public function profileUpdate(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate(['description' => ['required', 'string', 'max:3000'], 'idempotency_key' => ['required', 'uuid']]);
        $request->merge(['type' => 'profile_correction']);

        return $this->requestHelp($request);
    }

    public function read(Request $request, int $notification): RedirectResponse|JsonResponse
    {
        $query = DB::table('portal_notifications')->where('id', $notification)->where('candidate_id', $request->attributes->get('portal_account')->candidate_id);
        abort_unless($query->exists(), 404);
        $query->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function contact(Request $request): RedirectResponse|JsonResponse
    {
        $candidate = $this->candidate($request);
        $application = $this->application($candidate);
        $cs = $application?->assignedCs;
        $member = $cs ? DB::table('cs_members')->where('user_id', $cs->id)->where('is_active', true)->first() : null;
        $phone = $cs?->is_active ? ($cs->whatsapp ?: $member?->whatsapp) : null;
        if (! $phone) {
            throw ValidationException::withMessages(['contact' => 'Kontak CS belum tersedia. Silakan ajukan permintaan dihubungi.']);
        }
        $url = 'https://wa.me/'.ltrim(CsRouter::phone($phone), '+').'?text='.rawurlencode('Halo, saya '.$candidate->person?->full_name.'. Mohon bantuan untuk proses saya di Juragan Migran.');

        return $request->expectsJson() ? response()->json(['url' => $url]) : redirect()->away($url);
    }

    public function preferences(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['in_app_notifications' => ['required', 'boolean'], 'whatsapp_notifications' => ['required', 'boolean']]);
        DB::table('portal_accounts')->where('user_id', $request->user()->id)->update($data + ['updated_at' => now()]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Pilihan notifikasi tersimpan.');
    }

    public function revokeSessions(Request $request): RedirectResponse|JsonResponse
    {
        app(AuthenticationSecurity::class)->revokeOtherSessions($request, $request->user());

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Sesi pada perangkat lain telah dicabut.');
    }

    public function logout(Request $request): RedirectResponse
    {
        app(AccessPolicy::class)->audit($request->user(), 'auth.logout');
        DB::table('auth_session_records')->where('id', $request->session()->get('security.record'))->update(['revoked_at' => now()]);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('portal.login');
    }

    private function staffRequestQuery(Request $request): object
    {
        $query = DB::table('cpmi_requests as r')->join('candidates as c', 'c.id', '=', 'r.candidate_id')->join('persons as p', 'p.id', '=', 'c.person_id');
        if (! collect(app(AccessPolicy::class)->policies($request->user(), 'candidate.read.basic'))->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL')) {
            $query->whereIn('r.candidate_id', app(AccessPolicy::class)->candidateIds($request->user(), 'candidate.read.basic'));
        }

        return $query;
    }

    public function staffRequests(Request $request): View|JsonResponse
    {
        $workspace = app(CrmWorkspace::class);
        $filters = $workspace->filters($request, ['status' => 'nullable|in:'.implode(',', array_keys(self::STATUSES)), 'type' => 'nullable|in:'.implode(',', array_keys(self::REQUESTS)), 'priority' => 'nullable|in:normal,high,urgent', 'selected' => 'nullable|integer']);
        $query = $this->staffRequestQuery($request);
        foreach (['status', 'type', 'priority'] as $field) {
            if ($filters[$field] ?? '') {
                $query->where('r.'.$field, $filters[$field]);
            }
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('p.full_name', 'like', '%'.$search.'%')->orWhere('c.candidate_code', 'like', '%'.$search.'%'));
        }
        $workspace->dates($query, $filters, 'r.created_at');
        $today = $workspace->todayStart();
        $summary = ['Permintaan Baru' => (clone $query)->where('r.status', 'SUBMITTED')->count(), 'Menunggu Review' => (clone $query)->where('r.status', 'REVIEWING')->count(), 'Perlu Verifikasi' => (clone $query)->where('r.type', 'profile_correction')->whereNotIn('r.status', ['DONE', 'APPROVED', 'REJECTED'])->whereNull('r.verified_at')->count(), 'Selesai Hari Ini' => (clone $query)->whereIn('r.status', ['DONE', 'APPROVED', 'REJECTED'])->where('r.resolved_at', '>=', $today)->where('r.resolved_at', '<', $today->copy()->addDay())->count()];
        $rows = $query->select('r.id', 'r.candidate_id', 'r.type', 'r.status', 'r.priority', 'r.created_at', 'p.full_name', 'c.candidate_code')->orderBy('r.created_at', ($filters['sort'] ?? '') === 'oldest' ? 'asc' : 'desc')->orderByDesc('r.id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $selected = $filters['selected'] ?? $rows->first()?->id;
        $detail = $selected ? $this->staffRequestQuery($request)->where('r.id', $selected)->select('r.*', 'p.full_name', 'c.candidate_code')->first() : null;
        if (isset($filters['selected'])) {
            abort_unless($detail, 404);
        }
        $history = $detail ? DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('action', 'cpmi.request.reviewed')->where('new_values->request_id', $detail->id)->latest('audit_logs.id')->limit(20)->get(['audit_logs.created_at', 'users.name', 'audit_logs.new_values']) : collect();

        return $workspace->response($request, 'portal.staff-requests', ['requests' => $rows, 'detail' => $detail, 'history' => $history], $rows, $summary, $filters, fn ($row): array => ['id' => $row->id, 'candidate' => $row->full_name, 'candidate_code' => $row->candidate_code, 'type' => $row->type, 'priority' => $row->priority, 'status' => $row->status, 'submitted_at' => $row->created_at]);
    }

    public function staffRequestDetail(Request $request, int $record): View
    {
        $detail = $this->staffRequestQuery($request)->where('r.id', $record)->select('r.*', 'p.full_name', 'c.candidate_code')->first() ?? abort(404);
        $history = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('action', 'cpmi.request.reviewed')->where('new_values->request_id', $record)->latest('audit_logs.id')->limit(20)->get(['audit_logs.created_at', 'users.name', 'audit_logs.new_values']);

        return view('portal.request-detail', ['detail' => $detail, 'history' => $history]);
    }

    public function review(Request $request, int $record): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('process.update'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(self::STATUSES))], 'public_note' => 'required|string|max:2000', 'internal_note' => 'nullable|string|max:3000', 'priority' => 'nullable|in:normal,high,urgent', 'updated_at' => 'nullable|string', 'review_token' => 'nullable|string|size:64', 'verified' => 'sometimes|accepted', 'correction_field' => 'nullable|in:full_name,nik,birth_date,whatsapp,email,address,education_level', 'correction_value' => 'nullable|string|max:2000', 'evidence_reference' => 'nullable|string|max:255']);
        DB::transaction(function () use ($request, $record, $data): void {
            $row = DB::table('cpmi_requests')->where('id', $record)->lockForUpdate()->first() ?? abort(404);
            abort_unless(app(AccessPolicy::class)->canCandidate($request->user(), $row->candidate_id, 'process.update'), 403);
            $token = hash('sha256', json_encode([$row->id, $row->status, $row->payload, $row->public_note, $row->internal_note, $row->priority, $row->updated_at]));
            if (isset($data['review_token']) && ! hash_equals($token, $data['review_token'])) {
                throw ValidationException::withMessages(['review_token' => 'Keputusan telah berubah. Muat ulang sebelum menyimpan.']);
            }
            if (isset($data['updated_at']) && $row->updated_at !== $data['updated_at']) {
                throw ValidationException::withMessages(['updated_at' => 'Permintaan sudah diperbarui. Muat ulang sebelum mengambil keputusan.']);
            }
            $changes = collect($data)->only(['status', 'public_note', 'internal_note', 'priority'])->all();
            if ($row->type === 'profile_correction' && $data['status'] === 'APPROVED') {
                $request->validate(['verified' => 'required|accepted', 'evidence_reference' => 'required|string|max:255', 'correction_field' => 'required', 'correction_value' => 'required']);
                $policy = app(AccessPolicy::class);
                abort_unless($policy->canCandidate($request->user(), $row->candidate_id, 'candidate.update.profile'), 403);
                $field = $data['correction_field'];
                $permission = match ($field) {
                    'nik', 'birth_date' => 'candidate.read.identity', 'whatsapp', 'email' => 'candidate.read.contact', 'address' => 'candidate.read.location', default => 'candidate.read.basic'
                };
                abort_unless($policy->canCandidate($request->user(), $row->candidate_id, $permission), 403);
                $candidate = Candidate::withoutGlobalScope('access')->whereKey($row->candidate_id)->lockForUpdate()->firstOrFail();
                $person = $candidate->person()->lockForUpdate()->firstOrFail();
                $request->validate(['correction_value' => match ($field) {
                    'nik' => ['required', 'digits:16', Rule::unique('persons', 'nik')->ignore($person->id)], 'birth_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'whatsapp' => ['required', 'regex:/^\+?[0-9][0-9 ()-]{7,28}$/', 'max:30'], 'email' => 'required|email|max:255', 'full_name', 'education_level' => 'required|string|max:255', default => 'required|string|max:2000'
                }]);
                $before = $person->{$field};
                $person->update([$field => $data['correction_value']]);
                $payload = json_decode($row->payload, true);
                $payload['review'] = ['field' => $field, 'before' => $before, 'after' => $data['correction_value'], 'evidence_reference' => $data['evidence_reference']];
                $changes += ['payload' => json_encode($payload), 'verified_by' => $request->user()->id, 'verified_at' => now()];
                $policy->audit($request->user(), 'cpmi.profile.correction_applied', ['request_id' => $record, 'candidate_id' => $row->candidate_id, 'field' => $field, 'before' => $before, 'after' => $data['correction_value']]);
            }
            $resolved = in_array($data['status'], ['DONE', 'APPROVED', 'REJECTED'], true);
            DB::table('cpmi_requests')->where('id', $record)->update([...$changes, 'resolved_at' => $resolved ? ($row->resolved_at ?? now()) : null, 'updated_at' => now()]);
            DB::table('portal_notifications')->insert(['candidate_id' => $row->candidate_id, 'category' => 'REQUEST_UPDATE', 'title' => 'Permintaan Anda diperbarui', 'body_safe' => self::STATUSES[$data['status']], 'deep_link' => '/portal/requests', 'created_at' => now(), 'updated_at' => now()]);
            app(AccessPolicy::class)->audit($request->user(), 'cpmi.request.reviewed', ['request_id' => $record, 'status' => $data['status'], 'previous_status' => $row->status]);
        });

        return to_route('portal-admin.requests', ['selected' => $record])->with('success', 'Permintaan diperbarui. Catatan internal tidak dikirim ke portal.');
    }
}
