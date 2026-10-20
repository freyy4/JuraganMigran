<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\HealthInterview;
use App\Models\Interview;
use App\Models\MedicalEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SelectionTest extends TestCase
{
    use RefreshDatabase;

    private function question(Application $application, bool $required = true): int
    {
        return DB::table('health_questions')->insertGetId(['vacancy_id' => $application->vacancy_id, 'question' => 'Pertanyaan untuk lowongan', 'category' => 'Administrasi', 'is_required' => $required, 'is_active' => true, 'sequence' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_interview_search_by_candidate_code_and_status_filter(): void
    {
        $this->actingAs(User::factory()->create());
        $interview = Interview::factory()->create(['status' => 'completed']);
        Interview::factory()->create();
        $this->get(route('interviews.index', ['tab' => 'all', 'search' => $interview->application->candidate->candidate_code, 'status' => 'completed']))->assertOk()->assertViewHas('interviews', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $interview->id)->assertSee('Bagian Seleksi');
        $this->get(route('interviews.index', ['tab' => 'all', 'status' => 'invalid']))->assertSessionHasErrors('status');
        $this->get('/modul/menu-3-2')->assertRedirect(route('health-screenings.index', ['menu' => 'menu-3-2']));
        $this->get('/modul/menu-3-3')->assertRedirect(route('medical-events.index', ['menu' => 'menu-3-3']));
    }

    public function test_selection_pages_require_login_and_invalid_module_returns_not_found(): void
    {
        $application = Application::factory()->create();
        foreach (['health-screenings.index', 'medical-events.index', 'medical-events.create'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->put(route('health-screenings.update', $application), ['status' => 'pending'])->assertRedirect(route('login'));
        $this->post(route('medical-events.store'), [])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/modul/menu-does-not-exist')->assertNotFound();
    }

    public function test_health_draft_completion_and_application_isolation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $application = Application::factory()->create();
        $other = Application::factory()->create(['vacancy_id' => $application->vacancy_id]);
        $question = $this->question($application);
        $second = $this->question($application, false);
        HealthInterview::factory()->create(['application_id' => $application->id]);
        $this->get(route('health-screenings.index', ['search' => $application->application_code]))->assertOk()->assertViewHas('applications', fn ($rows) => $rows->total() === 1);
        $this->get(route('health-screenings.show', $application))->assertOk()->assertSee('Pertanyaan untuk lowongan');
        $data = ['status' => 'pending', 'answers' => [$question => ['answer' => 'Sudah', 'notes' => 'Catatan administrasi']]];
        $this->put(route('health-screenings.update', $application), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('health_interviews', ['application_id' => $application->id, 'status' => 'pending', 'completeness_percentage' => 50, 'completed_by' => null]);
        $data['status'] = 'completed';
        $this->put(route('health-screenings.update', $application), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('health_interviews', ['application_id' => $application->id, 'status' => 'completed', 'completed_by' => $user->id]);
        $this->assertDatabaseMissing('health_interviews', ['application_id' => $other->id]);
        $this->get(route('health-screenings.show', $application))->assertOk()->assertSee('Catatan administrasi');
        $data['status'] = 'pending';
        $this->put(route('health-screenings.update', $application), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('health_interviews', ['application_id' => $application->id, 'completed_at' => null, 'completed_by' => null]);
        $this->assertDatabaseCount('health_interviews', 1);
        $this->assertSame('active', $application->fresh()->status);
    }

    public function test_health_rejects_missing_required_answers_foreign_questions_and_empty_completion(): void
    {
        $this->actingAs(User::factory()->create());
        $application = Application::factory()->create();
        $this->put(route('health-screenings.update', $application), ['status' => 'completed'])->assertForbidden();
        HealthInterview::factory()->create(['application_id' => $application->id]);
        $question = $this->question($application);
        $foreign = $this->question(Application::factory()->create());
        $this->put(route('health-screenings.update', $application), ['status' => 'completed', 'answers' => [$question => ['answer' => '']]])->assertSessionHasErrors('answers.'.$question.'.answer');
        $this->put(route('health-screenings.update', $application), ['status' => 'pending', 'answers' => [$foreign => ['answer' => 'Interdit']]])->assertSessionHasErrors('answers');
        $this->put(route('health-screenings.update', $application), ['status' => 'pending', 'answers' => [$question => ['answer' => ['bad']]]])->assertSessionHasErrors('answers.'.$question.'.answer');
        $this->assertDatabaseCount('health_interviews', 1);
        $this->assertDatabaseCount('health_answers', 0);
    }

    public function test_cs_workflow_cannot_add_generic_questions_or_reopen_completed_legacy_records(): void
    {
        $this->actingAs(User::factory()->create());
        $interview = HealthInterview::factory()->create(['status' => 'completed', 'completeness_percentage' => 100, 'completed_at' => now()]);
        $this->post('/pemeriksaan-awal-kesehatan/'.$interview->application_id.'/pertanyaan', ['question' => 'Pertanyaan bebas', 'category' => 'Umum', 'sequence' => 1, 'is_required' => true])->assertNotFound();
        $this->assertSame('completed', $interview->fresh()->status);
        $this->assertNotNull($interview->fresh()->completed_at);
        $this->assertDatabaseMissing('health_questions', ['question' => 'Pertanyaan bebas']);
        $this->get(route('health-screenings.show', $interview->application))->assertOk()->assertDontSee('Tambah Pertanyaan');
    }

    public function test_mcu_schedule_and_result_use_jakarta_time_and_clinic_data(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $application = Application::factory()->create();
        $clinic = Company::factory()->create(['type' => 'clinic', 'status' => 'active']);
        $this->get(route('medical-events.create'))->assertOk()->assertSee($clinic->name);
        $data = ['application_id' => $application->id, 'clinic_id' => $clinic->id, 'scheduled_at' => '2026-10-05T09:00', 'status' => 'scheduled'];
        $this->post(route('medical-events.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('medical-events.index'));
        $event = MedicalEvent::firstOrFail();
        $this->assertSame('2026-10-05 02:00', $event->scheduled_at->setTimezone('UTC')->format('Y-m-d H:i'));
        $this->assertSame($user->id, $event->created_by);
        $this->get(route('medical-events.edit', $event))->assertOk()->assertSee('2026-10-05T09:00');
        $data += ['completed_at' => '2026-10-05T11:00', 'clearance_status' => 'fit', 'expires_at' => '2026-10-05'];
        $data['status'] = 'completed';
        $this->put(route('medical-events.update', $event), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('medical_events', ['id' => $event->id, 'status' => 'completed', 'clearance_status' => 'fit']);
        $this->get(route('medical-events.index', ['search' => $application->application_code, 'status' => 'completed']))->assertOk()->assertSee('Fit')->assertSee($clinic->name)->assertViewHas('events', fn ($rows) => $rows->total() === 1);
        $data['status'] = 'cancelled';
        $this->put(route('medical-events.update', $event), $data)->assertSessionHasNoErrors();
        $this->assertNull($event->fresh()->clearance_status);
        $this->assertNull($event->fresh()->completed_at);
        $this->assertSame('active', $application->fresh()->status);
    }

    public function test_mcu_validation_prevents_wrong_clinic_lamaran_and_incomplete_results(): void
    {
        $this->actingAs(User::factory()->create());
        $event = MedicalEvent::factory()->create();
        $base = ['application_id' => $event->application_id, 'clinic_id' => $event->clinic_id, 'scheduled_at' => '2026-10-05T09:00', 'status' => 'completed'];
        $this->put(route('medical-events.update', $event), $base)->assertSessionHasErrors(['completed_at', 'clearance_status']);
        $other = Application::factory()->create();
        $this->put(route('medical-events.update', $event), array_replace($base, ['application_id' => $other->id]))->assertSessionHasErrors('application_id');
        $sponsor = Company::factory()->create(['type' => 'sponsor']);
        $this->post(route('medical-events.store'), array_replace($base, ['clinic_id' => $sponsor->id, 'status' => 'scheduled']))->assertSessionHasErrors('clinic_id');
        $inactive = Company::factory()->create(['type' => 'clinic', 'status' => 'inactive']);
        $this->post(route('medical-events.store'), array_replace($base, ['clinic_id' => $inactive->id, 'status' => 'scheduled']))->assertSessionHasErrors('clinic_id');
        $this->put(route('medical-events.update', $event), $base + ['completed_at' => '2026-10-05T08:00', 'clearance_status' => 'fit'])->assertSessionHasErrors('completed_at');
        $this->put(route('medical-events.update', $event), $base + ['completed_at' => '2026-10-05T10:00', 'clearance_status' => 'fit', 'expires_at' => '2026-10-04'])->assertSessionHasErrors('expires_at');
        $this->assertSame('scheduled', $event->fresh()->status);
    }
}
