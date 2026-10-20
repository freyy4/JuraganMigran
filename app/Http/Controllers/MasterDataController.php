<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveMasterDataRequest;
use App\Models\ApplicationBlocker;
use App\Models\Task;
use App\Models\Vacancy;
use App\Services\AccessPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MasterDataController extends Controller
{
    private function definition(string $master): array
    {
        $definition = config('master-data.'.$master);
        abort_unless(is_array($definition) && isset($definition['model']), 404);

        return $definition;
    }

    public function index(Request $request, string $master): View
    {
        $definition = $this->definition($master);
        $model = new $definition['model'];
        $rules = ['search' => ['nullable', 'string', 'max:255']];
        if (isset($definition['fields']['is_active'])) {
            $rules['is_active'] = ['nullable', 'boolean'];
        }
        if (isset($definition['parent_field'])) {
            $parent = new $definition['parent_model'];
            $rules['parent_id'] = ['nullable', 'integer', Rule::exists($parent->getTable(), 'id')];
        }
        $filters = $request->validate($rules, ['string' => 'Pencarian harus berupa teks.', 'max' => 'Pencarian maksimal 255 karakter.', 'boolean' => 'Status tidak valid.', 'integer' => 'Induk wilayah tidak valid.', 'exists' => 'Induk wilayah tidak tersedia.']);
        $query = $model->newQuery();
        if (isset($definition['parent_field'])) {
            $query->with('parent');
            if ($filters['parent_id'] ?? null) {
                $query->where($definition['parent_field'], $filters['parent_id']);
            }
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'));
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return view('master-data.index', ['master' => $master, 'definition' => $definition, 'rows' => $query->orderBy($master === 'tahapan-proses' ? 'sequence' : 'name')->orderBy('id')->paginate(25)->withQueryString(), 'parents' => isset($definition['parent_model']) ? $definition['parent_model']::orderBy('name')->get() : collect()]);
    }

    private function form(string $master, Model $record): View
    {
        $definition = $this->definition($master);
        $parents = isset($definition['parent_model']) ? $definition['parent_model']::query() : null;
        if ($parents && $master === 'provinsi') {
            $parents->availableForSelection($record->country_id);
        }

        return view('master-data.form', ['master' => $master, 'definition' => $definition, 'record' => $record, 'parents' => $parents?->orderBy('name')->get() ?? collect()]);
    }

    public function create(string $master): View
    {
        $definition = $this->definition($master);

        return $this->form($master, new $definition['model'](['is_active' => true, 'is_terminal' => false, 'has_expiry' => false, 'sequence' => 0, 'data_type' => 'string']));
    }

    public function edit(string $master, int $record): View
    {
        $definition = $this->definition($master);

        return $this->form($master, $definition['model']::findOrFail($record));
    }

    public function store(SaveMasterDataRequest $request, string $master): RedirectResponse
    {
        $definition = $this->definition($master);
        $model = $definition['model']::create($request->validated());
        app(AccessPolicy::class)->audit($request->user(), 'master.created', ['resource' => $master, 'id' => $model->id]);

        return to_route('master-data.index', $master)->with('success', $definition['label'].' berhasil ditambahkan.');
    }

    public function update(SaveMasterDataRequest $request, string $master, int $record): RedirectResponse
    {
        $definition = $this->definition($master);
        DB::transaction(function () use ($request, $definition, $record, $master): void {
            $model = $definition['model']::whereKey($record)->lockForUpdate()->firstOrFail();
            $data = $request->validated();
            if ($master === 'jenis-persyaratan' && $data['data_type'] !== $model->data_type && $model->vacancyRequirements()->exists()) {
                throw ValidationException::withMessages(['data_type' => 'Tipe data tidak dapat diubah karena sudah digunakan oleh persyaratan lowongan.']);
            }
            $used = match ($master) {
                'jenis-persyaratan' => $model->vacancyRequirements()->exists(),
                'tahapan-proses' => $model->applications()->exists() || DB::table('application_stage_histories')->where('from_stage_id', $model->id)->orWhere('to_stage_id', $model->id)->exists(),
                'jenis-dokumen' => DB::table('candidate_documents')->where('document_type_id', $model->id)->exists() || DB::table('vacancy_document_requirements')->where('document_type_id', $model->id)->exists() || DB::table('document_requirement_rules')->where('document_type_id', $model->id)->exists(),
                default => false,
            };
            if ($master === 'tahapan-proses' && ! $used) {
                foreach (DB::table('process_workflow_versions')->select('snapshot')->cursor() as $version) {
                    if (collect(json_decode($version->snapshot, true)['stages'])->contains('id', $model->id)) {
                        $used = true;
                        break;
                    }
                }
            }
            if ($used && isset($data['code']) && $data['code'] !== $model->code) {
                throw ValidationException::withMessages(['code' => 'Kode sudah digunakan dan tidak dapat diubah.']);
            }
            if ($master === 'tahapan-proses' && $used && collect(['sequence', 'sla_hours', 'is_terminal'])->contains(fn ($field) => isset($data[$field]) && $data[$field] != $model->{$field})) {
                throw ValidationException::withMessages(['sequence' => 'Tahap yang sudah digunakan tidak boleh mengubah alur aktif. Buat tahap baru untuk perubahan alur.']);
            }
            $model->update($data);
            app(AccessPolicy::class)->audit($request->user(), 'master.updated', ['resource' => $master, 'id' => $model->id]);
        });

        return to_route('master-data.index', $master)->with('success', $definition['label'].' berhasil diperbarui.');
    }

    private function referenceDefinition(string $reference): array
    {
        $definitions = ['posisi-pekerjaan' => ['label' => 'Posisi Pekerjaan', 'model' => Vacancy::class, 'table' => 'vacancies', 'field' => 'position_name', 'route' => 'vacancies.index'], 'jenis-tugas' => ['label' => 'Jenis Tugas', 'model' => Task::class, 'table' => 'tasks', 'field' => 'task_type', 'route' => 'tasks.index'], 'kategori-kendala' => ['label' => 'Kategori Kendala', 'model' => ApplicationBlocker::class, 'table' => 'application_blockers', 'field' => 'category', 'route' => 'blockers.index'], 'jenis-perusahaan' => ['label' => 'Jenis Perusahaan', 'table' => 'companies', 'field' => 'type', 'route' => 'companies.index']];
        abort_unless(isset($definitions[$reference]), 404);

        return $definitions[$reference];
    }

    public function reference(Request $request, string $reference): View
    {
        $definition = $this->referenceDefinition($reference);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'is_active' => ['nullable', 'boolean']]);
        $query = DB::table('master_reference_entries as entry')->where('resource', $reference);
        if ($search = trim($data['search'] ?? '')) {
            $query->where(fn ($query) => $query->where('entry.name', 'like', '%'.$search.'%')->orWhere('entry.code', 'like', '%'.$search.'%'));
        }
        if (isset($data['is_active'])) {
            $query->where('is_active', $data['is_active']);
        }
        $table = $definition['table'];
        $field = $definition['field'];
        $column = $reference === 'posisi-pekerjaan' ? 'entry.name' : 'entry.code';
        $query->select('entry.*')->selectSub(DB::table($table)->whereColumn($field, $column)->selectRaw('COUNT(*)'), 'total');
        if ($reference === 'posisi-pekerjaan') {
            $query->selectSub(DB::table('vacancies')->whereColumn('position_name', 'entry.name')->whereNull('deleted_at')->whereIn('status', ['active', 'published', 'open'])->selectRaw('COUNT(*)'), 'active_vacancies');
        }
        $rows = $query->orderBy('entry.name')->orderBy('entry.id')->paginate(25)->withQueryString();

        return view('master-data.reference', compact('definition', 'rows', 'reference'));
    }

    public function saveReference(Request $request, string $reference): RedirectResponse
    {
        $definition = $this->referenceDefinition($reference);
        $data = $request->validate(['id' => ['nullable', 'integer'], 'code' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:255'], 'category' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'default_priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'sla_hours' => ['nullable', 'integer', 'min:0', 'max:87600'], 'default_role' => ['nullable', 'string', 'max:100'], 'is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $data, $reference, $definition): void {
            $entry = ! empty($data['id']) ? DB::table('master_reference_entries')->where('resource', $reference)->where('id', $data['id'])->lockForUpdate()->first() : null;
            abort_if(! empty($data['id']) && ! $entry, 404);
            $used = $entry && DB::table($definition['table'])->where($definition['field'], $reference === 'posisi-pekerjaan' ? $entry->name : $entry->code)->exists();
            if ($entry && (($entry->system_protected || $used) && $entry->code !== $data['code'] || $used && $reference === 'posisi-pekerjaan' && $entry->name !== $data['name'])) {
                throw ValidationException::withMessages(['code' => 'Kode atau posisi yang sudah digunakan tidak dapat diubah.']);
            }
            if ($reference === 'jenis-perusahaan' && ! $entry) {
                throw ValidationException::withMessages(['code' => 'Jenis perusahaan sistem tidak dapat ditambah bebas.']);
            }
            if (DB::table('master_reference_entries')->where('resource', $reference)->where('code', $data['code'])->when($entry, fn ($query) => $query->where('id', '!=', $entry->id))->exists()) {
                throw ValidationException::withMessages(['code' => 'Kode sudah digunakan.']);
            }
            $values = collect($data)->except('id')->all();
            if ($entry) {
                DB::table('master_reference_entries')->where('id', $entry->id)->update([...$values, 'updated_at' => now()]);
                $id = $entry->id;
            } else {
                $id = DB::table('master_reference_entries')->insertGetId([...$values, 'resource' => $reference, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(AccessPolicy::class)->audit($request->user(), 'master.reference.saved', ['resource' => $reference, 'id' => $id]);
        });

        Cache::forget('master.company-type-labels');

        return to_route('master-reference.index', $reference)->with('success', $definition['label'].' disimpan. Histori penggunaan tetap dipertahankan.');
    }
}
