<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveQualificationRequest;
use App\Models\Application;
use App\Models\ApplicationQualificationResult;
use App\Models\Vacancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class QualificationController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']], ['string' => 'Pencarian harus berupa teks.', 'max' => 'Pencarian maksimal :max karakter.']);
        $query = Application::with(['candidate.person' => fn ($query) => $query->withTrashed(), 'vacancy.currentRequirements', 'qualificationResults']);
        if ($search = trim($data['search'] ?? '')) {
            $query->where(function ($query) use ($search): void {
                $query->where('application_code', 'like', '%'.$search.'%')->orWhereHas('candidate', fn ($candidate) => $candidate->where('candidate_code', 'like', '%'.$search.'%'))->orWhereHas('candidate.person', fn ($person) => $person->withTrashed()->where('full_name', 'like', '%'.$search.'%'));
            });
        }

        return view('qualifications.index', ['applications' => $query->orderByDesc('id')->paginate(15)->withQueryString()]);
    }

    public function show(Application $application): View
    {
        $application->load(['candidate.person' => fn ($query) => $query->withTrashed(), 'vacancy.jobOrder.company']);
        $requirements = $application->vacancy->currentRequirements()->with('definition')->orderBy('requirement_definition_id')->get();
        $results = $application->qualificationResults()->with('checker')->whereIn('vacancy_requirement_id', $requirements->pluck('id'))->get()->keyBy('vacancy_requirement_id');
        $checked = $results->filter(fn ($result) => isset(ApplicationQualificationResult::RESULTS[$result->result]) && $result->result !== 'not_checked')->count();

        return view('qualifications.show', ['application' => $application, 'requirements' => $requirements, 'results' => $results, 'checked' => $checked, 'progress' => $requirements->count() ? (int) round($checked / $requirements->count() * 100) : 0]);
    }

    public function update(SaveQualificationRequest $request, Application $application): RedirectResponse
    {
        $rows = $request->validated('results');
        DB::transaction(function () use ($application, $rows, $request): void {
            $locked = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $vacancy = Vacancy::withTrashed()->whereKey($locked->vacancy_id)->lockForUpdate()->firstOrFail();
            $expected = $vacancy->currentRequirements()->pluck('id')->sort()->values()->all();
            $submitted = collect($rows)->pluck('vacancy_requirement_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($expected !== $submitted) {
                throw ValidationException::withMessages(['results' => 'Persyaratan telah berubah. Muat ulang halaman dan periksa versi terbaru.']);
            }
            foreach ($rows as $row) {
                $value = $row['value'] ?? null;
                $value = is_bool($value) ? ($value ? '1' : '0') : ($value === null ? null : (string) $value);
                $record = $locked->qualificationResults()->firstOrNew(['vacancy_requirement_id' => $row['vacancy_requirement_id']]);
                $record->fill(['value' => $value, 'result' => $row['result'], 'reason' => $row['reason'] ?? null]);
                if (! $record->exists || $record->isDirty()) {
                    $record->checked_at = $row['result'] === 'not_checked' ? null : now();
                    $record->checked_by = $row['result'] === 'not_checked' ? null : $request->user()->id;
                    $record->save();
                }
            }
        });

        return to_route('qualifications.show', $application)->with('success', 'Hasil kualifikasi berhasil disimpan.');
    }
}
