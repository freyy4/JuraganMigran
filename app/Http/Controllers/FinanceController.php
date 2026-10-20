<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Company;
use App\Services\AccessPolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public const MODULES = [
        'agreements' => ['commercial_agreements', 'Perjanjian Mitra'],
        'milestones' => ['agreement_milestones', 'Tahapan Pembayaran'],
        'receivables' => ['sponsor_receivables', 'Tagihan Mitra'],
        'sponsor-payments' => ['sponsor_payments', 'Pembayaran Mitra'],
        'benefits' => ['candidate_benefit_plans', 'Hak CPMI'],
        'obligations' => ['financial_obligations', 'Kewajiban CPMI'],
        'settlements' => [null, 'Penyelesaian Keuangan'],
    ];

    public static function fields(string $module): array
    {
        return match ($module) {
            'agreements' => ['company_id' => ['Mitra', 'select', true], 'agreement_number' => ['Nomor Perjanjian', 'text', true], 'start_date' => ['Mulai', 'date', false], 'end_date' => ['Selesai', 'date', false], 'status' => ['Status', 'select', true]],
            'milestones' => ['agreement_id' => ['Perjanjian', 'select', true], 'name' => ['Nama Tahapan', 'text', true], 'trigger_type' => ['Pemicu', 'select', true], 'trigger_stage_id' => ['Tahap Proses', 'select', false], 'percentage' => ['Persentase (opsional)', 'number', false], 'amount' => ['Nominal (opsional)', 'number', false], 'currency' => ['Mata Uang', 'text', true], 'sequence' => ['Urutan', 'number', true]],
            'receivables' => ['application_id' => ['Lamaran', 'select', true], 'agreement_milestone_id' => ['Tahapan', 'select', true], 'amount' => ['Nominal Tagihan', 'number', true], 'currency' => ['Mata Uang', 'text', true], 'due_date' => ['Jatuh Tempo', 'date', false], 'invoice_number' => ['Nomor Tagihan', 'text', false]],
            'sponsor-payments' => ['receivable_id' => ['Tagihan', 'select', true], 'amount' => ['Nominal Pembayaran', 'number', true], 'paid_at' => ['Dibayar Pada (WIB)', 'datetime-local', true], 'reference_number' => ['Referensi', 'text', true]],
            'benefits' => ['application_id' => ['Lamaran', 'select', true], 'target_amount' => ['Hak CPMI', 'number', true], 'currency' => ['Mata Uang', 'text', true], 'basis' => ['Dasar Hak', 'textarea', true]],
            'obligations' => ['application_id' => ['Lamaran', 'select', true], 'component' => ['Komponen', 'text', true], 'basis' => ['Dasar Kewajiban', 'textarea', true], 'agreement_reference' => ['Referensi Perjanjian', 'text', true], 'amount' => ['Nominal (IDR)', 'number', true], 'due_date' => ['Jatuh Tempo', 'date', false]],
            default => [],
        };
    }

    public static function cents(string|int|float $value): int
    {
        $parts = explode('.', (string) $value);

        return (int) $parts[0] * 100 + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
    }

    public static function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function percentageUnits(string|int|float|null $value): int
    {
        $parts = explode('.', (string) ($value ?? 0));

        return (int) $parts[0] * 10000 + (int) str_pad(substr($parts[1] ?? '', 0, 4), 4, '0');
    }

    private function options(string $module): array
    {
        return [
            'company_id' => Company::whereIn('id', app(AccessPolicy::class)->companyIds(auth()->user(), request()->attributes->get('access_permission')))->orderBy('name')->pluck('name', 'id')->all(),
            'application_id' => Application::orderByDesc('id')->pluck('application_code', 'id')->all(),
            'agreement_id' => app(AccessPolicy::class)->scopeFinance(DB::table('commercial_agreements'), 'agreements', 'commercial_agreements')->pluck('agreement_number', 'id')->all(),
            'agreement_milestone_id' => app(AccessPolicy::class)->scopeFinance(DB::table('agreement_milestones'), 'milestones', 'agreement_milestones')->select('id', 'name')->get()->mapWithKeys(fn ($row) => [$row->id => $row->name.' (#'.$row->id.')'])->all(),
            'trigger_stage_id' => DB::table('pipeline_stages')->pluck('name', 'id')->all(),
            'receivable_id' => app(AccessPolicy::class)->scopeFinance(DB::table('sponsor_receivables'), 'receivables', 'sponsor_receivables')->select('id', 'invoice_number', 'currency', 'amount')->get()->mapWithKeys(fn ($row) => [$row->id => ($row->invoice_number ?? 'Tagihan #'.$row->id).' · '.$row->currency.' '.$row->amount])->all(),
            'status' => ['draft' => 'Draf', 'active' => 'Aktif', 'closed' => 'Ditutup'],
            'trigger_type' => ['manual' => 'Manual', 'stage' => 'Tahap Proses', 'departure' => 'Keberangkatan', 'arrival' => 'Kedatangan'],
        ];
    }

    private function paymentInfo(string $module): array
    {
        return match ($module) {
            'receivables' => ['sponsor_payments', 'receivable_id', 'amount', 'paid_at'],'benefits' => ['candidate_benefit_disbursements', 'benefit_plan_id', 'target_amount', 'disbursed_at'],'obligations' => ['financial_obligation_payments', 'financial_obligation_id', 'amount', 'paid_at']
        };
    }

    private function records(string $module): Builder
    {
        $query = DB::table(self::MODULES[$module][0].' as record')->select('record.*');
        if (in_array($module, ['receivables', 'benefits', 'obligations'], true)) {
            [$payments,$foreign] = $this->paymentInfo($module);
            $query->selectSub(DB::table($payments)->selectRaw('COALESCE(SUM(amount),0)')->whereColumn($foreign, 'record.id'), 'paid_total');
        }

        return app(AccessPolicy::class)->scopeFinance($query, $module);
    }

    public function index(Request $request): View
    {
        $module = $request->route()->defaults['module'];
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'aging' => ['nullable', 'in:current,1-30,31-60,61-90,90-plus'], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/']]);
        $options = $this->options($module);
        if ($module === 'settlements') {
            $sections = [];
            foreach (['receivables', 'benefits', 'obligations'] as $kind) {
                $sections[$kind] = $this->records($kind)->orderByDesc('record.id')->paginate(15, ['*'], $kind.'_page')->withQueryString();
            }

            return view('finance.settlements', ['sections' => $sections, 'options' => $options]);
        }
        $query = $this->records($module);
        if ($search = trim($data['search'] ?? '')) {
            $query->where(function ($query) use ($module, $search): void {
                $query->where('record.id', ctype_digit($search) ? $search : 0);
                foreach (self::fields($module) as $field => [$label,$type]) {
                    if (in_array($type, ['text', 'textarea'], true)) {
                        $query->orWhere('record.'.$field, 'like', '%'.$search.'%');
                    }
                }
            });
        }
        if ($module === 'receivables' && $request->filled('aging')) {
            $query->where('record.status', '!=', 'cancelled')->whereRaw('record.amount > (SELECT COALESCE(SUM(amount),0) FROM sponsor_payments WHERE receivable_id = record.id)');
            $today = now('Asia/Jakarta')->startOfDay();
            $bucket = $request->input('aging');
            if ($bucket === 'current') {
                $query->where(fn ($q) => $q->whereNull('record.due_date')->orWhere('record.due_date', '>=', $today->toDateString()));
            } elseif ($bucket === '90-plus') {
                $query->where('record.due_date', '<', $today->copy()->subDays(90)->toDateString());
            } else {
                [$minimum, $maximum] = array_map('intval', explode('-', $bucket));
                $query->whereBetween('record.due_date', [$today->copy()->subDays($maximum)->toDateString(), $today->copy()->subDays($minimum)->toDateString()]);
            }
            if ($request->filled('currency')) {
                $query->where('record.currency', $request->input('currency'));
            }
        }
        $records = $query->orderByDesc('record.id')->paginate(15)->withQueryString();
        $histories = collect();
        if (in_array($module, ['benefits', 'obligations'], true)) {
            [$payments,$foreign] = $this->paymentInfo($module);
            $histories = DB::table($payments)->whereIn($foreign, $records->pluck('id'))->orderByDesc('id')->get()->groupBy($foreign);
        }

        return view('finance.index', ['histories' => $histories, 'module' => $module, 'label' => self::MODULES[$module][1], 'records' => $records, 'fields' => self::fields($module), 'options' => $options]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, null);
    }

    private function form(Request $request, ?object $record): View
    {
        $module = $request->route()->defaults['module'];

        return view('finance.form', ['module' => $module, 'record' => $record, 'label' => self::MODULES[$module][1], 'fields' => self::fields($module), 'options' => $this->options($module)]);
    }

    public function edit(Request $request, int $record): View
    {
        $module = $request->route()->defaults['module'];
        $row = $this->records($module)->where('record.id', $record)->first();
        abort_unless($row, 404);

        return $this->form($request, $row);
    }

    private function data(Request $request, string $module, ?int $record): array
    {
        $rules = [];
        $foreign = ['company_id' => 'companies', 'application_id' => 'applications', 'agreement_id' => 'commercial_agreements', 'agreement_milestone_id' => 'agreement_milestones', 'trigger_stage_id' => 'pipeline_stages', 'receivable_id' => 'sponsor_receivables'];
        foreach (self::fields($module) as $field => [$label,$type,$required]) {
            $rules[$field] = [$required ? 'required' : 'nullable'];
            if (isset($foreign[$field])) {
                $rule = Rule::exists($foreign[$field], 'id');
                if (in_array($field, ['company_id', 'application_id'], true)) {
                    $rule->whereNull('deleted_at');
                }$rules[$field] = [...$rules[$field], 'integer', $rule];
            } elseif (in_array($field, ['amount', 'target_amount'], true)) {
                $rules[$field] = [...$rules[$field], 'numeric', 'gt:0', 'regex:/^\d{1,12}(\.\d{1,2})?$/'];
            } elseif ($field === 'percentage') {
                $rules[$field] = [...$rules[$field], 'numeric', 'gt:0', 'max:100', 'decimal:0,4'];
            } elseif ($field === 'sequence') {
                $rules[$field] = [...$rules[$field], 'integer', 'min:0', 'max:4294967295'];
            } elseif ($field === 'currency') {
                $rules[$field] = [...$rules[$field], 'regex:/^[A-Z]{3}$/'];
            } elseif ($type === 'select') {
                $rules[$field] = [...$rules[$field], Rule::in(array_keys($this->options($module)[$field]))];
            } elseif ($type === 'date') {
                $rules[$field][] = 'date_format:Y-m-d';
            } elseif ($type === 'datetime-local') {
                $rules[$field][] = 'date_format:Y-m-d\TH:i';
            } else {
                $rules[$field] = [...$rules[$field], 'string', 'max:'.($type === 'textarea' ? 10000 : 255)];
            }
        }
        if ($module === 'agreements') {
            $rules['agreement_number'][] = Rule::unique('commercial_agreements', 'agreement_number')->ignore($record);
            if ($request->filled('start_date')) {
                $rules['end_date'][] = 'after_or_equal:start_date';
            }
        }
        if ($module === 'receivables') {
            $rules['invoice_number'][] = Rule::unique('sponsor_receivables', 'invoice_number')->ignore($record);
        }
        if ($module === 'milestones') {
            $rules['trigger_stage_id'][] = 'required_if:trigger_type,stage';
        }
        $data = $request->validate($rules, ['required' => ':attribute wajib diisi.', 'exists' => ':attribute tidak tersedia.', 'unique' => ':attribute sudah digunakan.', 'gt' => 'Nominal harus lebih besar dari nol.', 'regex' => 'Format :attribute tidak valid. Nominal maksimal 12 angka bulat dan 2 desimal; mata uang berupa 3 huruf kapital.', 'after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.']);

        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        $module = $request->route()->defaults['module'];
        if ($module === 'sponsor-payments') {
            $request->validate(['receivable_id' => ['required', 'integer', Rule::exists('sponsor_receivables', 'id')]]);

            return $this->pay($request, (int) $request->input('receivable_id'), 'receivables');
        }

        return $this->save($request, null);
    }

    public function update(Request $request, int $record): RedirectResponse
    {
        return $this->save($request, $record);
    }

    private function save(Request $request, ?int $record): RedirectResponse
    {
        $module = $request->route()->defaults['module'];
        $table = self::MODULES[$module][0];
        $data = $this->data($request, $module, $record);
        if ($record) {
            abort_unless($this->records($module)->where('record.id', $record)->exists(), 404);
        }
        $policy = app(AccessPolicy::class);
        $permission = request()->attributes->get('access_permission');
        if (isset($data['company_id'])) {
            abort_unless($policy->companyIds($request->user(), $permission)->where('companies.id', $data['company_id'])->exists(), 403);
        }
        if (isset($data['agreement_id'])) {
            abort_unless($this->records('agreements')->where('record.id', $data['agreement_id'])->exists(), 403);
        }
        if (isset($data['agreement_milestone_id'])) {
            abort_unless($this->records('milestones')->where('record.id', $data['agreement_milestone_id'])->exists(), 403);
        }
        DB::transaction(function () use ($module, $table, $data, $record): void {
            if ($record) {
                $old = DB::table($table)->where('id', $record)->lockForUpdate()->first();
                abort_unless($old, 404);
            }
            if ($module === 'agreements' && $record && (int) $old->company_id !== (int) $data['company_id'] && DB::table('agreement_milestones')->where('agreement_id', $record)->exists()) {
                throw ValidationException::withMessages(['company_id' => 'Mitra tidak dapat diganti setelah memiliki tahapan pembayaran.']);
            }
            if (in_array($module, ['receivables', 'benefits', 'obligations'], true)) {
                [$payments,$foreign] = $this->paymentInfo($module);
                if ($record && DB::table($payments)->where($foreign, $record)->exists()) {
                    throw ValidationException::withMessages(['amount' => 'Catatan dengan pembayaran tidak dapat diubah.']);
                }
            }
            if ($module === 'milestones') {
                DB::table('commercial_agreements')->where('id', $data['agreement_id'])->lockForUpdate()->first();
                if ($record && DB::table('sponsor_receivables')->where('agreement_milestone_id', $record)->exists()) {
                    throw ValidationException::withMessages(['name' => 'Tahapan sudah digunakan dalam tagihan.']);
                }
                if (empty($data['amount']) && empty($data['percentage'])) {
                    throw ValidationException::withMessages(['amount' => 'Isi nominal atau persentase tahapan.']);
                }
                $percent = DB::table('agreement_milestones')->where('agreement_id', $data['agreement_id'])->when($record, fn ($query) => $query->where('id', '!=', $record))->pluck('percentage')->reduce(fn ($sum, $value) => $sum + self::percentageUnits($value), 0);
                if ($percent + self::percentageUnits($data['percentage'] ?? 0) > 1000000) {
                    throw ValidationException::withMessages(['percentage' => 'Total persentase tahapan tidak boleh melebihi 100%.']);
                }
                if ($data['trigger_type'] !== 'stage') {
                    $data['trigger_stage_id'] = null;
                }
            }
            if ($module === 'receivables') {
                $milestone = DB::table('agreement_milestones')->where('id', $data['agreement_milestone_id'])->lockForUpdate()->first();
                if (! $milestone) {
                    throw ValidationException::withMessages(['agreement_milestone_id' => 'Tahapan tidak tersedia.']);
                }
                $agreement = DB::table('commercial_agreements')->where('id', $milestone->agreement_id)->lockForUpdate()->first();
                if (! $agreement || $agreement->status !== 'active') {
                    throw ValidationException::withMessages(['agreement_milestone_id' => 'Perjanjian harus aktif sebelum tagihan dicatat.']);
                }
                $application = Application::with('vacancy.jobOrder')->findOrFail($data['application_id']);
                if ((int) $application->vacancy?->jobOrder?->company_id !== (int) $agreement->company_id || $data['currency'] !== $milestone->currency) {
                    throw ValidationException::withMessages(['agreement_milestone_id' => 'Mitra dan mata uang tahapan harus sesuai tagihan.']);
                }
                $data['status'] = empty($data['invoice_number']) ? 'eligible' : 'invoiced';
            }
            if ($module === 'benefits') {
                $data['status'] = 'active';
            }
            if ($module === 'obligations') {
                $data['balance'] = $data['amount'];
                $data['status'] = 'open';
            }
            if ($record) {
                DB::table($table)->where('id', $record)->update([...$data, 'updated_at' => now()]);
            } else {
                DB::table($table)->insert([...$data, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return to_route($module.'.index')->with('success', 'Data keuangan berhasil disimpan.');
    }

    public function payment(Request $request, int $record): RedirectResponse
    {
        return $this->pay($request, $record, $request->route()->defaults['module']);
    }

    private function pay(Request $request, int $record, string $module): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'paid_at' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.now('Asia/Jakarta')->format('Y-m-d\TH:i')], 'reference_number' => ['required', 'string', 'max:255'], 'purpose' => [$module === 'benefits' ? 'required' : 'nullable', 'string', 'max:255'], 'recipient' => [$module === 'benefits' ? 'required' : 'nullable', 'string', 'max:255'], 'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        $path = $request->file('evidence')?->store('', 'documents');
        try {
            DB::transaction(function () use ($data, $record, $module, $path, $request): void {
                $row = DB::table(self::MODULES[$module][0])->where('id', $record)->lockForUpdate()->first();
                abort_unless($row, 404);
                [$table,$foreign,$total,$date] = $this->paymentInfo($module);
                $paid = DB::table($table)->where($foreign, $record)->pluck('amount')->reduce(fn ($sum, $amount) => $sum + self::cents($amount), 0);
                if ($module === 'benefits' && $row->status !== 'active') {
                    throw ValidationException::withMessages(['amount' => 'Rencana hak tidak aktif.']);
                }
                $referenceField = $module === 'obligations' ? 'reference' : 'reference_number';
                $purpose = ($data['purpose'] ?? '').' ('.$data['reference_number'].')';
                if ($module === 'benefits' && mb_strlen($purpose) > 255) {
                    throw ValidationException::withMessages(['purpose' => 'Tujuan dan referensi maksimal 255 karakter.']);
                }
                $duplicate = $module === 'benefits'
                    ? DB::table($table)->where($foreign, $record)->pluck('purpose')->contains(fn ($existing) => str_ends_with($existing ?? '', ' ('.$data['reference_number'].')'))
                    : DB::table($table)->where($foreign, $record)->where($referenceField, $data['reference_number'])->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['reference_number' => 'Referensi pembayaran sudah dicatat pada tagihan/rencana ini.']);
                }
                $amount = self::cents($data['amount']);
                $balance = self::cents($row->$total) - $paid;
                if ($amount > $balance) {
                    throw ValidationException::withMessages(['amount' => 'Pembayaran melebihi sisa saldo.']);
                }
                $reference = $module === 'obligations' ? 'reference' : 'reference_number';
                $values = [$foreign => $record, 'amount' => $data['amount'], $date => Carbon::createFromFormat('Y-m-d\TH:i', $data['paid_at'], 'Asia/Jakarta')->setTimezone(config('app.timezone'))->startOfMinute(), 'evidence_file' => $path, 'created_at' => now(), 'updated_at' => now()];
                if ($module === 'benefits') {
                    $values += ['purpose' => $purpose, 'recipient' => $data['recipient'], 'approved_by' => $request->user()->id];
                } else {
                    $values += [$reference => $data['reference_number'], 'received_by' => $request->user()->id];
                }
                DB::table($table)->insert($values);
                $state = ['updated_at' => now(), 'status' => $amount === $balance ? ($module === 'obligations' ? 'settled' : ($module === 'benefits' ? 'completed' : 'paid')) : ($module === 'obligations' ? 'open' : ($module === 'benefits' ? 'active' : 'partial'))];
                if ($module === 'obligations') {
                    $state['balance'] = self::money($balance - $amount);
                }
                DB::table(self::MODULES[$module][0])->where('id', $record)->update($state);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('documents')->delete($path);
            }throw $exception;
        }

        return to_route($module === 'receivables' ? 'sponsor-payments.index' : $module.'.index')->with('success', 'Pembayaran berhasil dicatat.');
    }

    public function file(Request $request, int $record): StreamedResponse
    {
        $kind = $request->route()->defaults['file_kind'];
        $table = match ($kind) {
            'sponsor' => 'sponsor_payments','benefit' => 'candidate_benefit_disbursements','obligation' => 'financial_obligation_payments'
        };
        $row = DB::table($table)->find($record);
        $parentTable = match ($kind) {
            'sponsor' => 'sponsor_receivables','benefit' => 'candidate_benefit_plans',default => 'financial_obligations'
        };
        $foreign = match ($kind) {
            'sponsor' => 'receivable_id','benefit' => 'benefit_plan_id',default => 'financial_obligation_id'
        };
        abort_unless($row && DB::table($parentTable)->where('id', $row->$foreign)->whereIn('application_id', app(AccessPolicy::class)->applicationIds($request->user(), $request->attributes->get('access_permission')))->exists(), 404);
        $path = $row?->evidence_file;
        abort_unless($path && preg_match('/^[a-zA-Z0-9_-]+\.(pdf|jpg|jpeg|png)$/', $path) && Storage::disk('documents')->exists($path), 404);

        return Storage::disk('documents')->download($path, 'bukti-'.$kind.'-'.$record.'.'.pathinfo($path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
