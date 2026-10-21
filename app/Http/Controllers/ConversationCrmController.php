<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccessPolicy;
use App\Services\AttributionService;
use App\Services\ConversationCrm;
use App\Services\ConversionService;
use App\Services\CrmLeadQuery;
use App\Services\CsRouter;
use App\Services\DocumentScanner;
use App\Services\LeadUpsertService;
use App\Services\WhatsAppCloud;
use App\Services\WhatsAppQrGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ConversationCrmController extends Controller
{
    public function __construct(private ConversationCrm $crm) {}

    public function inbox(Request $request): View|JsonResponse
    {
        abort_unless(config('conversation.enabled'), 404);
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'queue' => 'nullable|in:all,mine,unread,unassigned,overdue', 'channel' => 'nullable|integer', 'conversation' => 'nullable|integer', 'before' => 'nullable|integer|min:1']);
        $query = $this->crm->conversations($request)->join('acquisition_leads as lead', 'lead.id', '=', 'crm_conversations.lead_id')->join('whatsapp_channels as channel', 'channel.id', '=', 'crm_conversations.channel_id')->leftJoin('users as owner', 'owner.id', '=', 'crm_conversations.assigned_user_id');
        if ($search = $filters['search'] ?? null) {
            $query->where(fn ($q) => $q->where('lead.name', 'like', '%'.$search.'%')->when($request->user()->hasPermission('lead.read.contact'), fn ($q) => $q->orWhere('lead.normalized_phone', 'like', '%'.$search.'%')));
        }
        match ($filters['queue'] ?? 'all') {
            'mine' => $query->where('crm_conversations.assigned_user_id', $request->user()->id),
            'unread' => $query->where('unread_count', '>', 0),
            'unassigned' => $query->whereNull('crm_conversations.assigned_user_id'),
            'overdue' => $query->where('lead.next_followup_at', '<', now()),
            default => null,
        };
        $query->when($filters['channel'] ?? null, fn ($q, $id) => $q->where('channel_id', $id));
        $conversations = $query->select('crm_conversations.*', 'lead.name', 'lead.crm_source', 'lead.crm_stage', 'lead.next_followup_at', 'owner.name as owner_name', 'channel.provider as channel_provider', 'channel.name as channel_name')->selectSub(DB::table('crm_messages')->whereColumn('conversation_id', 'crm_conversations.id')->orderByDesc('provider_timestamp')->orderByDesc('id')->limit(1)->select('text'), 'preview')->orderByDesc('last_message_at')->orderByDesc('crm_conversations.id')->paginate(30)->withQueryString();
        $selected = isset($filters['conversation']) ? $this->crm->conversations($request)->where('crm_conversations.id', $filters['conversation'])->first() : null;
        abort_if(isset($filters['conversation']) && ! $selected, 404);
        $messages = null;
        $lead = null;
        if ($selected) {
            $selected->channel_provider = DB::table('whatsapp_channels')->where('id', $selected->channel_id)->value('provider');
            $lead = DB::table('acquisition_leads')->find($selected->lead_id);
            $messageQuery = DB::table('crm_messages')->where('conversation_id', $selected->id);
            if (isset($filters['before'])) {
                $cursor = DB::table('crm_messages')->where('conversation_id', $selected->id)->where('id', $filters['before'])->first() ?? abort(404);
                $messageQuery->where(fn ($q) => $q->where('provider_timestamp', '<', $cursor->provider_timestamp)->orWhere(fn ($q) => $q->where('provider_timestamp', $cursor->provider_timestamp)->where('id', '<', $cursor->id)));
            }
            $messages = $messageQuery->orderByDesc('provider_timestamp')->orderByDesc('id')->limit(50)->get()->sortBy(fn ($m) => ($m->provider_timestamp ?? $m->created_at).sprintf('%020d', $m->id))->values()->map(function ($message) {
                unset($message->provider_payload);

                return $message;
            });
        }
        if ($request->expectsJson()) {
            if ($selected) {
                unset($selected->wa_contact_id);
                $channel = DB::table('whatsapp_channels')->find($selected->channel_id);
                $selected->owner_name = User::find($selected->assigned_user_id)?->name;
                $selected->can_reply = $this->crm->conversations($request, 'crm.conversation.reply')->where('id', $selected->id)->exists()
                    && (! $channel->strict_owner || $selected->assigned_user_id === $request->user()->id)
                    && $selected->status === 'OPEN';
            }
            $conversations->through(function ($conversation) {
                unset($conversation->wa_contact_id);

                return $conversation;
            });

            return response()->json(['conversations' => $conversations, 'selected' => $selected, 'messages' => $messages, 'older_before' => $messages?->first()?->id]);
        }
        $visible = $this->crm->conversations($request);
        $metrics = ['Percakapan terbuka' => (clone $visible)->where('status', 'OPEN')->count(), 'Belum ditugaskan' => (clone $visible)->whereNull('assigned_user_id')->count(), 'Belum dibaca' => (clone $visible)->where('unread_count', '>', 0)->count(), 'Follow-up overdue' => $this->crm->leads($request)->where('next_followup_at', '<', now())->count()];

        return view('conversation.inbox', ['metrics' => $metrics] + $this->details($request, $lead) + compact('conversations', 'selected', 'messages', 'filters') + ['channels' => $this->visibleChannels($request), 'templates' => $selected ? DB::table('crm_whatsapp_templates')->where('channel_id', $selected->channel_id)->where('status', 'APPROVED')->get() : collect(), 'quickReplies' => DB::table('crm_quick_replies')->where('is_active', true)->limit(50)->get()]);
    }

    private function visibleChannels(Request $request): object
    {
        return DB::table('whatsapp_channels')->whereIn('id', $this->crm->conversations($request)->select('channel_id'))->get(['id', 'name', 'status']);
    }

    private function details(Request $request, ?object $lead): array
    {
        $tags = $lead ? DB::table('crm_tags')->join('crm_lead_tags', 'tag_id', '=', 'crm_tags.id')->where('lead_id', $lead->id)->select('crm_tags.*')->get() : collect();

        return ['attribution' => $lead ? app(AttributionService::class)->summary($lead->id) : [], 'applications' => $lead?->candidate_id ? DB::table('applications')->leftJoin('pipeline_stages', 'pipeline_stages.id', '=', 'applications.current_stage_id')->whereIn('applications.id', app(AccessPolicy::class)->applicationIds($request->user(), 'candidate.read.basic'))->where('candidate_id', $lead->candidate_id)->select('applications.id', 'applications.application_code', 'applications.status', 'pipeline_stages.name as stage_name')->limit(30)->get() : collect(), 'lead' => $lead, 'tags' => $tags, 'allTags' => DB::table('crm_tags')->where('is_active', true)->limit(200)->get(), 'followUps' => $lead ? DB::table('crm_follow_ups')->where('lead_id', $lead->id)->orderBy('due_at')->limit(30)->get() : collect(), 'phone' => $lead && app(AcquisitionController::class)->scopedLeads($request, 'lead.read.contact')->where('id', $lead->id)->exists() ? $lead->normalized_phone : ($lead ? app(AccessPolicy::class)->mask($lead->normalized_phone) : ''), 'owners' => User::where('is_active', true)->when(! collect(app(AccessPolicy::class)->policies($request->user(), 'crm.lead.assign'))->contains('scope', 'OPERATIONAL_ALL'), fn ($q) => $q->where('team_id', $request->user()->team_id ?? -1))->get(['id', 'name', 'team_id'])->filter(fn ($u) => $u->hasPermission('crm.conversation.reply')), 'vacancies' => DB::table('vacancies')->whereIn('id', app(AccessPolicy::class)->vacancyIds($request->user(), 'lead.convert_to_candidate'))->orderBy('title')->limit(200)->get(['id', 'title'])];
    }

    public function read(Request $request, int $conversation): RedirectResponse
    {
        $row = $this->crm->conversations($request)->where('id', $conversation)->first() ?? abort(404);
        DB::table('crm_conversations')->where('id', $conversation)->update(['unread_count' => 0]);
        $this->crm->activity($row->lead_id, 'conversation_read', 'Percakapan ditandai dibaca.', $request->user()->id);

        return back();
    }

    public function send(Request $request, int $conversation): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['text' => 'nullable|string|max:4096', 'mode' => 'required|in:text,note,template', 'template_id' => 'nullable|integer', 'variables' => 'nullable|array|max:20', 'variables.*' => 'required|string|max:500', 'client_key' => 'required|uuid', 'version' => 'required|integer|min:0', 'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240']);
        abort_if($request->hasFile('attachment') && $data['mode'] !== 'text', 422, 'Lampiran hanya dapat dikirim sebagai balasan WhatsApp dalam window layanan.');
        $scoped = $this->crm->conversations($request, 'crm.conversation.reply')->where('id', $conversation)->first() ?? abort(404);
        $storedMedia = null;
        try {
            $id = DB::transaction(function () use ($data, $request, $conversation, $scoped, &$storedMedia): ?int {
                $row = DB::table('crm_conversations')->where('id', $conversation)->lockForUpdate()->first();
                $channel = DB::table('whatsapp_channels')->find($row->channel_id);
                abort_if($row->version !== (int) $data['version'], 409, 'Owner percakapan berubah. Muat ulang sebelum mengirim.');
                abort_if($channel->strict_owner && $row->assigned_user_id !== $request->user()->id, 409, 'Hanya owner aktif dapat membalas. Ambil alih percakapan dengan alasan terlebih dahulu.');
                abort_if($row->status !== 'OPEN', 422, 'Buka kembali percakapan sebelum membalas.');
                $existing = DB::table('crm_messages')->where('client_key', $data['client_key'])->first();
                if ($existing) {
                    abort_unless($existing->conversation_id === $conversation && $existing->sent_by_user_id === $request->user()->id, 409);

                    return $existing->id;
                }
                if ($data['mode'] === 'note') {
                    if (! trim($data['text'] ?? '')) {
                        throw ValidationException::withMessages(['text' => 'Isi catatan wajib diisi.']);
                    }
                    DB::table('crm_messages')->insert(['conversation_id' => $conversation, 'direction' => 'INTERNAL', 'message_type' => 'note', 'text' => $data['text'], 'client_key' => $data['client_key'], 'status' => 'SAVED', 'sent_by_user_id' => $request->user()->id, 'provider_timestamp' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    $this->crm->activity($scoped->lead_id, 'note_added', $data['text'], $request->user()->id);

                    return null;
                }
                abort_unless($channel->is_active && $channel->status === 'CONNECTED', 422, 'Channel belum terhubung. Hubungi admin integrasi.');
                if ($channel->provider === 'QR_WEB_SESSION') {
                    $qr = DB::table('whatsapp_qr_sessions')->where('channel_id', $channel->id)->first();
                    abort_unless(app(WhatsAppQrGateway::class)->ready() && $qr && $qr->activated && $qr->state === 'CONNECTED' && $qr->last_heartbeat_at && now()->diffInSeconds($qr->last_heartbeat_at, true) <= config('whatsapp-qr.heartbeat_seconds'), 422, 'Sesi WhatsApp belum sehat. Pesan belum dikirim; tunggu koneksi pulih.');
                    abort_if(DB::table('crm_messages')->whereIn('conversation_id', DB::table('crm_conversations')->where('channel_id', $channel->id)->select('id'))->where('status', 'QUEUED')->count() >= 100, 422, 'Antrean pengiriman sedang penuh. Coba setelah koneksi pulih.');
                }
                $payload = ['messaging_product' => 'whatsapp', 'to' => $row->wa_contact_id, 'type' => $data['mode']];
                if ($data['mode'] === 'text') {
                    abort_unless($channel->provider === 'QR_WEB_SESSION' || ($row->service_window_expires_at && now()->lt($row->service_window_expires_at)), 422, 'Window 24 jam berakhir. Pilih template WhatsApp yang disetujui.');
                    if (! trim($data['text'] ?? '') && ! $request->hasFile('attachment')) {
                        throw ValidationException::withMessages(['text' => 'Isi pesan atau lampiran wajib diisi.']);
                    }
                    $text = $data['text'] ?? '';
                    if ($request->hasFile('attachment')) {
                        abort_if(mb_strlen($text) > 1024, 422, 'Caption lampiran maksimal 1024 karakter.');
                        $file = $request->file('attachment');
                        app(DocumentScanner::class)->inspect($file, 'attachment');
                        $storedMedia = $file->store('crm-outbox', 'local');
                        $type = str_starts_with($file->getMimeType(), 'image/') ? 'image' : 'document';
                        $payload['type'] = $type;
                        $payload[$type] = array_filter(['caption' => $text]);
                        if ($type === 'document') {
                            $payload[$type]['filename'] = 'informasi.pdf';
                        }
                        $payload['_local_media'] = ['path' => $storedMedia, 'mime' => $file->getMimeType()];
                        $text = '['.$type.'] '.$text;
                    } else {
                        $payload['text'] = ['body' => $text];
                    }

                } else {
                    abort_unless($channel->provider === 'META_CLOUD_API', 422, 'Template Meta hanya tersedia pada channel Cloud API. Gunakan balasan biasa untuk QR.');
                    abort_unless($request->user()->hasPermission('crm.whatsapp_template.use'), 403);
                    $template = DB::table('crm_whatsapp_templates')->where('id', $data['template_id'] ?? 0)->where('channel_id', $row->channel_id)->where('status', 'APPROVED')->first() ?? abort(422, 'Pilih template approved untuk channel ini.');
                    $components = json_decode($template->components, true);
                    abort_if(collect($components)->contains(fn ($c) => ! in_array($c['type'], ['BODY', 'FOOTER'], true)), 422, 'Template ini memerlukan komponen tambahan. Gunakan template teks.');
                    $body = collect($components)->firstWhere('type', 'BODY')['text'] ?? '';
                    preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
                    abort_unless(count(array_unique($matches[1])) === count($data['variables'] ?? []), 422, 'Lengkapi seluruh variabel template.');
                    $payload['template'] = ['name' => $template->name, 'language' => ['code' => $template->language]];
                    if (! empty($data['variables'])) {
                        $payload['template']['components'] = [['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => $v], array_values($data['variables']))]];
                    }
                    $text = $body;
                    foreach (array_values($data['variables'] ?? []) as $i => $value) {
                        $text = str_replace('{{'.($i + 1).'}}', $value, $text);
                    }
                    $this->crm->activity($row->lead_id, 'template_used', 'Template '.$template->name.' / '.$template->language, $request->user()->id);
                }
                $id = DB::table('crm_messages')->insertGetId(['conversation_id' => $conversation, 'client_key' => $data['client_key'], 'direction' => 'OUTBOUND', 'message_type' => $payload['type'], 'text' => $text, 'provider' => $channel->provider, 'provider_payload' => Crypt::encryptString(json_encode($payload)), 'status' => 'QUEUED', 'sent_by_user_id' => $request->user()->id, 'provider_timestamp' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $this->crm->activity($row->lead_id, 'message_queued', 'Pesan masuk antrean pengiriman.', $request->user()->id);

                return $id;
            });

        } catch (\Throwable $exception) {
            if ($storedMedia) {
                Storage::disk('local')->delete($storedMedia);
            }
            throw $exception;
        }

        return $request->expectsJson() ? response()->json(['id' => $id, 'status' => $data['mode'] === 'note' ? 'SAVED' : 'QUEUED'], 202) : back()->with('success', $data['mode'] === 'note' ? 'Catatan internal disimpan.' : 'Pesan masuk antrean. Status pengiriman akan diperbarui.');
    }

    public function assign(Request $request, int $conversation): RedirectResponse
    {
        $data = $request->validate(['user_id' => 'nullable|integer|exists:users,id', 'reason' => 'required|string|max:255', 'version' => 'required|integer|min:0']);
        $permission = $request->routeIs('crm.takeover') ? 'crm.conversation.takeover' : 'crm.lead.assign';
        $this->crm->conversations($request, $permission)->where('id', $conversation)->first() ?? abort(404);
        DB::transaction(function () use ($request, $conversation, $data, $permission): void {
            $row = DB::table('crm_conversations')->where('id', $conversation)->lockForUpdate()->first();
            abort_if($row->version !== (int) $data['version'], 409, 'Penugasan sudah berubah. Muat ulang.');
            abort_if(DB::table('crm_messages')->where('conversation_id', $conversation)->where('status', 'SENDING')->lockForUpdate()->exists(), 409, 'Pesan sedang dikirim. Tunggu status pengiriman sebelum transfer owner.');
            $user = User::findOrFail($permission === 'crm.conversation.takeover' ? $request->user()->id : ($data['user_id'] ?? 0));
            abort_unless($user->is_active && $user->hasPermission('crm.conversation.reply'), 422, 'Pilih CS aktif yang dapat membalas.');
            $all = collect(app(AccessPolicy::class)->policies($request->user(), $permission))->contains('scope', 'OPERATIONAL_ALL');
            abort_unless($all || ($request->user()->team_id && $user->team_id === $request->user()->team_id), 403);
            DB::table('crm_conversations')->where('id', $conversation)->update(['assigned_user_id' => $user->id, 'team_id' => $user->team_id, 'version' => $row->version + 1]);
            DB::table('acquisition_leads')->where('id', $row->lead_id)->update(['assigned_cs_id' => $user->id]);
            $this->crm->activity($row->lead_id, 'owner_changed', 'Owner '.$row->assigned_user_id.' → '.$user->id.': '.$data['reason'], $request->user()->id);
        });

        return back()->with('success', 'Owner percakapan diperbarui.');
    }

    public function close(Request $request, int $conversation): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:OPEN,CLOSED', 'reason' => 'required|string|max:255']);
        DB::transaction(function () use ($request, $conversation, $data): void {
            $row = $this->crm->conversations($request, 'crm.conversation.reply')->where('id', $conversation)->lockForUpdate()->first() ?? abort(404);
            abort_unless($row->assigned_user_id === $request->user()->id, 409, 'Hanya owner dapat menutup atau membuka percakapan.');
            DB::table('crm_conversations')->where('id', $conversation)->update(['status' => $data['status'], 'version' => $row->version + 1]);
            $this->crm->activity($row->lead_id, 'conversation_status', $data['status'].': '.$data['reason'], $request->user()->id);
        });

        return back()->with('success', 'Status percakapan diperbarui.');
    }

    public function pipeline(Request $request): View|JsonResponse
    {
        $filters = app(CrmLeadQuery::class)->filters($request);
        if ($request->filled('view')) {
            $saved = DB::table('crm_saved_views')->where('id', $request->integer('view'))->where('user_id', $request->user()->id)->first() ?? abort(404);
            $request->merge(json_decode($saved->filters, true));
            $request->request->remove('view');
            $request->query->remove('view');

            return $this->pipeline($request);
        }
        $q = app(CrmLeadQuery::class)->query($request);
        $summary = ['total' => (clone $q)->count(), 'unassigned' => (clone $q)->whereNull('assigned_cs_id')->count(), 'overdue' => (clone $q)->where('next_followup_at', '<', now())->count(), 'no_task' => (clone $q)->whereNotIn('id', DB::table('crm_follow_ups')->where('status', 'SCHEDULED')->select('lead_id'))->count()];
        $counts = (clone $q)->selectRaw('crm_stage, COUNT(*) as total')->groupBy('crm_stage')->pluck('total', 'crm_stage');
        $sort = $filters['sort'] ?? 'followup';
        $ordered = function ($query) use ($sort) {
            return match ($sort) {
                'created' => $query->orderByDesc('created_at')->orderByDesc('id'),
                'name' => $query->orderBy('name')->orderBy('id'),
                'activity' => $query->orderByDesc('updated_at')->orderByDesc('id'),
                default => $query->orderByRaw('next_followup_at IS NULL')->orderBy('next_followup_at')->orderByDesc('id'),
            };
        };
        $rows = $ordered(clone $q)->paginate($request->integer('per_page', 50))->withQueryString();
        $columns = [];
        foreach (config('conversation.stages') as $code => $label) {
            $columnQuery = (clone $q)->where('crm_stage', $code);
            $columns[$code] = $columnQuery->orderByDesc('id')->cursorPaginate(25, ['*'], 'cursor', $request->input('column') === $code ? $request->input('cursor') : null);
        }
        $visible = $request->input('mode') === 'list' ? $rows->getCollection() : collect($columns)->flatMap(fn ($column) => $column->items());
        $tagMap = DB::table('crm_lead_tags')->join('crm_tags', 'crm_tags.id', '=', 'tag_id')->whereIn('lead_id', $visible->pluck('id'))->get()->groupBy('lead_id');
        $ownerNames = DB::table('users')->whereIn('id', $visible->pluck('assigned_cs_id')->filter())->pluck('name', 'id');
        $cardPhones = app(AcquisitionController::class)->scopedLeads($request, 'lead.read.contact')->whereIn('id', $visible->pluck('id'))->pluck('normalized_phone', 'id');
        $cardMessages = DB::table('crm_messages')->whereIn('conversation_id', $this->crm->conversations($request)->whereIn('lead_id', $visible->pluck('id'))->select('id'))->whereIn('crm_messages.id', DB::table('crm_messages')->selectRaw('MAX(id)')->groupBy('conversation_id'))->join('crm_conversations', 'crm_conversations.id', '=', 'conversation_id')->get(['lead_id', 'text'])->keyBy('lead_id');
        $cardInterests = DB::table('crm_lead_interests')->join('vacancies', 'vacancies.id', '=', 'vacancy_id')->whereIn('lead_id', $visible->pluck('id'))->get(['lead_id', 'title'])->groupBy('lead_id');
        if ($request->expectsJson()) {
            $safe = fn ($lead) => collect((array) $lead)->only(['id', 'name', 'crm_stage', 'crm_version', 'crm_source', 'assigned_cs_id', 'next_followup_at', 'created_at', 'updated_at'])->all();

            return response()->json(['rows' => $rows->through($safe), 'counts' => $counts, 'summary' => $summary, 'stages' => collect($columns)->map(fn ($column, $code) => ['count' => $counts[$code] ?? 0, 'items' => collect($column->items())->map($safe), 'nextCursor' => $column->nextCursor()?->encode()])]);
        }

        return view('conversation.pipeline', ['filterChannels' => DB::table('whatsapp_channels')->whereIn('id', $this->crm->conversations($request)->select('channel_id'))->get(['id', 'name']), 'filterVacancies' => DB::table('vacancies')->whereIn('id', DB::table('crm_lead_interests')->whereIn('lead_id', $this->crm->leads($request, 'crm.pipeline.read')->select('id'))->select('vacancy_id'))->get(['id', 'title']), 'cardPhones' => $cardPhones, 'cardMessages' => $cardMessages, 'cardInterests' => $cardInterests, 'pipelineOwners' => DB::table('users')->whereIn('id', $this->crm->leads($request, 'crm.pipeline.read')->select('assigned_cs_id'))->get(['id', 'name']), 'rows' => $rows, 'columns' => $columns, 'summary' => $summary, 'ownerNames' => $ownerNames, 'counts' => $counts, 'tagMap' => $tagMap, 'savedViews' => DB::table('crm_saved_views')->where('user_id', $request->user()->id)->get(), 'filters' => $filters, 'allTags' => DB::table('crm_tags')->where('is_active', true)->get(), 'stageSettings' => DB::table('crm_stages')->orderBy('display_order')->get()]);
    }

    public function profile(Request $request, int $lead): View|RedirectResponse
    {
        $canonical = $lead;
        $visited = [];
        while ($alias = DB::table('acquisition_leads')->where('id', $canonical)->value('merged_into_id')) {
            abort_if(isset($visited[$canonical]), 409, 'Rantai alias perlu ditinjau administrator.');
            $visited[$canonical] = true;
            $canonical = (int) $alias;
        }
        if ($canonical !== $lead) {
            $this->crm->leads($request)->where('id', $canonical)->first() ?? abort(404);

            return to_route('crm.profile', $canonical);
        }
        $row = $this->crm->leads($request)->where('id', $lead)->first() ?? abort(404);

        return view('conversation.profile', ['interests' => DB::table('crm_lead_interests')->join('vacancies', 'vacancies.id', '=', 'vacancy_id')->where('lead_id', $lead)->get(['vacancies.title', 'application_id'])] + $this->details($request, $row) + ['activities' => DB::query()->fromSub(DB::table('crm_activities')->leftJoin('users', 'users.id', '=', 'actor_id')->where('lead_id', $lead)->whereNotIn('event_type', ['message_received', 'message_sent', 'message_queued'])->select('crm_activities.id', 'event_type', 'summary', 'crm_activities.created_at', 'users.name as actor_name')->unionAll(DB::table('crm_attribution_touchpoints')->where('lead_id', $lead)->selectRaw('id, event_type, source as summary, event_at as created_at, NULL as actor_name'))->unionAll(DB::table('crm_messages')->leftJoin('users', 'users.id', '=', 'sent_by_user_id')->whereIn('conversation_id', $this->crm->conversations($request)->where('lead_id', $lead)->select('id'))->whereIn('direction', ['INBOUND', 'OUTBOUND'])->selectRaw("crm_messages.id, CASE WHEN direction = 'INBOUND' THEN 'Pesan masuk' ELSE 'Pesan keluar' END as event_type, text as summary, COALESCE(provider_timestamp, crm_messages.created_at) as created_at, users.name as actor_name")), 'timeline')->orderByDesc('created_at')->orderByDesc('id')->paginate(40), 'conversations' => $this->crm->conversations($request)->where('lead_id', $lead)->get(), 'submissions' => DB::table('lead_submissions')->where('lead_id', $lead)->where('is_test', false)->orderByDesc('id')->limit(30)->get()]);
    }

    public function createLead(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'whatsapp' => 'required|string|max:30', 'source' => 'required|string|max:100', 'vacancy_id' => 'nullable|integer|exists:vacancies,id', 'note' => 'nullable|string|max:1000']);
        $phone = CsRouter::phone($data['whatsapp']);
        if (DB::table('acquisition_leads')->where('normalized_phone', $phone)->exists()) {
            throw ValidationException::withMessages(['whatsapp' => 'Nomor sudah terdaftar. Cari lead yang ada sebelum membuat lead baru.']);
        }
        $id = DB::transaction(function () use ($request, $data, $phone): int {
            $created = app(LeadUpsertService::class)->upsert($data['name'], $phone, null, $data['source']);
            $id = $created->id;
            if (! $created->assigned_cs_id) {
                DB::table('acquisition_leads')->where('id', $id)->update(['assigned_cs_id' => $request->user()->id]);
            }
            if (! empty($data['vacancy_id'])) {
                abort_unless(DB::table('vacancies')->whereIn('id', app(AccessPolicy::class)->vacancyIds($request->user(), 'crm.lead.edit'))->where('id', $data['vacancy_id'])->exists(), 403);
                DB::table('crm_lead_interests')->insert(['lead_id' => $id, 'vacancy_id' => $data['vacancy_id'], 'created_at' => now(), 'updated_at' => now()]);
            }
            if (! empty($data['note'])) {
                $this->crm->activity($id, 'note_added', $data['note'], $request->user()->id);
            }
            $this->crm->activity($id, 'lead_created', 'Lead manual dibuat. Sumber: '.$data['source'], $request->user()->id);

            return $id;
        });

        return to_route('crm.profile', $id)->with('success', 'Lead baru disimpan.');
    }

    public function move(Request $request, int $lead): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['stage' => ['required', Rule::in(array_keys(config('conversation.stages')))], 'reason' => 'nullable|string|max:255', 'version' => 'required|integer|min:0']);
        $this->crm->move($request, $lead, $data['stage'], $data['reason'] ?? null, $data['version']);

        return $request->expectsJson() ? response()->json(['stage' => $data['stage'], 'version' => $data['version'] + 1]) : back()->with('success', 'Tahap lead diperbarui.');
    }

    public function tag(Request $request, int $lead): RedirectResponse
    {
        $this->crm->leads($request, 'crm.tag.use')->where('id', $lead)->first() ?? abort(404);
        $data = $request->validate(['tag_id' => 'required|integer|exists:crm_tags,id', 'remove' => 'nullable|boolean']);
        DB::transaction(function () use ($request, $lead, $data): void {
            $tag = DB::table('crm_tags')->where('id', $data['tag_id'])->where('is_active', true)->first() ?? abort(422);
            if ($request->boolean('remove')) {
                DB::table('crm_lead_tags')->where('lead_id', $lead)->where('tag_id', $tag->id)->delete();
            } else {
                DB::table('crm_lead_tags')->insertOrIgnore(['lead_id' => $lead, 'tag_id' => $tag->id, 'added_by' => $request->user()->id, 'created_at' => now()]);
            }
            $this->crm->activity($lead, 'tag_changed', ($request->boolean('remove') ? 'Hapus tag: ' : 'Tambah tag: ').$tag->name, $request->user()->id);
        });

        return back()->with('success', 'Tag diperbarui.');
    }

    public function followUp(Request $request, int $lead): RedirectResponse
    {
        $this->crm->leads($request, 'crm.followup.manage')->where('id', $lead)->first() ?? abort(404);
        $data = $request->validate(['due_at' => 'required|date_format:Y-m-d\TH:i', 'type' => 'required|in:CHAT,CALL,INTERVIEW,OTHER', 'note' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($request, $lead, $data): void {
            DB::table('acquisition_leads')->where('id', $lead)->lockForUpdate()->first();
            $due = Carbon::parse($data['due_at'], 'Asia/Jakarta')->utc();
            DB::table('crm_follow_ups')->insert(['lead_id' => $lead, 'assigned_user_id' => $request->user()->id, 'due_at' => $due, 'type' => $data['type'], 'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            $this->crm->nextFollowUp($lead);
            $this->crm->activity($lead, 'followup_scheduled', $data['type'].' dijadwalkan '.$due->copy()->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB.', $request->user()->id);
        });

        return back()->with('success', 'Follow-up dijadwalkan.');
    }

    public function finishFollowUp(Request $request, int $lead, int $followup): RedirectResponse
    {
        $this->crm->leads($request, 'crm.followup.manage')->where('id', $lead)->first() ?? abort(404);
        $data = $request->validate(['status' => 'required|in:COMPLETED,CANCELLED', 'outcome' => 'required|string|max:255']);
        DB::transaction(function () use ($request, $lead, $followup, $data): void {
            DB::table('acquisition_leads')->where('id', $lead)->lockForUpdate()->first();
            $row = DB::table('crm_follow_ups')->where('id', $followup)->where('lead_id', $lead)->where('status', 'SCHEDULED')->first() ?? abort(404);
            DB::table('crm_follow_ups')->where('id', $row->id)->update($data + ['completed_at' => now(), 'updated_at' => now()]);
            $this->crm->nextFollowUp($lead);
            $this->crm->activity($lead, 'followup_completed', $data['status'].': '.$data['outcome'], $request->user()->id);
        });

        return back()->with('success', 'Follow-up diperbarui.');
    }

    public function followUps(Request $request): View
    {
        $rows = $this->crm->leads($request, 'crm.lead.read')->whereNotNull('next_followup_at')->orderBy('next_followup_at')->paginate(40);

        return view('conversation.followups', compact('rows'));
    }

    public function savedView(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'filters' => 'required|array:search,stage,owner,tag,overdue,source,from,to,channel,vacancy_id,country_id,campaign,unread,mode,preset,sort,per_page,utm_medium,assignment_state,conversion_state,followup_state,activity_from,activity_to']);
        DB::table('crm_saved_views')->insert(['user_id' => $request->user()->id, 'name' => $data['name'], 'filters' => json_encode($data['filters']), 'created_at' => now(), 'updated_at' => now()]);
        app(AccessPolicy::class)->audit($request->user(), 'crm.view_saved');

        return back()->with('success', 'Tampilan tersimpan.');
    }

    public function convert(Request $request, int $lead): RedirectResponse
    {
        $this->crm->leads($request, 'lead.convert_to_candidate')->where('id', $lead)->first() ?? abort(404);
        $data = $request->validate(['vacancy_id' => 'required|integer|exists:vacancies,id']);
        abort_unless(DB::table('vacancies')->whereIn('id', app(AccessPolicy::class)->vacancyIds($request->user(), 'lead.convert_to_candidate'))->where('id', $data['vacancy_id'])->exists(), 403);
        $application = app(ConversionService::class)->convert($request, $lead, $data['vacancy_id']);

        return to_route('applications.show', $application)->with('success', 'Lead dikonversi; workflow penempatan dilanjutkan melalui lamaran.');
    }

    public function settings(Request $request): View
    {
        return view('conversation.settings', ['metaResume' => $request->user()->hasPermission('WHATSAPP_CONNECTION_MANAGE') ? DB::table('meta_auth_sessions')->where('user_id', $request->user()->id)->where('expires_at', '>', now())->whereNotIn('status', ['FAILED', 'DISCONNECTED'])->latest('created_at')->value('id') : null, 'metaTeams' => app(MetaConnectController::class)->teams(), 'stages' => DB::table('crm_stages')->orderBy('display_order')->get(), 'channels' => $request->user()->hasPermission('WHATSAPP_CONNECTION_VIEW') ? DB::table('whatsapp_channels')->where('provider', 'META_CLOUD_API')->get(['id', 'name', 'waba_id', 'phone_number_id', 'display_phone_number', 'status', 'team_id', 'routing_method', 'strict_owner', 'prefer_existing_owner', 'is_active', 'last_webhook_at', 'business_hours', 'last_error', 'connection_method', 'verified_name', 'quality_rating', 'webhook_ready', 'last_health_check_at', 'inbound_test_at', 'outbound_test_at', 'connected_at']) : collect(), 'tags' => DB::table('crm_tags')->get(), 'quickReplies' => DB::table('crm_quick_replies')->get(), 'templates' => $request->user()->hasPermission('crm.whatsapp_template.manage') ? DB::table('crm_whatsapp_templates')->get() : collect(), 'deadEvents' => $request->user()->hasPermission('crm.whatsapp.manage') ? DB::table('crm_webhook_events')->where('status', 'DEAD')->limit(20)->get(['id', 'error', 'created_at']) : collect(), 'diagnostics' => $request->user()->hasPermission('crm.whatsapp.manage') ? DB::table('crm_webhook_events')->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status') : collect()]);
    }

    public function connectStart(Request $request): JsonResponse
    {
        abort_unless(config('conversation.app_id') && config('conversation.signup_config_id') && config('conversation.app_secret'), 422, 'Admin perlu mengatur Meta App ID, signup configuration ID, dan app secret di server.');
        $state = Str::random(48);
        $request->session()->put('crm_meta_signup', ['state' => $state, 'expires' => now()->addMinutes(10)->timestamp]);

        return response()->json(['state' => $state, 'app_id' => config('conversation.app_id'), 'config_id' => config('conversation.signup_config_id'), 'version' => config('conversation.graph_version')]);
    }

    public function connectCallback(Request $request): JsonResponse
    {
        $data = $request->validate(['state' => 'required|string|max:100', 'code' => 'required|string|max:4000', 'waba_id' => 'required|regex:/^[0-9]+$/|max:100', 'phone_number_id' => 'required|regex:/^[0-9]+$/|max:100']);
        $state = $request->session()->pull('crm_meta_signup');
        abort_unless($state && $state['expires'] >= now()->timestamp && hash_equals($state['state'], $data['state']), 419, 'Sesi koneksi berakhir. Ulangi onboarding.');
        try {
            $channel = app(WhatsAppCloud::class)->connect($data['code'], $data['waba_id'], $data['phone_number_id']);
        } catch (\Throwable) {
            return response()->json(['message' => 'Koneksi Meta gagal. Periksa akses akun bisnis, nomor, dan langganan webhook, lalu coba lagi.'], 422);
        }
        DB::table('whatsapp_channels')->updateOrInsert(['phone_number_id' => $data['phone_number_id']], $channel + ['created_at' => now()]);
        app(AccessPolicy::class)->audit($request->user(), 'crm.meta_connected', ['phone_number_id' => $data['phone_number_id']]);

        return response()->json(['connected' => true]);
    }

    public function channel(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => 'nullable|integer|exists:whatsapp_channels,id', 'name' => 'required|string|max:100', 'waba_id' => 'required|regex:/^[0-9]+$/|max:100', 'phone_number_id' => ['required', 'regex:/^[0-9]+$/', 'max:100', Rule::unique('whatsapp_channels')->ignore($request->input('id'))], 'display_phone_number' => 'required|string|max:30', 'access_token' => 'nullable|string|max:4000', 'team_id' => 'nullable|integer|min:1', 'routing_method' => 'required|in:ROUND_ROBIN,LEAST_ACTIVE,MANUAL', 'strict_owner' => 'required|boolean', 'prefer_existing_owner' => 'required|boolean', 'is_active' => 'required|boolean', 'hours_start' => 'nullable|date_format:H:i|required_with:hours_end', 'hours_end' => 'nullable|date_format:H:i|after:hours_start|required_with:hours_start']);
        $data['business_hours'] = ! empty($data['hours_start']) ? json_encode(['days' => [1, 2, 3, 4, 5], 'start' => $data['hours_start'], 'end' => $data['hours_end']]) : null;
        unset($data['hours_start'], $data['hours_end']);
        $id = $data['id'] ?? null;
        unset($data['id']);
        if (! empty($data['access_token'])) {
            $data['access_token'] = Crypt::encryptString($data['access_token']);
        } else {
            unset($data['access_token']);
            abort_unless($id, 422, 'Token diperlukan untuk channel baru.');
        }
        $data['updated_at'] = now();
        if ($id) {
            DB::table('whatsapp_channels')->where('id', $id)->where('provider', 'META_CLOUD_API')->first() ?? abort(404);
            DB::table('whatsapp_channels')->where('id', $id)->where('provider', 'META_CLOUD_API')->update($data);
        } else {
            $id = DB::table('whatsapp_channels')->insertGetId($data + ['created_at' => now()]);
        }
        app(AccessPolicy::class)->audit($request->user(), 'crm.channel_configured', ['channel_id' => $id]);

        return back()->with('success', 'Konfigurasi tersimpan. Token terenkripsi; uji koneksi dan webhook sebelum digunakan.');
    }

    public function testChannel(Request $request, int $channel): RedirectResponse
    {
        $row = DB::table('whatsapp_channels')->where('provider', 'META_CLOUD_API')->find($channel) ?? abort(404);
        try {
            app(WhatsAppCloud::class)->inspect($row);
        } catch (\Throwable) {
            return back()->withErrors(['channel' => 'Koneksi Meta gagal. Periksa token, izin, dan phone number ID.']);
        }
        app(AccessPolicy::class)->audit($request->user(), 'crm.channel_tested', ['channel_id' => $channel]);

        return back()->with('success', 'Koneksi Meta berhasil.');
    }

    public function registerChannel(Request $request, int $channel): RedirectResponse
    {
        $data = $request->validate(['pin' => 'required|digits:6']);
        $row = DB::table('whatsapp_channels')->where('provider', 'META_CLOUD_API')->find($channel) ?? abort(404);
        try {
            app(WhatsAppCloud::class)->register($row, $data['pin']);
        } catch (\Throwable) {
            return back()->withErrors(['channel' => 'Registrasi nomor ke Cloud API gagal. Periksa kepemilikan, verifikasi nomor, dan PIN.']);
        }
        app(AccessPolicy::class)->audit($request->user(), 'crm.channel_registered', ['channel_id' => $channel]);

        return back()->with('success', 'Nomor terdaftar pada WhatsApp Cloud API. Lanjutkan uji webhook.');
    }

    public function syncTemplates(Request $request, int $channel): RedirectResponse
    {
        $row = DB::table('whatsapp_channels')->where('provider', 'META_CLOUD_API')->find($channel) ?? abort(404);
        try {
            $count = app(WhatsAppCloud::class)->templates($row);
        } catch (\Throwable) {
            return back()->withErrors(['template' => 'Sinkronisasi Meta gagal. Periksa koneksi dan izin.']);
        }
        app(AccessPolicy::class)->audit($request->user(), 'crm.templates_synced', ['channel_id' => $channel]);

        return back()->with('success', $count.' template disinkronkan dari Meta.');
    }

    public function attachment(Request $request, int $conversation, int $message): BinaryFileResponse
    {
        $row = $this->crm->conversations($request)->where('id', $conversation)->first() ?? abort(404);
        $attachment = DB::table('crm_messages')->where('id', $message)->where('conversation_id', $conversation)->where('direction', 'INBOUND')->whereIn('message_type', ['image', 'document'])->first() ?? abort(404);
        abort_unless($attachment->provider_payload, 404);
        $media = json_decode(Crypt::decryptString($attachment->provider_payload), true);
        abort_unless(! empty($media['media_id']) || ! empty($media['local_path']), 404);
        try {
            if (! empty($media['local_path'])) {
                abort_unless(str_starts_with($media['local_path'], 'crm-inbound/') && ! str_contains($media['local_path'], '..') && Storage::disk('local')->exists($media['local_path']), 404);
                $download = ['data' => Storage::disk('local')->get($media['local_path']), 'mime' => $attachment->media_mime];
            } else {
                $download = app(WhatsAppCloud::class)->downloadMedia(DB::table('whatsapp_channels')->find($row->channel_id), $media['media_id']);
            }
        } catch (\Throwable) {
            abort(422, 'Lampiran tidak tersedia atau tidak didukung. Batas: JPG, PNG, PDF, 10 MB.');
        }
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$download['mime']];
        $temporary = tempnam(sys_get_temp_dir(), 'jm-crm-media-');
        file_put_contents($temporary, $download['data']);
        try {
            $file = new UploadedFile($temporary, 'attachment.'.$extension, $download['mime'], null, true);
            abort_unless($file->getMimeType() === $download['mime'], 422, 'Tipe file tidak cocok.');
            app(DocumentScanner::class)->inspect($file);
        } catch (\Throwable $exception) {
            unlink($temporary);
            throw $exception;
        }
        app(AccessPolicy::class)->audit($request->user(), 'crm.attachment_downloaded', ['message_id' => $message, 'lead_id' => $row->lead_id]);

        return response()->download($temporary, 'lampiran-'.$message.'.'.$extension, ['Content-Type' => $download['mime'], 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    public function claim(Request $request, int $conversation): RedirectResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:0']);
        DB::transaction(function () use ($request, $conversation, $data): void {
            $row = $this->crm->conversations($request)->where('id', $conversation)->lockForUpdate()->first() ?? abort(404);
            abort_if($row->assigned_user_id || $row->version !== (int) $data['version'], 409, 'Percakapan telah diambil CS lain. Muat ulang antrean.');
            abort_unless($request->user()->team_id && $row->team_id === $request->user()->team_id, 403);
            DB::table('crm_conversations')->where('id', $conversation)->update(['assigned_user_id' => $request->user()->id, 'version' => $row->version + 1]);
            DB::table('acquisition_leads')->where('id', $row->lead_id)->update(['assigned_cs_id' => $request->user()->id]);
            $this->crm->activity($row->lead_id, 'conversation_claimed', 'Percakapan diambil dari antrean oleh '.$request->user()->name, $request->user()->id);
        });

        return back()->with('success', 'Percakapan ditugaskan kepada Anda.');
    }

    public function createStage(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'color_token' => 'required|in:blue,green,amber,purple', 'sla_hours' => 'nullable|integer|min:1|max:8760']);
        DB::table('crm_stages')->insert($data + ['code' => 'CUSTOM_'.Str::upper(Str::random(10)), 'display_order' => 4, 'transitions' => json_encode(['LOST']), 'created_at' => now(), 'updated_at' => now()]);
        app(AccessPolicy::class)->audit($request->user(), 'crm.stage_created', ['name' => $data['name']]);

        return back()->with('success', 'Tahap ditambahkan. Atur transisi dari tahap lain untuk menggunakannya.');
    }

    public function saveStage(Request $request, int $stage): RedirectResponse
    {
        $row = DB::table('crm_stages')->find($stage) ?? abort(404);
        $data = $request->validate(['name' => 'required|string|max:80', 'display_order' => 'required|integer|min:0|max:100', 'color_token' => 'required|in:blue,green,amber,purple', 'sla_hours' => 'nullable|integer|min:1|max:8760', 'transitions' => 'nullable|array|max:50', 'transitions.*' => ['required', Rule::in(array_diff(array_keys(config('conversation.stages')), ['CONVERTED', $row->code]))]]);
        $data['transitions'] = json_encode($row->code === 'CONVERTED' ? [] : ($data['transitions'] ?? []));
        DB::table('crm_stages')->where('id', $stage)->update($data + ['updated_at' => now()]);
        app(AccessPolicy::class)->audit($request->user(), 'crm.stage_configured', ['code' => $row->code]);

        return back()->with('success', 'Konfigurasi tahap CRM disimpan. Tahap Dikonversi hanya dapat dicapai melalui konversi Candidate/Lamaran.');
    }

    public function availability(Request $request): RedirectResponse
    {
        $data = $request->validate(['availability' => 'required|in:ONLINE,BUSY,AWAY,OFFLINE', 'conversation_cap' => 'required|integer|min:1|max:200']);
        DB::table('crm_agent_profiles')->updateOrInsert(['user_id' => $request->user()->id], $data + ['updated_at' => now(), 'created_at' => now()]);
        app(AccessPolicy::class)->audit($request->user(), 'crm.availability_changed', $data);

        return back()->with('success', 'Ketersediaan CS diperbarui.');
    }

    public function archive(Request $request, int $lead): RedirectResponse
    {
        $this->crm->leads($request, 'crm.lead.edit')->where('id', $lead)->first() ?? abort(404);
        $data = $request->validate(['archived' => 'required|boolean', 'reason' => 'required|string|max:255']);
        DB::transaction(function () use ($request, $lead, $data): void {
            DB::table('acquisition_leads')->where('id', $lead)->update(['archived_at' => $data['archived'] ? now() : null, 'crm_version' => DB::raw('crm_version + 1')]);
            $this->crm->activity($lead, $data['archived'] ? 'archived' : 'reactivated', $data['reason'], $request->user()->id);
        });

        return back()->with('success', 'Status arsip diperbarui. Riwayat lead tetap tersedia.');
    }

    public function retryMessage(Request $request, int $conversation, int $message): RedirectResponse
    {
        DB::transaction(function () use ($request, $conversation, $message): void {
            $row = $this->crm->conversations($request, 'crm.conversation.reply')->where('id', $conversation)->lockForUpdate()->first() ?? abort(404);
            abort_unless($row->assigned_user_id === $request->user()->id, 409, 'Hanya owner dapat mencoba ulang.');
            $pending = DB::table('crm_messages')->where('id', $message)->where('conversation_id', $conversation)->where('direction', 'OUTBOUND')->where('status', 'FAILED')->lockForUpdate()->first() ?? abort(422, 'Hanya pesan gagal yang dapat dicoba ulang. Pesan dengan pengiriman belum pasti harus diperiksa terlebih dahulu.');
            abort_if($pending->attempts >= 5, 422, 'Batas percobaan tercapai. Hubungi administrator.');
            DB::table('crm_messages')->where('id', $message)->update(['status' => 'QUEUED', 'external_message_id' => null, 'status_at' => null, 'sent_by_user_id' => $request->user()->id, 'error_code' => null, 'updated_at' => now()]);
            $this->crm->activity($row->lead_id, 'message_retry', 'Percobaan ulang pesan #'.$message, $request->user()->id);
        });

        return back()->with('success', 'Pesan dimasukkan kembali ke antrean. Window layanan dan owner diperiksa ulang sebelum pengiriman.');
    }

    public function retryEvent(Request $request, int $event): RedirectResponse
    {
        $changed = DB::table('crm_webhook_events')->where('id', $event)->where('status', 'DEAD')->whereNotNull('payload')->update(['status' => 'RETRY', 'attempts' => 0, 'error' => null]);
        abort_unless($changed, 422, 'Event tidak tersedia untuk diproses ulang.');
        app(AccessPolicy::class)->audit($request->user(), 'crm.webhook_retry', ['event_id' => $event]);

        return back()->with('success', 'Event dijadwalkan untuk diproses ulang.');
    }

    public function saveTag(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:60|unique:crm_tags,name', 'category' => 'required|in:PROGRAM,COUNTRY,ENGAGEMENT', 'color_token' => 'required|in:blue,green,amber,purple']);
        abort_if(preg_match('/(hiv|hepatitis|hamil|diagnos|penyakit|medis|mcu|utang|hutang|gaji|insiden|incident|saldo)/iu', $data['name']), 422, 'Tag CRM hanya untuk program, negara, dan interaksi. Gunakan modul terlindungi untuk data sensitif.');
        DB::table('crm_tags')->insert($data + ['created_at' => now(), 'updated_at' => now()]);
        app(AccessPolicy::class)->audit($request->user(), 'crm.tag_created');

        return back()->with('success', 'Tag disimpan.');
    }

    public function saveQuickReply(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'body' => 'required|string|max:4096']);
        DB::table('crm_quick_replies')->insert($data + ['created_at' => now(), 'updated_at' => now()]);
        app(AccessPolicy::class)->audit($request->user(), 'crm.quick_reply_created');

        return back()->with('success', 'Balasan cepat disimpan. Ini bukan template approved Meta.');
    }
}
