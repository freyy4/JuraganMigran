<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Storage::fake('documents');
    }

    private function context(): array
    {
        $application = Application::factory()->create();
        $type = DocumentType::factory()->create(['name' => 'Dokumen Dinamis']);
        $application->vacancy->documentRequirements()->create(['document_type_id' => $type->id, 'is_required' => true]);

        return [$application, $type];
    }

    private function upload(Application $application, DocumentType $type, array $extra = []): void
    {
        $this->post(route('documents.upload', $application), ['document_type_id' => $type->id, 'file' => UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf'), ...$extra])->assertSessionHasNoErrors();
    }

    public function test_upload_review_verify_reject_reupload_and_private_download(): void
    {
        [$application,$type] = $this->context();
        $this->get(route('documents.show', $application))->assertOk()->assertSee('Dokumen Dinamis')->assertSee('Dokumen wajib belum diunggah.');
        $this->upload($application, $type);
        $document = CandidateDocument::firstOrFail();
        Storage::disk('documents')->assertExists($document->file_path);
        $this->assertSame(auth()->id(), $document->uploaded_by);
        $this->get(route('documents.download', $document))->assertOk()->assertDownload();
        $this->patch(route('documents.review', $document), ['status' => 'verified'])->assertSessionHasErrors('status');
        $this->patch(route('documents.review', $document), ['status' => 'reviewed'])->assertSessionHasNoErrors();
        $this->patch(route('documents.review', $document), ['status' => 'verified'])->assertSessionHasNoErrors();
        $this->assertSame(auth()->id(), $document->fresh()->verified_by);
        $this->assertNotNull($document->fresh()->verified_at);
        $this->patch(route('documents.review', $document), ['status' => 'rejected'])->assertSessionHasErrors('rejection_reason');
        $this->patch(route('documents.review', $document), ['status' => 'rejected', 'rejection_reason' => 'File tidak terbaca'])->assertSessionHasNoErrors();
        $this->upload($application, $type);
        $latest = CandidateDocument::orderByDesc('id')->firstOrFail();
        $this->assertSame(2, $latest->version);
        $this->get(route('documents.index'))->assertOk()->assertViewHas('summary', fn ($summary) => $summary['Menunggu Review'] === 1 && $summary['Ditolak'] === 0);
        $this->assertSame('uploaded', $latest->status);
        $this->assertNull($latest->verified_by);
        Storage::disk('documents')->assertExists($document->file_path);
        $this->get(route('documents.show', $application))->assertOk()->assertSee('Diunggah')->assertDontSee('File tidak terbaca');
        $this->patch(route('documents.review', $document), ['status' => 'reviewed'])->assertSessionHasErrors('status');
        auth()->logout();
        $this->get(route('documents.download', $latest))->assertRedirect(route('login'));
        $this->get('/storage/'.$latest->file_path)->assertForbidden();
    }

    public function test_summary_missing_expiry_and_application_isolation(): void
    {
        [$application,$type] = $this->context();
        $other = Application::factory()->create(['vacancy_id' => $application->vacancy_id]);
        $this->get(route('documents.index'))->assertOk()->assertViewHas('summary', fn ($summary) => $summary['Dokumen Kurang'] === 2);
        $this->upload($application, $type, ['expires_at' => now('Asia/Jakarta')->addDays(10)->toDateString()]);
        $this->get(route('documents.index'))->assertOk()->assertViewHas('summary', fn ($summary) => $summary['Dokumen Kurang'] === 1 && $summary['Menunggu Review'] === 1 && $summary['Akan Kedaluwarsa'] === 1);
        $this->get(route('documents.show', $other))->assertOk()->assertSee('Dokumen wajib belum diunggah.');
        $document = CandidateDocument::firstOrFail();
        $document->update(['expires_at' => now('Asia/Jakarta')->subDay()->toDateString()]);
        $this->get(route('documents.show', $application))->assertOk()->assertSee('Kedaluwarsa');
        $this->patch(route('documents.review', $document), ['status' => 'reviewed'])->assertSessionHasErrors('status');
        $this->get(route('documents.index'))->assertOk()->assertViewHas('summary', fn ($summary) => $summary['Dokumen Kurang'] === 2 && $summary['Menunggu Review'] === 0);
    }

    public function test_validation_and_shared_document_fallback(): void
    {
        [$application,$type] = $this->context();
        $otherType = DocumentType::factory()->create();
        $this->post(route('documents.upload', $application), ['document_type_id' => $otherType->id, 'file' => UploadedFile::fake()->create('script.html', 10, 'text/html')])->assertSessionHasErrors(['document_type_id', 'file']);
        $this->assertDatabaseCount('candidate_documents', 0);
        $this->post(route('documents.upload', $application), ['document_type_id' => $type->id, 'file' => UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf')])->assertSessionHasErrors('file');
        $path = UploadedFile::fake()->create('shared.pdf', 10, 'application/pdf')->store('', 'documents');
        CandidateDocument::create(['candidate_id' => $application->candidate_id, 'document_type_id' => $type->id, 'file_path' => $path, 'status' => 'verified']);
        $this->get(route('documents.show', $application))->assertOk()->assertSee('Terverifikasi');
        $this->get(route('documents.index'))->assertOk()->assertViewHas('summary', fn ($summary) => $summary['Dokumen Kurang'] === 0);
        $this->upload($application, $type);
        $this->get(route('documents.show', $application))->assertOk()->assertSee('Diunggah')->assertDontSee('Terverifikasi');
    }
}
