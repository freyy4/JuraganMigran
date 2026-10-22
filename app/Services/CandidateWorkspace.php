<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CandidateWorkspace
{
    public const AGE_OPTIONS = ['18-25' => '18–25 tahun', '26-35' => '26–35 tahun', '36-45' => '36–45 tahun', '46+' => '46 tahun ke atas'];

    public const PASSPORT_OPTIONS = ['NONE' => 'Belum ada paspor', 'VALID' => 'Paspor berlaku', 'EXPIRED' => 'Paspor kedaluwarsa', 'IN_PROGRESS' => 'Dalam pengurusan'];

    public const READINESS_OPTIONS = ['READY' => 'Siap berangkat', 'PREPARING' => 'Dalam persiapan', 'UNDECIDED' => 'Belum ditentukan'];

    public function allowed(Request $request, Candidate $candidate, string $permission): bool
    {
        $cached = $request->attributes->get('crm_field_permissions', []);

        return array_key_exists($permission, $cached) ? in_array($candidate->id, $cached[$permission], true) : app(AccessPolicy::class)->canCandidate($request->user(), $candidate->id, $permission);
    }

    public function details(Request $request, Candidate $candidate): array
    {
        $candidate->load(['person', 'contacts']);
        $permissions = collect(['identity', 'contact', 'location', 'timeline'])->mapWithKeys(fn (string $field): array => [$field => $this->allowed($request, $candidate, 'candidate.read.'.$field)])->all();
        $canEdit = $this->allowed($request, $candidate, 'candidate.update.profile') && $permissions['identity'] && $permissions['contact'] && $permissions['location'];
        $canClose = $this->allowed($request, $candidate, 'candidate.close');
        $applications = Application::where('candidate_id', $candidate->id)->with(['vacancy.jobOrder.country', 'currentStage', 'assignedCs'])->orderByDesc('applied_at')->orderByDesc('id')->get();
        $current = $applications->first(fn (Application $application): bool => ! in_array($application->status, ['closed', 'cancelled', 'rejected', 'withdrawn', 'completed'], true)) ?? $applications->first();
        $leadQuery = app(AcquisitionControllerBridge::class);

        return [];
    }
}
