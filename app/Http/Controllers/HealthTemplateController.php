<?php

namespace App\Http\Controllers;

use App\Models\HealthScreeningTemplate;
use App\Services\HealthScreeningEngine;
use App\Services\ScopedTemplateResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HealthTemplateController extends Controller
{
    public function references(Request $request): JsonResponse
    {
        $data = $request->validate(['scope' => ['required', Rule::in(array_keys(ScopedTemplateResolver::SCOPES))], 'q' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'array', 'max:100'], 'selected.*' => ['integer']]);

        return response()->json(['items' => app(ScopedTemplateResolver::class)->references($data['scope'], trim($data['q'] ?? ''), null, $data['selected'] ?? [])->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->values()]);
    }

    public function index(Request $request): View
    {
        $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(['draft', 'review', 'published', 'archived'])], 'scope_type' => ['nullable', Rule::in(array_keys(ScopedTemplateResolver::SCOPES))], 'question_category' => ['nullable', 'string', 'max:255']]);

        return view('health-templates.index', ['metrics' => ['Template Aktif' => HealthScreeningTemplate::where('status', 'published')->count(), 'Draft' => HealthScreeningTemplate::where('status', 'draft')->count(), 'Versi Terbit' => DB::table('health_template_versions')->count(), 'Pertanyaan Global' => DB::table('health_questions')->where('is_active', true)->whereNull('applicability_rule')->where('restricted', false)->count()], 'templates' => HealthScreeningTemplate::when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('scope_type'), fn ($query) => $query->where('scope_type', $request->string('scope_type')))->withCount('versions')->latest('id')->paginate(25)->withQueryString(), 'categories' => DB::table('health_questions')->whereNotNull('code')->select('category')->selectRaw('COUNT(*) as total')->groupBy('category')->orderBy('category')->get(), 'questions' => DB::table('health_questions')->whereNotNull('code')->where('category', $request->input('question_category', '__none__'))->orderBy('sequence')->paginate(25, ['*'], 'questions_page')->withQueryString()]);
    }

    public function show(?HealthScreeningTemplate $template = null): View
    {
        return view('health-templates.form', ['template' => $template ?? new HealthScreeningTemplate(['scope_type' => 'GLOBAL', 'draft_schema' => ['question_ids' => []]]), 'questions' => DB::table('health_questions')->where('is_active', true)->where('answer_type', '!=', 'LEGACY_TEXT')->orderBy('category')->orderBy('sequence')->get(), 'scopeOptions' => app(ScopedTemplateResolver::class)->options($template?->scope_type, $template?->scope_id)]);
    }

    public function save(Request $request, ?HealthScreeningTemplate $template = null): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'scope_type' => ['required', Rule::in(array_keys(ScopedTemplateResolver::SCOPES))], 'scope_id' => ['nullable', 'integer'], 'question_ids' => ['required', 'array', 'min:1'], 'question_ids.*' => ['required', 'integer', 'distinct', Rule::exists('health_questions', 'id')->where('is_active', true)], 'intent' => ['required', Rule::in(['draft', 'review', 'archived'])]]);
        app(ScopedTemplateResolver::class)->validateScope($data, $template?->scope_type, $template?->scope_id);
        if ($template?->versions()->exists() && ($template->scope_type !== $data['scope_type'] || $template->scope_id != ($data['scope_type'] === 'GLOBAL' ? null : $data['scope_id']))) {
            throw ValidationException::withMessages(['scope_type' => 'Cakupan template terbit tidak dapat diubah. Buat template lain.']);
        }
        if (DB::table('health_questions')->whereIn('id', $data['question_ids'])->where('answer_type', 'LEGACY_TEXT')->exists()) {
            throw ValidationException::withMessages(['question_ids' => 'Pertanyaan generik lama tidak dapat masuk template terstruktur.']);
        }
        $template ??= new HealthScreeningTemplate(['created_by' => $request->user()->id]);
        $template->fill(['name' => $data['name'], 'scope_type' => $data['scope_type'], 'scope_id' => $data['scope_type'] === 'GLOBAL' ? null : $data['scope_id'], 'status' => $data['intent'], 'draft_schema' => ['question_ids' => $data['question_ids']]])->save();

        return to_route('health-templates.show', $template)->with('success', 'Draf template disimpan. Versi terbit lama tetap berlaku sampai versi baru diterbitkan.');
    }

    public function publish(Request $request, HealthScreeningTemplate $template): RedirectResponse
    {
        $data = $request->validate(['policy_reference' => ['nullable', 'string', 'max:2000'], 'effective_from' => ['nullable', 'date_format:Y-m-d'], 'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from']]);
        DB::transaction(function () use ($request, $template, $data): void {
            $template = HealthScreeningTemplate::whereKey($template->id)->lockForUpdate()->firstOrFail();
            if ($template->status !== 'review') {
                throw ValidationException::withMessages(['template' => 'Ajukan template untuk ditinjau sebelum penerbitan.']);
            }
            $questions = DB::table('health_questions')->whereIn('id', $template->draft_schema['question_ids'])->where('is_active', true)->orderBy('sequence')->orderBy('id')->get();
            if ($questions->count() !== count($template->draft_schema['question_ids'])) {
                throw ValidationException::withMessages(['template' => 'Pertanyaan berubah/tidak aktif. Tinjau kembali draf.']);
            }
            foreach ($questions as $question) {
                $condition = $question->applicability_rule ? json_decode($question->applicability_rule, true) : [];
                if (isset($condition['question_code'])) {
                    $parent = $questions->firstWhere('code', $condition['question_code']);
                    if (! $parent || $parent->id === $question->id || isset(json_decode($parent->applicability_rule ?? 'null', true)['question_code'])) {
                        throw ValidationException::withMessages(['template' => 'Pemicu pertanyaan bersyarat harus mengacu ke pertanyaan utama dalam template yang sama.']);
                    }
                }
            }
            $restricted = $questions->contains('restricted', true);
            if ($restricted && ! trim($data['policy_reference'] ?? '')) {
                throw ValidationException::withMessages(['policy_reference' => 'Dasar kebijakan sah wajib dicatat untuk pertanyaan terbatas.']);
            }
            $snapshot = $questions->map(function ($q): array {
                $row = (array) $q;
                foreach (['options', 'clarification_rule', 'followup_rule', 'applicability_rule'] as $field) {
                    $row[$field] = $q->$field ? json_decode($q->$field, true) : null;
                }

                return $row;
            })->all();
            $template->versions()->create([...$data, 'version' => ($template->versions()->max('version') ?? 0) + 1, 'snapshot' => ['questions' => $snapshot], 'published_by' => $request->user()->id, 'published_at' => now(), 'policy_approved_by' => $restricted ? $request->user()->id : null]);
            $template->update(['status' => 'published']);
        });

        return back()->with('success', 'Versi template berhasil diterbitkan. Jawaban sesi lama tetap memakai versi asal.');
    }

    public function question(Request $request): RedirectResponse
    {
        if ($request->exists('guided_options')) {
            $guided = $request->validate(['guided_options' => ['nullable', 'string', 'max:5000'], 'guided_clarification' => ['nullable', Rule::in(['YA', 'TIDAK', 'TIDAK_TAHU'])], 'guided_followup' => ['nullable', Rule::in(['NEEDS_CLARIFICATION', 'REFER_TO_MCU', 'MEDICAL_REVIEW_REQUIRED'])], 'guided_followup_when' => ['nullable', Rule::in(['YA', 'TIDAK', 'TIDAK_TAHU'])], 'question_id' => ['nullable', 'integer', 'exists:health_questions,id']]);
            $existing = ! empty($guided['question_id']) ? DB::table('health_questions')->where('id', $guided['question_id'])->first() : null;
            if ($existing && $existing->code !== $request->input('code')) {
                throw ValidationException::withMessages(['code' => 'Kode pertanyaan yang sudah tersimpan tidak dapat diubah.']);
            }
            $oldOptions = $existing?->options ? json_decode($existing->options, true) : [];
            $options = [];
            foreach (array_values(array_filter(array_map('trim', preg_split('/\R/', $guided['guided_options'] ?? '')))) as $index => $label) {
                $key = array_keys($oldOptions ?? [])[$index] ?? 'PILIHAN_'.($index + 1);
                $options[$key] = $label;
            }
            $clarification = $existing?->clarification_rule ? json_decode($existing->clarification_rule, true) : [];
            $clarification['when'] ??= [];
            $selectedClarification = $guided['guided_clarification'] ?? '';
            if (($clarification['when'][0] ?? '') !== $selectedClarification) {
                $clarification['when'] = $selectedClarification !== '' ? [$selectedClarification] : [];
            }
            $followup = $existing?->followup_rule ? json_decode($existing->followup_rule, true) : [];
            if (! empty($guided['guided_followup'])) {
                $selectedFollowup = $guided['guided_followup_when'] ?? 'YA';
                if (($followup['when'][0] ?? '') !== $selectedFollowup) {
                    $followup['when'] = [$selectedFollowup];
                }
                $followup['outcome'] = $guided['guided_followup'];
            } else {
                $followup = [];
            }
            $request->merge([
                'options_json' => $options ? json_encode($options) : null,
                'clarification_json' => $clarification['when'] ? json_encode($clarification) : null,
                'followup_json' => $followup ? json_encode($followup) : null,
                'applicability_json' => $existing?->applicability_rule,
            ]);
        }
        $data = $request->validate(['question_id' => ['nullable', 'integer', 'exists:health_questions,id'], 'code' => ['required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]+$/'], 'question' => ['required', 'string', 'max:2000'], 'category' => ['required', 'string', 'max:255'], 'helper_text' => ['nullable', 'string', 'max:2000'], 'answer_type' => ['required', Rule::in(HealthScreeningEngine::TYPES)], 'options_json' => ['nullable', 'json'], 'clarification_json' => ['nullable', 'json'], 'followup_json' => ['nullable', 'json'], 'applicability_json' => ['nullable', 'json'], 'is_required' => ['required', 'boolean'], 'restricted' => ['required', 'boolean'], 'is_active' => ['required', 'boolean'], 'sequence' => ['required', 'integer', 'min:0', 'max:10000']]);
        if (! empty($data['question_id']) && DB::table('health_questions')->where('id', $data['question_id'])->where('code', '!=', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Kode pertanyaan yang sudah tersimpan tidak dapat diubah.']);
        }
        if (($data['question_id'] ?? null) && DB::table('health_questions')->where('id', $data['question_id'])->where('answer_type', 'LEGACY_TEXT')->exists()) {
            throw ValidationException::withMessages(['question_id' => 'Pertanyaan generik lama harus dipertahankan. Buat pertanyaan katalog baru.']);
        }
        if (DB::table('health_questions')->where('code', $data['code'])->when($data['question_id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))->exists()) {
            throw ValidationException::withMessages(['code' => 'Kode sudah digunakan.']);
        }
        $row = collect($data)->except(['question_id', 'options_json', 'clarification_json', 'followup_json', 'applicability_json'])->all();
        foreach (['options', 'clarification', 'followup', 'applicability'] as $field) {
            $value = isset($data[$field.'_json']) ? json_decode($data[$field.'_json'], true) : null;
            if ($value !== null && ! is_array($value)) {
                throw ValidationException::withMessages([$field.'_json' => 'Konfigurasi harus berupa objek atau daftar JSON.']);
            }
            if ($field === 'followup' && $value && ! in_array($value['outcome'] ?? '', ['NEEDS_CLARIFICATION', 'REFER_TO_MCU', 'MEDICAL_REVIEW_REQUIRED'], true)) {
                throw ValidationException::withMessages(['followup_json' => 'Hasil hanya boleh berupa klarifikasi, rujukan MCU, atau review medis.']);
            }
            if ($field === 'applicability' && $value && ((! isset($value['question_code']) && ! in_array($value['field'] ?? '', ['gender', 'candidate_status', 'application_status'], true)) || ! is_array($value['values'] ?? null))) {
                throw ValidationException::withMessages(['applicability_json' => 'Gunakan field gender/candidate_status/application_status dan daftar values.']);
            }
            $row[$field === 'options' ? 'options' : $field.'_rule'] = $value ? json_encode($value) : null;
        }
        $clarification = json_decode($row['clarification_rule'] ?? 'null', true);
        if ($clarification && ! is_array($clarification['when'] ?? null)) {
            throw ValidationException::withMessages(['clarification_json' => 'Pemicu when harus berupa daftar kode jawaban.']);
        }
        foreach ($clarification['fields'] ?? [] as $field) {
            Validator::make($field, ['key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]+$/'], 'label' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(HealthScreeningEngine::TYPES)], 'required' => ['required', 'boolean'], 'options' => ['nullable', 'array'], 'options.*' => ['string', 'max:255']])->validate();
            if (in_array($field['type'], ['SINGLE_SELECT', 'MULTI_SELECT'], true) && empty($field['options'])) {
                throw ValidationException::withMessages(['clarification_json' => 'Pilihan detail harus memiliki options.']);
            }
        }
        $options = json_decode($row['options'] ?? 'null', true);
        if ($options && (array_is_list($options) || collect($options)->contains(fn ($label) => ! is_string($label)))) {
            throw ValidationException::withMessages(['options_json' => 'Pilihan harus berupa objek kode dan label teks.']);
        }
        $followup = json_decode($row['followup_rule'] ?? 'null', true);
        if ($followup && ! is_array($followup['when'] ?? null)) {
            throw ValidationException::withMessages(['followup_json' => 'Pemicu when harus berupa daftar kode jawaban.']);
        }
        if (str_starts_with($data['answer_type'], 'YES_NO')) {
            $row['options'] = json_encode(['TIDAK' => 'Tidak', 'YA' => 'Ya', 'TIDAK_TAHU' => 'Tidak tahu', ...($data['answer_type'] === 'YES_NO_UNKNOWN_NOT_APPLICABLE' ? ['TIDAK_RELEVAN' => 'Tidak relevan'] : [])]);
        }
        if ($data['answer_type'] === 'SHORT_TEXT_CONDITIONAL' && ! isset(json_decode($row['applicability_rule'] ?? 'null', true)['question_code'])) {
            throw ValidationException::withMessages(['applicability_json' => 'Teks singkat harus memiliki question_code dan values sebagai pemicu dari jawaban pertanyaan lain.']);
        }
        if (in_array($data['answer_type'], ['SINGLE_SELECT', 'MULTI_SELECT'], true) && ! $row['options']) {
            throw ValidationException::withMessages(['options_json' => 'Pilihan jawaban wajib diisi.']);
        }
        if (in_array($data['code'], ['HIV_KNOWN_STATUS', 'TPHA_VDRL_HISTORY', 'ILLICIT_DRUG_HISTORY', 'CURRENT_MENSTRUATION', 'VAGINAL_DISCHARGE'], true)) {
            $row['restricted'] = true;
        }
        if ($data['question_id'] ?? null) {
            DB::table('health_questions')->where('id', $data['question_id'])->update([...$row, 'updated_at' => now()]);
        } else {
            DB::table('health_questions')->insert([...$row, 'created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('success', 'Katalog pertanyaan disimpan. Versi yang sudah terbit tidak berubah.');
    }
}
