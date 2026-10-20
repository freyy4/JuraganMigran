<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\CandidateDocument;
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

class DocumentCustodyController extends Controller
{
    public const STATES = ['NOT_RECEIVED' => 'Belum diterima', 'RECEIVED_BY_JM' => 'Diterima JM', 'HELD_BY_JM' => 'Disimpan JM', 'HANDED_TO_PT' => 'Diserahkan ke PT', 'HELD_BY_PT' => 'Disimpan PT', 'TEMPORARILY_RELEASED' => 'Keluar sementara', 'RETURN_REQUESTED' => 'Pengembalian diminta', 'RETURN_APPROVED' => 'Pengembalian disetujui', 'RETURN_SCHEDULED' => 'Pengembalian dijadwalkan', 'RETURNED_TO_CPMI' => 'Dikembalikan ke CPMI', 'CLOSED' => 'Ditutup'];

    private function query(): Builder
    {
        return DB::table('original_document_custodies as custody')
            ->join('candidate_documents as document', 'document.id', '=', 'custody.candidate_document_id')
            ->join('document_types as type', 'type.id', '=', 'document.document_type_id')
            ->join('candidates as candidate', 'candidate.id', '=', 'document.candidate_id')
            ->join('persons as person', 'person.id', '=', 'candidate.person_id')
            ->leftJoin('applications as application', 'application.id', '=', 'custody.application_id')
            ->when(! collect(app(AccessPolicy::class)->policies(auth()->user(), request()->attributes->get('access_permission')))->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL'), fn ($query) => $query->whereIn('document.candidate_id', app(AccessPolicy::class)->candidateIds(auth()->user(), request()->attributes->get('access_permission'))))
            ->select('custody.*', 'document.document_number', 'document.status as digital_status', 'document.version', 'type.name as document_type', 'person.full_name', 'candidate.candidate_code', 'application.application_code');
    }

    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(['received', 'transferred', 'returned'])], 'custody_state' => ['nullable', Rule::in(array_keys(self::STATES))]]);
        $query = $this->query();
        if ($filters['custody_state'] ?? null) {
            $query->where('custody.custody_state', $filters['custody_state']);
        }
        if ($filters['status'] ?? null) {
            $query->where('custody.status', $filters['status']);
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($query) => $query->where('person.full_name', 'like', '%'.$search.'%')->orWhere('candidate.candidate_code', 'like', '%'.$search.'%')->orWhere('application.application_code', 'like', '%'.$search.'%')->orWhere('document.document_number', 'like', '%'.$search.'%'));
        }
        $documents = CandidateDocument::latestVersions()->with(['documentType', 'candidate.person' => fn ($query) => $query->withTrashed(), 'application'])->whereNotExists(function ($custody): void {
            $custody->selectRaw('1')->from('original_document_custodies as active_custody')->join('candidate_documents as held_document', 'held_document.id', '=', 'active_custody.candidate_document_id')->where('active_custody.status', '!=', 'returned')->whereColumn('held_document.candidate_id', 'candidate_documents.candidate_id')->whereColumn('held_document.document_type_id', 'candidate_documents.document_type_id');
        })->orderByDesc('id')->get();

        return view('documents.originals', ['custodies' => $query->orderByDesc('custody.id')->paginate(15)->withQueryString(), 'documents' => $documents]);
    }

    public function show(int $custody): View
    {
        $record = $this->query()->where('custody.id', $custody)->first();
        abort_unless($record, 404);
        $histories = DB::table('document_custody_histories as history')->leftJoin('users', 'users.id', '=', 'history.performed_by')->where('custody_id', $custody)->select('history.*', 'users.name as performer')->orderByDesc('history.id')->paginate(15);

        return view('documents.custody', ['custody' => $record, 'histories' => $histories]);
    }

    private function data(Request $request, bool $receipt): array
    {
        return $request->validate([
            'candidate_document_id' => $receipt ? ['required', 'integer', Rule::exists('candidate_documents', 'id')->whereNull('deleted_at')] : ['prohibited'],
            'action' => $receipt ? ['prohibited'] : ['required', Rule::in(['transfer', 'return', 'request_return', 'approve_return', 'schedule_return', 'hold_jm', 'handover_pt', 'hold_pt', 'release', 'close'])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'case_reference' => ['nullable', 'string', 'max:255'],
            'expected_return_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'holder' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], ['required' => ':attribute wajib diisi.', 'exists' => 'Dokumen tidak tersedia.', 'integer' => 'Dokumen tidak valid.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute melebihi batas :max.', 'in' => 'Tindakan tidak valid.', 'prohibited' => ':attribute tidak dapat diganti.', 'file' => 'Bukti tidak valid.', 'mimes' => 'Bukti harus berupa PDF, JPG, atau PNG.'], ['candidate_document_id' => 'Dokumen', 'holder' => 'Pemegang / penerima', 'location' => 'Lokasi', 'evidence' => 'Bukti serah-terima', 'action' => 'Tindakan']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->data($request, true);
        $path = $request->file('evidence')?->store('', 'documents');
        try {
            $custody = DB::transaction(function () use ($data, $request, $path): int {
                $document = CandidateDocument::findOrFail($data['candidate_document_id']);
                Candidate::withTrashed()->whereKey($document->candidate_id)->lockForUpdate()->firstOrFail();
                $document = CandidateDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
                if (! CandidateDocument::latestVersions()->whereKey($document->id)->exists()) {
                    throw ValidationException::withMessages(['candidate_document_id' => 'Versi dokumen berubah. Pilih versi terbaru.']);
                }
                $alreadyHeld = DB::table('original_document_custodies as custody')->join('candidate_documents as document', 'document.id', '=', 'custody.candidate_document_id')->where('document.candidate_id', $document->candidate_id)->where('document.document_type_id', $document->document_type_id)->where('custody.status', '!=', 'returned')->exists();
                if ($alreadyHeld) {
                    throw ValidationException::withMessages(['candidate_document_id' => 'Dokumen asli masih tercatat dalam penyimpanan. Gunakan serah-terima atau pengembalian pada catatan tersebut.']);
                }
                $id = DB::table('original_document_custodies')->insertGetId(['candidate_document_id' => $document->id, 'application_id' => $document->application_id, 'status' => 'received', 'custody_state' => 'RECEIVED_BY_JM', 'current_holder' => $data['holder'], 'current_location' => $data['location'], 'received_at' => now(), 'received_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                $this->history($id, 'receive', null, $data, $request, $path);

                return $id;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('documents')->delete($path);
            }
            throw $exception;
        }

        return to_route('document-custodies.show', $custody)->with('success', 'Penerimaan dokumen asli berhasil dicatat.');
    }

    public function update(Request $request, int $custody): RedirectResponse
    {
        $data = $this->data($request, false);
        $path = $request->file('evidence')?->store('', 'documents');
        try {
            DB::transaction(function () use ($data, $request, $path, $custody): void {
                $record = DB::table('original_document_custodies')->where('id', $custody)->lockForUpdate()->first();
                abort_unless($record, 404);
                if ($record->custody_state === 'CLOSED' || ($record->custody_state === 'RETURNED_TO_CPMI' && $data['action'] !== 'close')) {
                    throw ValidationException::withMessages(['action' => 'Dokumen sudah dikembalikan. Catat penerimaan baru jika dokumen diserahkan kembali.']);
                }
                $states = ['transfer' => 'TEMPORARILY_RELEASED', 'return' => 'RETURNED_TO_CPMI', 'request_return' => 'RETURN_REQUESTED', 'approve_return' => 'RETURN_APPROVED', 'schedule_return' => 'RETURN_SCHEDULED', 'hold_jm' => 'HELD_BY_JM', 'handover_pt' => 'HANDED_TO_PT', 'hold_pt' => 'HELD_BY_PT', 'release' => 'TEMPORARILY_RELEASED', 'close' => 'CLOSED'];
                $state = $states[$data['action']];
                if ($data['action'] === 'close' && $record->custody_state !== 'RETURNED_TO_CPMI') {
                    throw ValidationException::withMessages(['action' => 'Kembalikan dokumen ke CPMI sebelum menutup catatan.']);
                }
                if ($data['action'] === 'hold_pt' && ! in_array($record->custody_state, ['HANDED_TO_PT', 'HELD_BY_PT'], true)) {
                    throw ValidationException::withMessages(['action' => 'Catat penyerahan ke PT sebelum menyatakan disimpan PT.']);
                }
                if ($data['action'] === 'approve_return') {
                    abort_unless($request->user()->hasPermission('document.exception.approve') && $record->custody_state === 'RETURN_REQUESTED', 403);
                }
                if ($data['action'] === 'schedule_return' && $record->custody_state !== 'RETURN_APPROVED') {
                    throw ValidationException::withMessages(['action' => 'Setujui pengembalian sebelum menjadwalkan.']);
                }
                if (in_array($data['action'], ['request_return', 'approve_return', 'schedule_return', 'handover_pt', 'release', 'close'], true) && ! trim($data['reason'] ?? '')) {
                    throw ValidationException::withMessages(['reason' => 'Alasan tindakan wajib dicatat.']);
                }
                $data['from_status'] = $record->custody_state;
                $data['to_status'] = $state;
                $expected = empty($data['expected_return_at']) ? null : Carbon::createFromFormat('Y-m-d\TH:i', $data['expected_return_at'], 'Asia/Jakarta')->timezone(config('app.timezone'));
                DB::table('original_document_custodies')->where('id', $custody)->update(['status' => in_array($data['action'], ['return', 'close'], true) ? 'returned' : ($data['action'] === 'hold_jm' ? 'received' : 'transferred'), 'custody_state' => $state, 'expected_return_at' => $expected, 'current_holder' => $data['holder'], 'current_location' => $data['location'], 'updated_at' => now()]);
                $this->history($custody, $data['action'], $record->current_holder, $data, $request, $path);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('documents')->delete($path);
            }
            throw $exception;
        }

        return to_route('document-custodies.show', $custody)->with('success', 'Serah-terima dokumen berhasil dicatat.');
    }

    private function history(int $custody, string $action, ?string $from, array $data, Request $request, ?string $path): void
    {
        DB::table('document_custody_histories')->insert(['custody_id' => $custody, 'from_status' => $data['from_status'] ?? null, 'to_status' => $data['to_status'] ?? 'RECEIVED_BY_JM', 'reason' => $data['reason'] ?? null, 'case_reference' => $data['case_reference'] ?? null, 'action' => $action, 'from_holder' => $from, 'to_holder' => $data['holder'], 'location' => $data['location'], 'evidence_file' => $path, 'performed_by' => $request->user()->id, 'performed_at' => now()]);
    }

    public function downloadEvidence(int $history): StreamedResponse
    {
        $record = DB::table('document_custody_histories')->where('id', $history)->first();
        $path = $record?->evidence_file;
        abort_unless($path && preg_match('/^[a-zA-Z0-9_-]+\.(pdf|jpg|jpeg|png)$/', $path) && Storage::disk('documents')->exists($path), 404);

        return Storage::disk('documents')->download($path, 'bukti-serah-terima-'.$history.'.'.pathinfo($path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
