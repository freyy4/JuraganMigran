<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveBlockerRequest;
use App\Models\Application;
use App\Models\ApplicationBlocker;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\CrmWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BlockerController extends Controller
{
    private function relations(): array
    {
        return ['application.candidate.person' => fn ($query) => $query->withTrashed(), 'application.vacancy', 'stage', 'owner', 'resolver'];
    }

    public function index(Request $request): View|JsonResponse
    {
        $workspace = app(CrmWorkspace::class);
        $filters = $workspace->filters($request, ['category' => 'nullable|string|max:255', 'priority' => 'nullable|string|max:255', 'status' => 'nullable|string|max:255', 'owner_id' => 'nullable|integer|exists:users,id', 'stage_id' => 'nullable|integer|exists:pipeline_stages,id']);
        $query = ApplicationBlocker::with($this->relations());
        $workspace->blockerFilters($query, $filters);
        $summary = ['Kendala Baru' => (clone $query)->where('status', 'open')->count(), 'Kritikal' => (clone $query)->whereNotIn('status', ApplicationBlocker::RESOLVED)->whereIn('priority', ['critical', 'urgent', 'high'])->count(), 'Menunggu Solusi' => (clone $query)->whereNotIn('status', ApplicationBlocker::RESOLVED)->count(), 'Selesai' => (clone $query)->whereIn('status', ApplicationBlocker::RESOLVED)->count()];
        if (in_array($filters['sort'] ?? '', ['newest', 'oldest'], true)) {
            $query->orderBy('created_at', $filters['sort'] === 'oldest' ? 'asc' : 'desc');
        } else {
            $query->orderByRaw('due_at IS NULL')->orderBy('due_at');
        }
        $rows = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();

        return $workspace->response($request, 'blockers.index', ['blockers' => $rows, 'categories' => array_values(array_unique([...CrmWorkspace::BLOCKER_CATEGORIES, ...ApplicationBlocker::categories()])), 'priorities' => ApplicationBlocker::priorities(), 'statuses' => ApplicationBlocker::statuses(), 'stages' => PipelineStage::orderBy('sequence')->get(), 'users' => User::orderBy('name')->get()], $rows, $summary, $filters, fn ($blocker): array => ['id' => $blocker->id, 'code' => $blocker->code, 'candidate' => $blocker->application?->candidate?->person?->full_name, 'category' => $blocker->category, 'priority' => $blocker->priority, 'status' => $blocker->status, 'due' => $blocker->due_at, 'nextAction' => $blocker->next_action]);
    }

    public function insights(Request $request): JsonResponse
    {
        return response()->json(app(CrmWorkspace::class)->blockerInsights($request));
    }

    private function form(ApplicationBlocker $blocker): View
    {
        $applications = Application::withTrashed()->where(function ($query) use ($blocker): void {
            $query->whereNull('deleted_at');
            if ($blocker->exists) {
                $query->orWhere('id', $blocker->application_id);
            }
        })->with(['candidate.person' => fn ($query) => $query->withTrashed(), 'vacancy'])->orderByDesc('id')->get();

        $categoryEntries = DB::table('master_reference_entries')->where('resource', 'kategori-kendala')->get(['code', 'is_active']);
        $availableCategories = collect([...CrmWorkspace::BLOCKER_CATEGORIES, ...ApplicationBlocker::categories(), ...$categoryEntries->where('is_active', true)->pluck('code')->all()])->unique()->reject(fn ($value) => $value !== $blocker->category && $categoryEntries->where('is_active', false)->contains('code', $value))->values()->all();

        return view('blockers.form', ['blocker' => $blocker, 'applications' => $applications, 'stages' => PipelineStage::orderBy('sequence')->orderBy('id')->get(), 'users' => User::orderBy('name')->get(), 'categories' => $availableCategories, 'priorities' => ApplicationBlocker::priorities(), 'statuses' => ApplicationBlocker::statuses()]);
    }

    public function create(): View
    {
        return $this->form(new ApplicationBlocker(['priority' => 'normal', 'status' => 'open']));
    }

    public function edit(ApplicationBlocker $blocker): View
    {
        return $this->form($blocker);
    }

    public function show(ApplicationBlocker $blocker): View
    {
        return view('blockers.show', ['blocker' => $blocker->load($this->relations())]);
    }

    private function data(SaveBlockerRequest $request, ?ApplicationBlocker $blocker = null): array
    {
        $data = $request->validated();
        if ((int) ($data['owner_id'] ?? 0) !== (int) ($blocker?->owner_id ?? 0)) {
            app(CrmWorkspace::class)->authorizeOwner($request, isset($data['owner_id']) ? (int) $data['owner_id'] : null);
        }
        $data['due_at'] = empty($data['due_at']) ? null : Carbon::createFromFormat('Y-m-d\TH:i', $data['due_at'], ApplicationBlocker::TIMEZONE)->setTimezone(config('app.timezone'))->startOfMinute();
        $resolved = in_array($data['status'], ApplicationBlocker::RESOLVED, true);
        $data['resolved_at'] = $resolved ? ($blocker?->resolved_at ?? now()) : null;
        $data['resolved_by'] = $resolved ? ($blocker?->resolved_by ?? $request->user()->id) : null;

        return $data;
    }

    public function store(SaveBlockerRequest $request): RedirectResponse
    {
        $blocker = ApplicationBlocker::create($this->data($request));

        return to_route('blockers.show', $blocker)->with('success', 'Kendala berhasil ditambahkan.');
    }

    public function update(SaveBlockerRequest $request, ApplicationBlocker $blocker): RedirectResponse
    {
        $blocker->update($this->data($request, $blocker));

        return to_route('blockers.show', $blocker)->with('success', 'Kendala berhasil diperbarui.');
    }

    public function resolve(Request $request, ApplicationBlocker $blocker): RedirectResponse
    {
        ApplicationBlocker::whereKey($blocker->id)->whereNotIn('status', ApplicationBlocker::RESOLVED)->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $request->user()->id]);

        return to_route('blockers.show', $blocker)->with('success', 'Kendala berhasil ditandai selesai.');
    }
}
