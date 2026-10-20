<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Interview;
use App\Models\InterviewQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InterviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(Carbon::parse('2026-10-03 10:00:00', 'Asia/Jakarta')->utc());
    }

    public function test_schedule_assign_edit_today_and_upcoming(): void
    {
        $application = Application::factory()->create();
        $user = User::factory()->create();
        $data = ['application_id' => $application->id, 'scheduled_at' => '2026-10-03T12:00', 'interviewer_id' => $user->id, 'type' => 'general', 'meeting_link' => 'https://example.com/meeting'];
        $this->get(route('interviews.create'))->assertOk();
        $this->post(route('interviews.store'), $data)->assertSessionHasNoErrors();
        $interview = Interview::firstOrFail();
        $this->assertSame('2026-10-03 05:00', $interview->scheduled_at->format('Y-m-d H:i'));
        $this->get(route('interviews.index'))->assertOk()->assertSee($user->name)->assertSee($application->candidate->person->full_name);
        $this->get(route('interviews.edit', $interview))->assertOk()->assertSee('2026-10-03T12:00');
        $data['scheduled_at'] = '2026-10-04T00:00';
        $this->put(route('interviews.update', $interview), $data)->assertSessionHasNoErrors();
        $this->get(route('interviews.index'))->assertOk()->assertViewHas('interviews', fn ($rows) => $rows->total() === 0);
        $this->get(route('interviews.index', ['tab' => 'upcoming', 'interviewer_id' => $user->id]))->assertOk()->assertViewHas('interviews', fn ($rows) => $rows->total() === 1);
        $this->put(route('interviews.update', $interview), [...$data, 'application_id' => Application::factory()->create()->id])->assertSessionHasErrors('application_id');
        $this->post(route('interviews.store'), [...$data, 'meeting_link' => 'javascript:alert(1)'])->assertSessionHasErrors('meeting_link');
    }

    public function test_dynamic_questions_draft_answers_required_final_and_outcomes(): void
    {
        $interview = Interview::factory()->create();
        $general = InterviewQuestion::factory()->create(['question' => 'Pertanyaan Umum', 'sequence' => 1]);
        $specific = InterviewQuestion::factory()->create(['vacancy_id' => $interview->application->vacancy_id, 'question' => 'Pertanyaan Lowongan', 'input_type' => 'boolean', 'sequence' => 2]);
        $other = InterviewQuestion::factory()->create(['vacancy_id' => Application::factory()->create()->vacancy_id, 'question' => 'Pertanyaan Lain']);
        $this->get(route('interviews.show', $interview))->assertOk()->assertSeeInOrder(['Pertanyaan Umum', 'Pertanyaan Lowongan'])->assertDontSee('Pertanyaan Lain');
        $this->put(route('interviews.answers', $interview), ['outcome' => 'continue', 'answers' => []])->assertSessionHasErrors('answers.'.$general->id.'.answer');
        $this->assertDatabaseCount('interview_answers', 0);
        $data = ['answers' => [$general->id => ['answer' => 'Jawaban kandidat'], $specific->id => ['answer' => false]]];
        $this->put(route('interviews.answers', $interview), $data)->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $interview->fresh()->status);
        $this->assertDatabaseHas('interview_answers', ['question_id' => $specific->id, 'answer' => '0']);
        foreach (array_keys(Interview::OUTCOMES) as $outcome) {
            $this->put(route('interviews.answers', $interview), [...$data, 'outcome' => $outcome])->assertSessionHasNoErrors();
            $this->assertSame($outcome, $interview->fresh()->outcome);
        }
        $this->assertSame('completed', $interview->fresh()->status);
        $this->assertDatabaseCount('interview_answers', 2);
        $this->assertSame('active', $interview->application->fresh()->status);
        $this->put(route('interviews.answers', $interview), ['answers' => [$other->id => ['answer' => 'Tidak sah']]])->assertSessionHasErrors('answers');
        $this->post(route('interviews.questions', $interview), ['question' => 'Pertanyaan Tambahan', 'input_type' => 'text', 'sequence' => 3, 'is_required' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('interview_questions', ['question' => 'Pertanyaan Tambahan', 'vacancy_id' => $interview->application->vacancy_id]);
    }

    public function test_pagination_search_and_authentication(): void
    {
        $interview = Interview::factory()->create();
        Interview::factory()->count(15)->create();
        $this->get(route('interviews.index'))->assertOk()->assertViewHas('interviews', fn ($rows) => $rows->count() === 15);
        $this->get(route('interviews.index', ['search' => $interview->application->application_code]))->assertOk()->assertViewHas('interviews', fn ($rows) => $rows->total() === 1);
        auth()->logout();
        $this->get(route('interviews.index'))->assertRedirect(route('login'));
        $this->put(route('interviews.answers', $interview), ['outcome' => 'continue'])->assertRedirect(route('login'));
        $this->assertNull($interview->fresh()->outcome);
    }
}
