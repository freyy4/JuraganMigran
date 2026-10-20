<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TrainingEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrainingActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(Carbon::parse('2026-10-03 12:00', 'Asia/Jakarta')->utc());
        Storage::fake('documents');
    }

    private function payload(TrainingEnrollment $enrollment): array
    {
        return ['training_enrollment_id' => $enrollment->id, 'leave_type' => 'permission', 'start_at' => '2026-10-03T09:00', 'end_at' => '2026-10-03T15:00', 'reason' => 'Keperluan keluarga'];
    }

    public function test_leave_submission_approval_private_evidence_and_return_confirmation(): void
    {
        $enrollment = TrainingEnrollment::factory()->create(['status' => 'ongoing']);
        $data = $this->payload($enrollment) + ['evidence' => UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf')];
        $this->get(route('training-leaves.index'))->assertOk()->assertSee('Bagian Pelatihan');
        $this->post(route('training-leaves.store'), $data)->assertSessionHasNoErrors();
        $leave = DB::table('training_leave_requests')->first();
        $this->assertSame('2026-10-03 02:00:00', $leave->start_at);
        $this->get(route('training-leaves.evidence', $leave->id))->assertOk()->assertDownload();
        $this->patch(route('training-leaves.action', $leave->id), ['action' => 'return'])->assertSessionHasErrors('action');
        $this->patch(route('training-leaves.action', $leave->id), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('training_leave_requests', ['id' => $leave->id, 'approval_status' => 'approved', 'approved_by' => auth()->id()]);
        $this->get(route('training-progress.index', ['batch_id' => $enrollment->batch_id]))->assertOk()->assertSee('Belum Konfirmasi Kembali');
        $this->patch(route('training-leaves.action', $leave->id), ['action' => 'return'])->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('training_leave_requests')->find($leave->id)->return_confirmed_at);
        $this->patch(route('training-leaves.action', $leave->id), ['action' => 'return'])->assertSessionHasErrors('action');
        $this->get(route('training-progress.index', ['batch_id' => $enrollment->batch_id]))->assertDontSee('Belum Konfirmasi Kembali');
        $this->assertSame('ongoing', $enrollment->fresh()->status);
    }

    public function test_overlap_terminal_participants_validation_and_failed_upload_cleanup(): void
    {
        $enrollment = TrainingEnrollment::factory()->create();
        $data = $this->payload($enrollment);
        $this->post(route('training-leaves.store'), array_replace($data, ['end_at' => '2026-10-02T09:00']))->assertSessionHasErrors('end_at');
        $this->post(route('training-leaves.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('training-leaves.store'), $data + ['evidence' => UploadedFile::fake()->create('extra.pdf', 10, 'application/pdf')])->assertSessionHasErrors('start_at');
        $this->assertSame([], Storage::disk('documents')->allFiles());
        $leave = DB::table('training_leave_requests')->first();
        $this->patch(route('training-leaves.action', $leave->id), ['action' => 'reject'])->assertSessionHasNoErrors();
        $this->patch(route('training-leaves.action', $leave->id), ['action' => 'approve'])->assertSessionHasErrors('action');
        $this->post(route('training-leaves.store'), $data)->assertSessionHasNoErrors();
        $enrollment->update(['status' => 'completed']);
        $this->post(route('training-leaves.store'), $data)->assertSessionHasErrors('training_enrollment_id');
        $pending = DB::table('training_leave_requests')->where('approval_status', 'pending')->first();
        $this->patch(route('training-leaves.action', $pending->id), ['action' => 'approve'])->assertSessionHasErrors('action');
        $this->get(route('training-leaves.evidence', 9999))->assertNotFound();
    }

    public function test_future_leave_cannot_be_confirmed_and_history_cannot_be_reassigned(): void
    {
        $enrollment = TrainingEnrollment::factory()->create();
        $data = array_replace($this->payload($enrollment), ['start_at' => '2026-10-04T09:00', 'end_at' => '2026-10-04T15:00']);
        $this->post(route('training-leaves.store'), $data)->assertSessionHasNoErrors();
        $leave = DB::table('training_leave_requests')->first();
        $this->patch(route('training-leaves.action', $leave->id), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->patch(route('training-leaves.action', $leave->id), ['action' => 'return'])->assertSessionHasErrors('action');
        $other = TrainingEnrollment::factory()->create();
        $this->put(route('training.update', $enrollment), ['application_id' => $other->application_id, 'batch_id' => $enrollment->batch_id, 'status' => 'scheduled'])->assertSessionHasErrors('application_id');
        $this->assertSame($enrollment->application_id, $enrollment->fresh()->application_id);
    }

    public function test_progress_counts_filters_search_and_lpk_prefill(): void
    {
        $enrollment = TrainingEnrollment::factory()->create(['status' => 'ongoing']);
        TrainingEnrollment::factory()->create(['batch_id' => $enrollment->batch_id, 'status' => 'completed']);
        TrainingEnrollment::factory()->create();
        $this->get(route('training-progress.index', ['batch_id' => $enrollment->batch_id]))->assertOk()->assertViewHas('summary', fn ($summary) => $summary['Berlangsung'] === 1 && $summary['Selesai'] === 1)->assertViewHas('enrollments', fn ($rows) => $rows->total() === 2);
        $this->get(route('training-progress.index', ['search' => $enrollment->application->candidate->candidate_code]))->assertViewHas('enrollments', fn ($rows) => $rows->total() === 1);
        $this->get(route('training-progress.index', ['status' => 'invalid']))->assertSessionHasErrors('status');
        $company = Company::factory()->create(['type' => 'lpk']);
        $this->get(route('training-batches.create', ['company_id' => $company->id]))->assertOk()->assertViewHas('batch', fn ($batch) => $batch->company_id === $company->id);
        $this->get(route('companies.create', ['type' => 'lpk']))->assertOk()->assertViewHas('company', fn ($company) => $company->type === 'lpk')->assertSee('Bagian Pelatihan');
        $this->get(route('companies.show', $company))->assertOk()->assertSee('Tambah Angkatan');
    }

    public function test_training_activity_routes_require_login_and_old_menu_links_redirect(): void
    {
        $this->get('/modul/menu-5-3')->assertRedirect(route('training-leaves.index', ['menu' => 'menu-5-3']));
        $this->get('/modul/menu-5-4')->assertRedirect(route('training-progress.index', ['menu' => 'menu-5-4']));
        auth()->logout();
        foreach (['training-leaves.index', 'training-progress.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->post(route('training-leaves.store'), [])->assertRedirect(route('login'));
        $this->patch(route('training-leaves.action', 1), ['action' => 'approve'])->assertRedirect(route('login'));
        $this->get(route('training-leaves.evidence', 1))->assertRedirect(route('login'));
        $this->assertDatabaseCount('training_leave_requests', 0);
    }
}
