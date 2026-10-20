<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequirementSet;
use App\Models\DocumentType;
use App\Models\PipelineStage;
use App\Services\ScopedTemplateResolver;
use App\Services\VisualConditionCompiler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DocumentRequirementController extends Controller
{
    public function references(Request $request): JsonResponse
    {
        $data = $request->validate(['scope' => ['required', Rule::in(array_keys(ScopedTemplateResolver::SCOPES))], 'q' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'array', 'max:100'], 'selected.*' => ['integer']]);

        return response()->json(['items' => app(ScopedTemplateResolver::class)->references($data['scope'], trim($data['q'] ?? ''), null, $data['selected'] ?? [])->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->values()]);
    }

    public function index(Request $request): View
    {
        $request->validate(['search' => ['nullable', 'string', 'max:255'], 'scope_type' => ['nullable', Rule::in(array_keys(ScopedTemplateResolver::SCOPES))], 'selected' => ['nullable', 'integer', 'exists:document_requirement_sets,id']]);

        $set = $request->filled('selected') ? DocumentRequirementSet::findOrFail($request->integer('selected')) : null;

        return view('document-requirements.index', ['set' => $request->filled('selected') ? DocumentRequirementSet::findOrFail($request->integer('selected')) : null, 'types' => DocumentType::where('is_active', true)->orderBy('name')->limit(100)->get(), 'stages' => PipelineStage::orderBy('sequence')->get(), 'scopeOptions' => app(ScopedTemplateResolver::class)->options($set?->scope_type, $set?->scope_id), 'sets' => DocumentRequirementSet::when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))->when($request->filled('scope_type'), fn ($q) => $q->where('scope_type', $request->string('scope_type')))->withCount('versions')->latest('id')->paginate(25)->withQueryString()]);
    }

    public function show(?DocumentRequirementSet $set = null): View
    {
        return view('document-requirements.form', ['set' => $set ?? new DocumentRequirementSet(['scope_type' => 'GLOBAL', 'draft_schema' => ['rules' => []]]), 'types' => DocumentType::where('is_active', true)->orderBy('name')->get(), 'stages' => PipelineStage::orderBy('sequence')->get(), 'scopeOptions' => app(ScopedTemplateResolver::class)->options($set?->scope_type, $set?->scope_id)]);
    }

    public function save(Request $request, ?DocumentRequirementSet $set = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'scope_type' => ['required', Rule::in(array_keys(ScopedTemplateResolver::SCOPES))], 'scope_id' => ['nullable', 'integer'], 'intent' => ['required', Rule::in(['draft', 'review', 'archived'])], 'rules' => ['required', 'array', 'min:1', 'max:100'], 'rules.*' => ['array:document_type_id,stage_id,required_level,must_verify_before_exit,original_required,expiry_policy,minimum_validity_days,condition_json,condition,sort_order'], 'rules.*.document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)], 'rules.*.stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'], 'rules.*.required_level' => ['required', Rule::in(['REQUIRED', 'OPTIONAL', 'CONDITIONAL'])], 'rules.*.must_verify_before_exit' => ['required', 'boolean'], 'rules.*.original_required' => ['required', 'boolean'], 'rules.*.expiry_policy' => ['required', Rule::in(['VALID', 'NONE'])], 'rules.*.minimum_validity_days' => ['required', 'integer', 'min:0', 'max:3650'], 'rules.*.condition_json' => ['nullable', 'json'], 'rules.*.condition' => ['nullable', 'array'], 'rules.*.sort_order' => ['required', 'integer', 'min:0', 'max:10000']]);
        app(ScopedTemplateResolver::class)->validateScope($data, $set?->scope_type, $set?->scope_id);
        if ($set?->versions()->exists() && ($set->scope_type !== $data['scope_type'] || $set->scope_id != ($data['scope_type'] === 'GLOBAL' ? null : $data['scope_id']))) {
            throw ValidationException::withMessages(['scope_type' => 'Cakupan set terbit tidak dapat diubah. Buat set lain.']);
        }
        foreach ($data['rules'] as $i => &$row) {
            $condition = $row['condition'] ?? (! empty($row['condition_json']) ? json_decode($row['condition_json'], true) : null);
            if ($condition !== null && ! is_array($condition)) {
                throw ValidationException::withMessages(['conditions' => 'Aturan perlu diperiksa atau dimigrasikan. Gunakan kondisi yang didukung.']);
            }
            if ($row['required_level'] !== 'CONDITIONAL' && isset($condition['conditions']) && collect($condition['conditions'])->every(fn ($clause) => trim((string) ($clause['value'] ?? implode(',', $clause['values'] ?? []))) === '' && ($clause['operator'] ?? '') !== 'exists')) {
                $condition = null;
            }
            $condition = app(VisualConditionCompiler::class)->compile($condition);
            if ($row['required_level'] === 'CONDITIONAL' && ! $condition) {
                throw ValidationException::withMessages(['rules.'.$i.'.condition' => 'Tambahkan kondisi untuk dokumen bersyarat.']);
            }
            $row['condition_rule'] = $condition;
            unset($row['condition_json'], $row['condition']);
        }
        unset($row);
        $set ??= new DocumentRequirementSet(['created_by' => $request->user()->id]);
        $set->fill(['name' => $data['name'], 'scope_type' => $data['scope_type'], 'scope_id' => $data['scope_type'] === 'GLOBAL' ? null : $data['scope_id'], 'status' => $data['intent'], 'draft_schema' => ['rules' => array_values($data['rules'])]])->save();

        return to_route('document-requirements.show', $set)->with('success', 'Draf kebutuhan dokumen disimpan. Jenis dokumen master tidak diubah.');
    }

    public function publish(Request $request, DocumentRequirementSet $set): RedirectResponse
    {
        $data = $request->validate(['effective_from' => ['nullable', 'date_format:Y-m-d'], 'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from']]);
        DB::transaction(function () use ($request, $set, $data): void {
            $set = DocumentRequirementSet::whereKey($set->id)->lockForUpdate()->firstOrFail();
            if ($set->status !== 'review') {
                throw ValidationException::withMessages(['set' => 'Tinjau draf sebelum penerbitan.']);
            }
            $rules = $set->draft_schema['rules'];
            foreach ($rules as $row) {
                if (! DocumentType::whereKey($row['document_type_id'])->where('is_active', true)->exists() || (! empty($row['stage_id']) && ! PipelineStage::whereKey($row['stage_id'])->exists())) {
                    throw ValidationException::withMessages(['rules' => 'Jenis dokumen atau tahap telah berubah. Periksa draf.']);
                }
            }
            $version = $set->versions()->create([...$data, 'version' => ($set->versions()->max('version') ?? 0) + 1, 'snapshot' => ['rules' => $rules], 'published_by' => $request->user()->id, 'published_at' => now()]);
            foreach ($rules as $row) {
                DB::table('document_requirement_rules')->insert([...$row, 'condition_rule' => $row['condition_rule'] ? json_encode($row['condition_rule']) : null, 'stage_id' => $row['stage_id'] ?: null, 'document_requirement_version_id' => $version->id]);
            }
            $set->update(['status' => 'published']);
        });

        return back()->with('success', 'Versi kebutuhan dokumen diterbitkan. Kebutuhan tahap berikutnya tidak dihitung kurang pada tahap awal.');
    }
}
