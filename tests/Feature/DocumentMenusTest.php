<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentMenusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Storage::fake('documents');
        $this->travelTo(Carbon::parse('2026-10-03 23:30:00', 'Asia/Jakarta')->utc());
    }

    private function document(Application $application, DocumentType $type, array $extra = []): CandidateDocument
    {
        $path = UploadedFile::fake()->create('document.pdf', 10, 'application/pdf')->store('', 'documents');

        return CandidateDocument::create(['candidate_id' => $application->candidate_id, 'application_id' => $application->id, 'document_type_id' => $type->id, 'status' => 'uploaded', 'version' => 1, 'file_path' => $path, ...$extra]);
    }

    private function context(): array
    {
        $application = Application::factory()->create();
        $type = DocumentType::factory()->create(['name' => 'Paspor Kandidat']);
        $application->vacancy->documentRequirements()->create(['document_type_id' => $type->id, 'is_required' => true]);

        return [$application, $type];
    }

    public function test_verification_lists_only_latest_version_and_supports_search_review_and_filters(): void
    {
        [$application, $type] = $this->context();
        $old = $this->document($application, $type, ['document_number' => 'OLD-NUMBER']);
        $latest = $this->document($application, $type, ['version' => 2, 'document_number' => 'LATEST-NUMBER']);
        $this->get(route('document-verifications.index'))->assertOk()->assertSee('LATEST-NUMBER')->assertDontSee('OLD-NUMBER')->assertSee('Bagian Dokumen')->assertViewHas('documents', fn ($rows) => $rows->total() === 1);
        $this->get(route('document-verifications.index', ['search' => $application->candidate->candidate_code]))->assertOk()->assertViewHas('documents', fn ($rows) => $rows->total() === 1);
        $this->from(route('document-verifications.index'))->patch(route('documents.review', $latest), ['status' => 'reviewed'])->assertSessionHasNoErrors()->assertRedirect(route('document-verifications.index'));
        $this->get(route('document-verifications.index', ['status' => 'uploaded']))->assertViewHas('documents', fn ($rows) => $rows->total() === 0);
        $this->get(route('document-verifications.index'))->assertViewHas('documents', fn ($rows) => $rows->total() === 1);
        $this->get(route('document-verifications.index', ['status' => 'reviewed']))->assertOk()->assertSee('Verifikasi')->assertViewHas('documents', fn ($rows) => $rows->first()->id === $latest->id);
        $this->patch(route('documents.review', $old), ['status' => 'reviewed'])->assertSessionHasErrors('status');
        $this->get(route('document-verifications.index', ['status' => 'invalid']))->assertSessionHasErrors('status');
    }

    public function test_missing_required_documents_respect_shared_fallback_latest_version_and_application_scope(): void
    {
        [$application, $type] = $this->context();
        $sameCandidate = Application::factory()->create(['candidate_id' => $application->candidate_id, 'vacancy_id' => $application->vacancy_id]);
        $other = Application::factory()->create(['vacancy_id' => $application->vacancy_id]);
        $optional = DocumentType::factory()->create(['name' => 'Dokumen Opsional']);
        $application->vacancy->documentRequirements()->create(['document_type_id' => $optional->id, 'is_required' => false]);
        $this->get(route('missing-documents.index'))->assertOk()->assertSee('Paspor Kandidat')->assertDontSee('Dokumen Opsional')->assertViewHas('applications', fn ($rows) => $rows->total() === 3);
        $this->document($application, $type, ['application_id' => null, 'status' => 'verified']);
        $this->get(route('missing-documents.index'))->assertOk()->assertViewHas('applications', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $other->id);
        $this->document($application, $type, ['status' => 'rejected']);
        $this->get(route('missing-documents.index'))->assertViewHas('applications', fn ($rows) => $rows->total() === 2 && ! $rows->pluck('id')->contains($sameCandidate->id));
        $this->document($application, $type, ['version' => 2, 'status' => 'uploaded']);
        $this->get(route('missing-documents.index', ['search' => $application->application_code]))->assertOk()->assertViewHas('applications', fn ($rows) => $rows->total() === 0);
        $this->document($application, $type, ['version' => 3, 'expires_at' => '2026-10-02']);
        $this->get(route('missing-documents.index', ['search' => $application->application_code]))->assertViewHas('applications', fn ($rows) => $rows->total() === 1);
    }

    public function test_expiry_uses_jakarta_date_and_excludes_obsolete_versions(): void
    {
        [$application, $type] = $this->context();
        $this->document($application, $type, ['expires_at' => '2026-10-02', 'document_number' => 'OLD-EXPIRED']);
        $this->document($application, $type, ['version' => 2, 'expires_at' => '2026-10-03', 'document_number' => 'EXPIRES-TODAY']);
        $other = Application::factory()->create();
        $this->document($other, $type, ['expires_at' => '2026-10-02', 'document_number' => 'EXPIRED-LATEST']);
        $this->get(route('expired-documents.index'))->assertOk()->assertSee('EXPIRED-LATEST')->assertDontSee('OLD-EXPIRED')->assertDontSee('EXPIRES-TODAY')->assertViewHas('documents', fn ($rows) => $rows->total() === 1);
        $this->get(route('expired-documents.index', ['period' => 'expiring']))->assertOk()->assertSee('EXPIRES-TODAY')->assertDontSee('EXPIRED-LATEST')->assertViewHas('documents', fn ($rows) => $rows->total() === 1);
        $this->get(route('expired-documents.index', ['period' => ['bad']]))->assertSessionHasErrors('period');
    }

    public function test_original_receipt_transfer_return_and_private_evidence_history(): void
    {
        [$application, $type] = $this->context();
        $document = $this->document($application, $type);
        $this->get(route('document-custodies.index'))->assertOk()->assertSee('Paspor Kandidat');
        $data = ['candidate_document_id' => $document->id, 'holder' => 'Petugas A', 'location' => 'Lemari A', 'evidence' => UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf')];
        $this->post(route('document-custodies.store'), $data)->assertSessionHasNoErrors();
        $custody = DB::table('original_document_custodies')->first();
        $this->assertSame($application->id, $custody->application_id);
        $history = DB::table('document_custody_histories')->first();
        $this->assertSame(auth()->id(), $history->performed_by);
        Storage::disk('documents')->assertExists($history->evidence_file);
        $this->get(route('document-custodies.evidence', $history->id))->assertOk()->assertDownload()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('document-custodies.show', $custody->id))->assertOk()->assertSee('Petugas A')->assertSee('Penerimaan');
        $this->patch(route('document-custodies.update', $custody->id), ['action' => 'transfer', 'holder' => 'Petugas B', 'location' => 'Lemari B'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('original_document_custodies', ['id' => $custody->id, 'status' => 'transferred', 'current_holder' => 'Petugas B']);
        $this->assertDatabaseHas('document_custody_histories', ['custody_id' => $custody->id, 'action' => 'transfer', 'from_holder' => 'Petugas A', 'to_holder' => 'Petugas B']);
        $this->patch(route('document-custodies.update', $custody->id), ['action' => 'return', 'holder' => 'Kandidat', 'location' => 'Diserahkan ke kandidat'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('document_custody_histories', 3);
        $this->get(route('document-custodies.show', $custody->id))->assertOk()->assertSee('Dokumen telah dikembalikan.');
        $this->patch(route('document-custodies.update', $custody->id), ['action' => 'transfer', 'holder' => 'X', 'location' => 'Y'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('document_custody_histories', 3);
        $this->post(route('document-custodies.store'), ['candidate_document_id' => $document->id, 'holder' => 'Petugas C', 'location' => 'Lemari C'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('original_document_custodies', 2);
        $this->get(route('document-custodies.index', ['search' => $application->candidate->candidate_code, 'status' => 'returned']))->assertOk()->assertViewHas('custodies', fn ($rows) => $rows->total() === 1);
    }

    public function test_duplicate_original_receipts_and_stale_versions_are_rejected_and_evidence_is_cleaned_up(): void
    {
        [$application, $type] = $this->context();
        $old = $this->document($application, $type);
        $latest = $this->document($application, $type, ['version' => 2]);
        $base = ['candidate_document_id' => $latest->id, 'holder' => 'Petugas', 'location' => 'Lemari'];
        $this->post(route('document-custodies.store'), array_replace($base, ['candidate_document_id' => $old->id]))->assertSessionHasErrors('candidate_document_id');
        $this->post(route('document-custodies.store'), $base)->assertSessionHasNoErrors();
        $files = Storage::disk('documents')->allFiles();
        $this->post(route('document-custodies.store'), $base + ['evidence' => UploadedFile::fake()->create('duplicate.pdf', 10, 'application/pdf')])->assertSessionHasErrors('candidate_document_id');
        $this->assertSame($files, Storage::disk('documents')->allFiles());
        $newer = $this->document($application, $type, ['version' => 3]);
        $this->post(route('document-custodies.store'), array_replace($base, ['candidate_document_id' => $newer->id]))->assertSessionHasErrors('candidate_document_id');
        $this->assertDatabaseCount('original_document_custodies', 1);
        $this->assertDatabaseCount('document_custody_histories', 1);
        $this->get(route('document-custodies.show', 9999))->assertNotFound();
        $this->get(route('document-custodies.evidence', 9999))->assertNotFound();
    }

    public function test_document_menu_routes_and_custody_writes_require_authentication(): void
    {
        foreach (['menu-4-1' => 'document-verifications.index', 'menu-4-2' => 'missing-documents.index', 'menu-4-3' => 'expired-documents.index', 'menu-4-4' => 'document-custodies.index'] as $key => $route) {
            $this->get('/modul/'.$key)->assertRedirect(route($route, ['menu' => $key]));
        }
        auth()->logout();
        foreach (['document-verifications.index', 'missing-documents.index', 'expired-documents.index', 'document-custodies.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->post(route('document-custodies.store'), [])->assertRedirect(route('login'));
        $this->patch(route('document-custodies.update', 1), [])->assertRedirect(route('login'));
        $this->get(route('document-custodies.evidence', 1))->assertRedirect(route('login'));
        $this->assertDatabaseCount('original_document_custodies', 0);
    }

    public function test_upload_retry_preserves_values_only_for_failed_document_type(): void
    {
        [$application, $type] = $this->context();
        $otherType = DocumentType::factory()->create();
        $application->vacancy->documentRequirements()->create(['document_type_id' => $otherType->id, 'is_required' => true]);
        $this->from(route('documents.show', $application))->post(route('documents.upload', $application), ['document_type_id' => $type->id, 'document_number' => 'RETRY-123', 'issued_at' => '2026-10-01', 'expires_at' => '2026-10-03'])->assertSessionHasErrors('file');
        $response = $this->get(route('documents.show', $application))->assertOk()->assertSee('value="RETRY-123"', false)->assertSee('value="2026-10-01"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'value="RETRY-123"'));
    }
}
