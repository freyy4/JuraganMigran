<?php

namespace App\Http\Controllers;

use App\Models\Vacancy;
use App\Services\AttributionService;
use App\Services\ConversationCrm;
use App\Services\CsRouter;
use App\Services\DocumentScanner;
use App\Services\FunnelSchema;
use App\Services\IdentityResolutionService;
use App\Services\LeadUpsertService;
use App\Services\SiteAttribution;
use App\Services\TrackingHub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class FunnelPublicController extends Controller
{
    private function published(string $slug): array
    {
        $funnel = DB::table('application_funnels')->where('slug', $slug)->where('status', 'published')->first() ?? abort(404);
        if (preg_match('/^legacy-(\d+)-/', $slug, $matches)) {
            $page = DB::table('landing_pages')->where('id', $matches[1])->where('status', 'published')->first() ?? abort(404);
            if ($page->campaign_id) {
                $today = now('Asia/Jakarta')->toDateString();
                $campaign = DB::table('campaigns')->find($page->campaign_id);
                abort_unless($campaign && $campaign->status === 'active' && (! $campaign->start_date || $campaign->start_date <= $today) && (! $campaign->end_date || $campaign->end_date >= $today) && (! $campaign->vacancy_id || $campaign->vacancy_id === $page->vacancy_id), 404);
            }
        }
        $version = DB::table('funnel_versions')->where('id', $funnel->active_version_id)->where('funnel_id', $funnel->id)->first() ?? abort(404);
        $vacancy = Vacancy::with(['jobOrder.country', 'jobOrder.company'])->whereKey($version->vacancy_id)->where('status', 'published')->firstOrFail();

        abort_if($vacancy->public_enabled && (($vacancy->valid_through && $vacancy->valid_through->isPast()) || ($vacancy->publish_at && $vacancy->publish_at->isFuture())), 404);

        return [$funnel, $version, $vacancy, json_decode($version->schema_snapshot, true)];
    }

    public function show(Request $request, string $slug): View
    {
        [$funnel,$version,$vacancy,$schema] = $this->published($slug);
        $key = 'funnel_token.'.$funnel->id;
        $entry = $request->session()->get($key);
        if (! $entry || $entry['version'] !== $version->id) {
            $entry = ['token' => (string) Str::uuid(), 'version' => $version->id];
            $request->session()->put($key, $entry);
        }
        app(SiteAttribution::class)->capture($request, $vacancy->id);
        $this->logEvent($request, $funnel->id, $version->id, 'view');
        $attribution = $request->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referral_code']);
        $request->session()->put('funnel_attribution.'.$funnel->id, array_filter($attribution, fn ($value) => is_string($value) && mb_strlen($value) <= 255) + ['landing_url' => $request->url(), 'referrer' => mb_substr($request->headers->get('referer', ''), 0, 500)]);

        return view('funnels.public', compact('funnel', 'vacancy', 'schema') + ['token' => $entry['token'], 'version' => $version->id, 'isTest' => false, 'submitUrl' => route('apply.submit', $slug)]);
    }

    public function store(Request $request, string $slug): RedirectResponse
    {
        [$funnel,$currentVersion,$vacancy,$schema] = $this->published($slug);
        $request->validate(['idempotency_key' => ['required', 'uuid'], 'version_id' => ['required', 'integer'], 'consent' => ['accepted'], 'marketing_consent' => ['sometimes', 'boolean'], 'website' => ['prohibited']]);
        $entry = $request->session()->get('funnel_token.'.$funnel->id);
        abort_unless($entry && hash_equals($entry['token'], $request->input('idempotency_key')) && $entry['version'] === $request->integer('version_id'), 419, 'Formulir kedaluwarsa. Muat ulang halaman.');
        $existing = DB::table('lead_submissions')->where('idempotency_key', $entry['token'])->first();
        if ($existing) {
            $request->session()->put('funnel_receipt.'.$existing->public_token, true);

            return to_route('apply.thank-you', ['slug' => $slug, 'token' => $existing->public_token]);
        }
        $version = DB::table('funnel_versions')->where('id', $entry['version'])->where('funnel_id', $funnel->id)->first() ?? abort(404);
        $schema = json_decode($version->schema_snapshot, true);
        $result = app(FunnelSchema::class)->answers($request, $schema);
        $answers = $result['answers'];
        $files = [];
        foreach ($answers as $key => $value) {
            if ($value instanceof UploadedFile) {
                app(DocumentScanner::class)->inspect($value, 'answers.'.$key);
            }
        }
        foreach ($answers as $key => $value) {
            if ($value instanceof UploadedFile) {
                $path = $value->store('funnel-uploads', 'documents');
                $files[] = $path;
                $answers[$key] = ['file_path' => $path];
            }
        }
        try {
            $submission = DB::transaction(function () use ($request, $slug, $funnel, $version, $schema, $answers, $entry): object {
                $locked = DB::table('application_funnels')->where('id', $funnel->id)->where('status', 'published')->lockForUpdate()->first() ?? abort(404);
                $currentVacancy = Vacancy::whereKey($version->vacancy_id)->where('status', 'published')->lockForUpdate()->firstOrFail();
                abort_if($currentVacancy->public_enabled && (($currentVacancy->valid_through && $currentVacancy->valid_through->isPast()) || ($currentVacancy->publish_at && $currentVacancy->publish_at->isFuture())), 404);
                $prior = DB::table('lead_submissions')->where('idempotency_key', $entry['token'])->first();
                if ($prior) {
                    return $prior;
                }
                $attribution = $request->session()->get('funnel_attribution.'.$funnel->id, []) + app(SiteAttribution::class)->summary($request) + ['funnel_slug' => $slug, 'version' => $version->version];
                $existingLead = app(IdentityResolutionService::class)->resolve($answers['whatsapp']);
                $lead = app(LeadUpsertService::class)->upsert($answers['name'], $answers['whatsapp'], $answers['email'] ?? null, $attribution['utm_source'] ?? 'UNKNOWN');
                $state = $existingLead ? 'EXISTING_LEAD_NEW_APPLICATION' : 'NEW_LEAD';
                $prior = DB::table('lead_submissions')->where('lead_id', $lead->id)->where('vacancy_id', $version->vacancy_id)->where('is_test', false)->latest('id')->first();
                if ($prior) {
                    $state = 'EXISTING_APPLICATION';
                }
                $attribution = $request->session()->get('funnel_attribution.'.$funnel->id, []) + app(SiteAttribution::class)->summary($request) + ['funnel_slug' => $slug, 'version' => $version->version];
                $id = DB::table('lead_submissions')->insertGetId(['public_token' => (string) Str::uuid(), 'idempotency_key' => $entry['token'], 'lead_id' => $lead->id, 'funnel_id' => $funnel->id, 'version_id' => $version->id, 'vacancy_id' => $version->vacancy_id, 'application_id' => $prior?->application_id, 'payload' => json_encode(['encrypted' => Crypt::encryptString(json_encode($answers))]), 'attribution' => json_encode($attribution), 'duplicate_state' => $state, 'consent_version' => $schema['consent_version'], 'consented_at' => now(), 'marketing_consent' => $request->boolean('marketing_consent'), 'is_test' => false, 'created_at' => now(), 'updated_at' => now()]);
                app(LeadUpsertService::class)->interest($lead->id, $version->vacancy_id);
                app(AttributionService::class)->record($lead->id, 'submission-'.$id, 'FORM_SUBMITTED', $attribution['utm_source'] ?? 'UNKNOWN', $attribution + ['anonymous_session_id' => app(SiteAttribution::class)->session($request), 'funnel_id' => $funnel->id]);
                app(ConversationCrm::class)->activity($lead->id, 'form_submitted', 'Formulir #'.$id.' · minat lowongan #'.$version->vacancy_id);
                app(SiteAttribution::class)->link($request, $lead->id);
                app(SiteAttribution::class)->event($request, 'form_submit_success', [], $version->vacancy_id);
                app(CsRouter::class)->assign($lead->id, $version->vacancy_id, $id);

                return DB::table('lead_submissions')->find($id);
            });
        } catch (\Throwable $exception) {
            foreach ($files as $file) {
                Storage::disk('documents')->delete($file);
            }throw $exception;
        }
        try {
            app(TrackingHub::class)->fromRequest($request, 'FORM_SUBMIT', 'submission-'.$submission->id.'-submit', ['lead_id' => $submission->lead_id, 'funnel_id' => $funnel->id, 'funnel_version_id' => $version->id, 'vacancy_id' => $version->vacancy_id]);
            if ($submission->duplicate_state === 'NEW_LEAD') {
                app(TrackingHub::class)->fromRequest($request, 'LEAD_CREATED', 'lead-'.$submission->lead_id, ['lead_id' => $submission->lead_id, 'funnel_id' => $funnel->id, 'funnel_version_id' => $version->id, 'vacancy_id' => $version->vacancy_id]);
            }
        } catch (\Throwable) {
            Log::warning('Tracking side effect failed after lead commit.');
        }
        $request->session()->put('funnel_receipt.'.$submission->public_token, true);
        if ($result['route']) {
            $target = substr($result['route'], strlen('/apply/'));
            if (DB::table('application_funnels')->where('slug', $target)->where('status', 'published')->exists()) {
                return to_route('apply.show', $target)->with('success', 'Pendaftaran tersimpan. Lanjutkan ke formulir berikutnya.');
            }
        }
        if ($schema['post_submit'] === 'EXTERNAL_REDIRECT') {
            return redirect()->away($schema['external_redirect']);
        }

        return to_route('apply.thank-you', ['slug' => $slug, 'token' => $submission->public_token]);
    }

    private function receipt(Request $request, string $slug, string $token): array
    {
        abort_unless(Str::isUuid($token) && $request->session()->get('funnel_receipt.'.$token), 404);
        $submission = DB::table('lead_submissions')->where('public_token', $token)->first() ?? abort(404);
        $funnel = DB::table('application_funnels')->where('id', $submission->funnel_id)->where('slug', $slug)->first() ?? abort(404);
        $version = DB::table('funnel_versions')->find($submission->version_id);

        return [$submission, $funnel, json_decode($version->schema_snapshot, true)];
    }

    public function thankYou(Request $request, string $slug, string $token): View
    {
        [$submission,$funnel,$schema] = $this->receipt($request, $slug, $token);
        $lead = DB::table('acquisition_leads')->find($submission->lead_id);
        $member = $lead?->assigned_member_id ? DB::table('cs_members')->find($lead->assigned_member_id) : null;

        return view('funnels.thank-you', ['slug' => $slug, 'token' => $token, 'reference' => $submission->public_token, 'schema' => $schema, 'whatsAppAvailable' => (bool) ($member && $member->is_active), 'vacancy' => Vacancy::find($submission->vacancy_id), 'trackingReceipt' => DB::table('canonical_tracking_events')->where('idempotency_key', 'submission-'.$submission->id.'-submit')->value('event_id'), 'trackingLeadReceipt' => $submission->duplicate_state === 'NEW_LEAD' ? DB::table('canonical_tracking_events')->where('idempotency_key', 'lead-'.$submission->lead_id)->value('event_id') : null, 'trackingVersion' => $submission->version_id]);
    }

    public function whatsapp(Request $request, string $slug, string $token): RedirectResponse
    {
        [$submission,$funnel,$schema] = $this->receipt($request, $slug, $token);
        $lead = DB::table('acquisition_leads')->find($submission->lead_id) ?? abort(404);
        $member = app(CsRouter::class)->assign($lead->id, $submission->vacancy_id, $submission->id);
        if (! $member || ! $member->is_active) {
            return back()->withErrors(['whatsapp' => 'CS belum tersedia. Pendaftaran Anda sudah tersimpan. Silakan coba kembali nanti.']);
        }
        $vacancy = Vacancy::with(['jobOrder.country', 'jobOrder.company'])->findOrFail($submission->vacancy_id);
        $message = app(CsRouter::class)->message($lead, $vacancy, $member, $schema['whatsapp_template_id'] ?? null, $submission);
        $this->logEvent($request, $funnel->id, $submission->version_id, 'whatsapp_opened');
        try {
            app(TrackingHub::class)->leadMilestone($lead->id, 'WHATSAPP_CLICK', 'submission-'.$submission->id.'-whatsapp');
        } catch (\Throwable) {
            Log::warning('Tracking side effect failed during WhatsApp handoff.');
        }

        return redirect()->away(app(CsRouter::class)->url($member, $message));
    }

    public function event(Request $request, string $slug): JsonResponse
    {
        [$funnel,$version] = $this->published($slug);
        $data = $request->validate(['event' => ['required', 'in:start,step_completed'], 'step' => ['nullable', 'integer', 'min:0', 'max:10']]);
        $this->logEvent($request, $funnel->id, $version->id, $data['event'], $data['step'] ?? null);

        return response()->json(['ok' => true]);
    }

    private function logEvent(Request $request, int $funnel, int $version, string $event, ?int $step = null): void
    {
        DB::table('funnel_analytics_events')->insert(['funnel_id' => $funnel, 'version_id' => $version, 'visitor_hash' => hash_hmac('sha256', $request->session()->getId(), config('app.key')), 'event' => $event, 'step' => $step, 'created_at' => now(), 'updated_at' => now()]);
    }
}
