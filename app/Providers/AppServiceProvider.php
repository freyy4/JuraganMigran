<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\ApplicationBlocker;
use App\Models\ApplicationQualificationResult;
use App\Models\ApplicationStageHistory;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\FlightDetail;
use App\Models\HealthInterview;
use App\Models\Interview;
use App\Models\MedicalEvent;
use App\Models\ReadinessChecklist;
use App\Models\Task;
use App\Models\TrainingEnrollment;
use App\Models\Vacancy;
use App\Services\AccessPolicy;
use App\Services\ProcessWorkflow;
use App\Services\TrackingHub;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Vacancy::class => ['posisi-pekerjaan', 'position_name'], Task::class => ['jenis-tugas', 'task_type'], ApplicationBlocker::class => ['kategori-kendala', 'category']] as $model => [$resource, $field]) {
            $model::saving(function (Model $record) use ($resource, $field): void {
                $value = $record->{$field};
                if (! $value || ! $record->isDirty($field) || ! Schema::hasTable('master_reference_entries')) {
                    return;
                }
                $code = strlen($value) <= 100 ? $value : hash('sha256', $value);
                $entry = DB::table('master_reference_entries')->where('resource', $resource)->where($resource === 'posisi-pekerjaan' ? 'name' : 'code', $resource === 'posisi-pekerjaan' ? $value : $code)->first();
                if ($entry && ! $entry->is_active) {
                    throw ValidationException::withMessages([$field => 'Pilihan ini sudah nonaktif. Pilih acuan aktif.']);
                }
                if (! $entry) {
                    DB::table('master_reference_entries')->insertOrIgnore(['resource' => $resource, 'code' => $code, 'name' => $value, 'created_at' => now(), 'updated_at' => now()]);
                }
                if (in_array($resource, ['jenis-tugas', 'kategori-kendala'], true) && $entry) {
                    $record->priority ??= $entry->default_priority;
                    if (! $record->due_at && $entry->sla_hours !== null) {
                        $record->due_at = now()->addHours($entry->sla_hours);
                    }
                }
            });
        }
        Application::creating(function (Application $application): void {
            if (! Schema::hasColumn('applications', 'workflow_version_id')) {
                return;
            }
            $version = DB::table('vacancies')->where('id', $application->vacancy_id)->value('workflow_version_id');
            $application->workflow_version_id = $version;
            if ($version) {
                $stages = json_decode(DB::table('process_workflow_versions')->where('id', $version)->value('snapshot'), true)['stages'];
                if (! $application->current_stage_id) {
                    $application->current_stage_id = $stages[0]['id'];
                } elseif (! collect($stages)->contains('id', $application->current_stage_id)) {
                    throw ValidationException::withMessages(['current_stage_id' => 'Tahap awal tidak ada dalam versi alur lowongan ini.']);
                }
            }
        });
        Application::created(function (Application $application): void {
            if ($application->workflow_version_id && $application->current_stage_id) {
                $stage = app(ProcessWorkflow::class)->stage($application, $application->current_stage_id);
                $application->stageHistories()->create(['from_stage_id' => null, 'to_stage_id' => $application->current_stage_id, 'entered_at' => now(), 'changed_by' => auth()->id(), 'reason' => 'Tahap awal sesuai versi alur lowongan.', 'sla_hours_snapshot' => $stage['sla_hours'] ?? null]);
            }
        });
        Application::updated(function (Application $application): void {
            if ($application->wasChanged('status') && in_array($application->status, ['ready', 'ready_for_departure', 'departure_ready'], true)) {
                app(TrackingHub::class)->milestone($application->id, 'FLIGHT_READY', 'flight-ready-'.$application->id);
            }
        });
        TrainingEnrollment::saved(function (TrainingEnrollment $enrollment): void {
            if ($enrollment->status === 'ongoing' && ($enrollment->wasChanged('status') || $enrollment->wasRecentlyCreated)) {
                app(TrackingHub::class)->milestone($enrollment->application_id, 'LPK_JOINED', 'training-joined-'.$enrollment->id);
            }
        });
        Interview::saved(function (Interview $interview): void {
            if ($interview->status === 'completed' && ($interview->wasChanged('status') || $interview->wasRecentlyCreated)) {
                app(TrackingHub::class)->milestone($interview->application_id, 'INTERVIEW_COMPLETED', 'interview-completed-'.$interview->id);
            }
        });
        MedicalEvent::created(function (MedicalEvent $medical): void {
            if ($medical->status === 'scheduled') {
                app(TrackingHub::class)->milestone($medical->application_id, 'MCU_SCHEDULED', 'mcu-scheduled-'.$medical->id);
            }
        });
        foreach ([Application::class, Candidate::class, CandidateDocument::class, Task::class, ApplicationBlocker::class, Interview::class, MedicalEvent::class, HealthInterview::class, TrainingEnrollment::class, FlightDetail::class, ReadinessChecklist::class, ApplicationQualificationResult::class, ApplicationStageHistory::class] as $model) {
            $model::addGlobalScope('access', function (Builder $query): void {
                $user = auth()->user();
                if (! $user) {
                    return;
                }
                $table = $query->getModel()->getTable();
                if (request()->routeIs('portal.*')) {
                    $candidate = DB::table('portal_accounts')->where('user_id', $user->id)->value('candidate_id');
                    if ($table === 'candidates') {
                        $query->where('candidates.id', $candidate);
                    } elseif ($table === 'applications' || $table === 'candidate_documents') {
                        $query->where($table.'.candidate_id', $candidate);
                    } else {
                        $query->whereIn($table.'.application_id', DB::table('applications')->where('candidate_id', $candidate)->select('id'));
                    }

                    return;
                }
                if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                    return;
                }
                $policy = app(AccessPolicy::class);
                $permission = request()->attributes->get('access_permission') ?? $policy->routePermission(request()->route()?->getName() ?? '') ?? 'candidate.read.basic';
                $aggregate = request()->routeIs('reports.pipeline.*', 'reports.cs.*', 'reports.partners.*', 'reports.vacancies.*', 'cockpit.*', 'dashboard');
                if (collect($policy->policies($user, $permission))->contains(fn ($grant) => $grant['scope'] === 'OPERATIONAL_ALL')) {
                    return;
                }
                if ($table === 'applications') {
                    $policy->scopeApplications($query, $user, $permission, 'applications', $aggregate);
                } elseif ($table === 'candidates') {
                    if (! collect($policy->policies($user, $permission))->contains(fn ($grant) => $grant['scope'] === 'OPERATIONAL_ALL')) {
                        $query->whereIn('candidates.id', $policy->candidateIds($user, $permission));
                    }
                } elseif ($table === 'candidate_documents') {
                    $query->whereIn($table.'.candidate_id', $policy->candidateIds($user, $permission))->where(fn ($documents) => $documents->whereNull($table.'.application_id')->orWhereIn($table.'.application_id', $policy->applicationIds($user, $permission)));
                } else {
                    $query->whereIn($table.'.application_id', $policy->applicationIds($user, $permission, $aggregate));
                }
            });
        }
    }
}
