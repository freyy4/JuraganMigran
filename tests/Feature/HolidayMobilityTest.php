<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\DocumentType;
use App\Models\HolidayEvent;
use App\Models\HolidayPolicy;
use App\Models\MobilityCase;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Services\HolidayEligibility;
use App\Services\MobilityService;
use App\Services\QrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HolidayMobilityTest extends TestCase
{
    use RefreshDatabase;

    private function visualArtifact(string $html, string $name): void
    {
        $html = preg_replace_callback('~https?://[^/" ]+/(css|js)/([^" ]+)~', fn ($match) => 'file://'.public_path($match[1].'/'.$match[2]), $html);
        file_put_contents('/tmp/jm-v87-'.$name.'.html', $html);
    }

    private function holiday(Application $app): HolidayEvent
    {
        $enrollment = TrainingEnrollment::factory()->create(['application_id' => $app->id, 'status' => 'ongoing']);
        $policy = HolidayPolicy::create(['name' => 'Kebijakan Uji', 'scope' => [], 'rules' => [['condition' => 'departure_within_days', 'days' => 5, 'result' => 'NOT_ALLOWED', 'reason' => 'Keberangkatan terlalu dekat dengan waktu kembali.']]]);

        return HolidayEvent::create(['name' => 'Libur Uji', 'start_date' => now()->addDays(2)->toDateString(), 'end_date' => now()->addDays(4)->toDateString(), 'return_deadline' => now()->addDays(5), 'holiday_type' => 'LPK', 'lpk_ids' => [$enrollment->batch->company_id], 'policy_id' => $policy->id, 'status' => 'ACTIVE']);
    }

    private function leaveData(Application $app, HolidayEvent $holiday): array
    {
        return ['application_id' => $app->id, 'holiday_event_id' => $holiday->id, 'start_at' => now('Asia/Jakarta')->addDays(2)->format('Y-m-d\TH:i'), 'end_at' => now('Asia/Jakarta')->addDays(4)->format('Y-m-d\TH:i'), 'destination_city' => 'Bandung', 'emergency_contact' => 'Kontak darurat privat', 'reason' => 'Mengunjungi keluarga', 'outbound_mode' => 'JM_PICKUP_SERVICE', 'inbound_mode' => 'SELF_TRAVEL'];
    }

    public function test_departure_policy_is_configurable_and_recomputed_before_approval(): void
    {
        $app = Application::factory()->create();
        $holiday = $this->holiday($app);
        $this->actingAs(User::factory()->create());
        $response = $this->get(route('holiday-transport.index', ['edit_holiday_id' => $holiday->id]));
        $response->assertOk()->assertSee('Libur Uji');
        if (getenv('JM_V87_VISUAL_QA')) {
            $this->visualArtifact($response->getContent(), 'operations');
        }
        $payload = $this->leaveData($app, $holiday);
        $this->postJson(route('holiday-transport.leave'), $payload)->assertOk();
        $leave = DB::table('training_leave_requests')->first();
        $this->assertNotSame($payload['emergency_contact'], $leave->emergency_contact);
        $this->assertDatabaseCount('mobility_legs', 2);
        DB::table('flight_details')->insert(['application_id' => $app->id, 'departure_at' => now()->addDays(6), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('NOT_ALLOWED', app(HolidayEligibility::class)->evaluate($app, $holiday)['result']);
        $this->postJson(route('holiday-transport.review', $leave->id), ['action' => 'approve'])->assertUnprocessable();
        $this->postJson(route('holiday-transport.review', $leave->id), ['action' => 'approve', 'override_reason' => 'Kembali lebih awal dengan pendampingan', 'revised_return_at' => now('Asia/Jakarta')->addDays(3)->format('Y-m-d\TH:i'), 'candidate_acknowledged' => 1])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.exception_approved']);
        $this->assertDatabaseHas('training_leave_requests', ['id' => $leave->id, 'approval_status' => 'approved']);
        $case = MobilityCase::first();
        $this->get(route('mobility.show', $case))->assertOk();
    }

    public function test_qr_is_opaque_minimal_single_use_and_revocable(): void
    {
        $app = Application::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);
        $case = app(MobilityService::class)->create($app, ['purpose' => 'PT', 'origin' => 'Bandung', 'destination' => 'Kantor PT', 'appointment_at' => now()->addDay(), 'transport_mode' => 'SELF_TRAVEL'], $user->id);
        $leg = DB::table('mobility_legs')->first();
        $this->postJson(route('mobility.api-issue-body'), ['mobility_leg_id' => $leg->id, 'expires_at' => now('Asia/Jakarta')->addDays(2)->format('Y-m-d\TH:i')])->assertOk();
        $pass = DB::table('qr_passes')->first();
        $token = Crypt::decryptString($pass->encrypted_token);
        $this->assertSame(48, strlen($token));
        $this->getJson(route('qr.public', $token))->assertOk()->assertJsonPath('recruitment', 'Juragan Migran')->assertJsonMissingPath('nik')->assertJsonMissingPath('whatsapp')->assertJsonMissingPath('application_id');
        $this->get(route('qr.public', $token))->assertOk();
        $this->get(route('qr.image', $token))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->postJson(route('mobility.check-in', $token))->assertOk();
        $this->postJson(route('mobility.check-in', $token))->assertStatus(409);
        $this->assertDatabaseCount('qr_scan_events', 4);
        $this->getJson(route('qr.public', $token))->assertJsonPath('status', 'USED')->assertJsonMissingPath('name');
        $secondCase = app(MobilityService::class)->create($app, ['purpose' => 'LPK', 'origin' => 'PT', 'destination' => 'LPK', 'appointment_at' => now()->addDay(), 'transport_mode' => 'SELF_TRAVEL'], $user->id);
        $second = DB::table('mobility_legs')->where('mobility_case_id', $secondCase->id)->first();
        $pass = app(MobilityService::class)->issue($second->id, now()->addDay(), $user->id);
        $token = Crypt::decryptString($pass->encrypted_token);
        $this->postJson(route('mobility.revoke', $pass->id))->assertOk();
        $this->postJson(route('mobility.check-in', $token))->assertStatus(409);
        $this->getJson(route('qr.public', $token))->assertJsonPath('status', 'CANCELLED');
    }

    public function test_scoped_staff_and_portal_cannot_access_other_travel(): void
    {
        $mine = Application::factory()->create();
        $other = Application::factory()->create();
        $otherName = $other->candidate->person->full_name;
        $this->holiday($mine);
        app(MobilityService::class)->create($mine, ['purpose' => 'LPK', 'origin' => 'Bandung', 'destination' => 'LPK Uji', 'appointment_at' => now()->addDay(), 'transport_mode' => 'SELF_TRAVEL'], null);
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $mine->update(['assigned_cs_id' => $cs->id]);
        $case = app(MobilityService::class)->create($other, ['purpose' => 'PT', 'origin' => 'Kota', 'destination' => 'PT', 'appointment_at' => now()->addDay(), 'transport_mode' => 'SELF_TRAVEL'], null);
        $this->actingAs($cs)->get(route('mobility.show', $case))->assertForbidden();
        $this->getJson(route('mobility.api-show-list'))->assertOk()->assertDontSee($otherName);
        $this->postJson(route('holiday-transport.policy'), [])->assertForbidden();
        $portal = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cpmi')->value('id')]);
        DB::table('portal_accounts')->insert(['candidate_id' => $mine->candidate_id, 'user_id' => $portal->id, 'normalized_phone' => '+6281212121212', 'status' => 'active', 'phone_verified_at' => now(), 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($portal)->get(route('portal.travel.show', $case))->assertNotFound();
        $response = $this->get(route('portal.holiday'));
        $response->assertOk()->assertDontSee($otherName);
        if (getenv('JM_V87_VISUAL_QA')) {
            $this->visualArtifact($response->getContent(), 'portal');
        }
    }

    public function test_return_reminders_and_escalation_are_idempotent(): void
    {
        $app = Application::factory()->create();
        $holiday = $this->holiday($app);
        $this->actingAs(User::factory()->create());
        $this->postJson(route('holiday-transport.leave'), $this->leaveData($app, $holiday))->assertOk();
        $leave = DB::table('training_leave_requests')->first();
        $this->postJson(route('holiday-transport.review', $leave->id), ['action' => 'approve'])->assertOk();
        DB::table('training_leave_requests')->where('id', $leave->id)->update(['mandatory_return_at' => now()->subHour()]);
        $this->artisan('ops:monitor')->assertSuccessful();
        $this->artisan('ops:monitor')->assertSuccessful();
        $this->assertDatabaseCount('operations_alerts', 1);
        $this->assertDatabaseHas('training_leave_requests', ['id' => $leave->id, 'journey_status' => 'OVERDUE_RETURN']);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_transport_reminders_do_not_repeat_and_expired_qr_cannot_check_in(): void
    {
        $app = Application::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);
        $case = app(MobilityService::class)->create($app, ['purpose' => 'PT', 'origin' => 'JM', 'destination' => 'PT', 'appointment_at' => now()->addHours(12), 'transport_mode' => 'JM_PICKUP_SERVICE'], $user->id);
        $leg = DB::table('mobility_legs')->where('mobility_case_id', $case->id)->first();
        $this->artisan('ops:monitor')->assertSuccessful();
        $this->artisan('ops:monitor')->assertSuccessful();
        $this->assertDatabaseCount('operations_alerts', 1);
        $pass = app(MobilityService::class)->issue($leg->id, now()->addHour(), $user->id);
        $this->travel(2)->hours();
        $this->actingAs($user);
        $token = Crypt::decryptString($pass->encrypted_token);
        $this->getJson(route('qr.public', $token))->assertJsonPath('status', 'EXPIRED')->assertJsonMissingPath('name');
        $this->postJson(route('mobility.check-in', $token))->assertStatus(409);
        $this->assertDatabaseHas('qr_passes', ['id' => $pass->id, 'used_at' => null]);
    }

    public function test_pickup_capacity_and_existing_document_custody_handover(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $app = Application::factory()->create();
        $app->candidate->person->update(['full_name' => "Daisy O'Reilly"]);
        $case = app(MobilityService::class)->create($app, ['purpose' => 'HANDOVER', 'origin' => 'JM', 'destination' => 'Kantor PT', 'appointment_at' => now()->addDay(), 'transport_mode' => 'JM_PICKUP_SERVICE'], $user->id);
        $this->postJson(route('mobility.batch'), ['route_name' => 'JM ke PT', 'destination' => 'PT', 'pickup_at' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i'), 'pic_id' => $user->id, 'capacity' => 1, 'status' => 'READY'])->assertOk();
        $batch = DB::table('transport_batches')->first();
        $leg = DB::table('mobility_legs')->where('mobility_case_id', $case->id)->first();
        $this->get(route('holiday-transport.index'))->assertOk()->assertSee('JM ke PT');
        $this->postJson(route('mobility.leg', $leg->id), ['status' => 'PLANNED', 'batch_id' => $batch->id, 'scheduled_at' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i')])->assertOk();
        $other = Application::factory()->create();
        $second = app(MobilityService::class)->create($other, ['purpose' => 'PT', 'origin' => 'JM', 'destination' => 'PT', 'appointment_at' => now()->addDay(), 'transport_mode' => 'JM_PICKUP_SERVICE'], $user->id);
        $otherLeg = DB::table('mobility_legs')->where('mobility_case_id', $second->id)->first();
        $this->postJson(route('mobility.leg', $otherLeg->id), ['status' => 'PLANNED', 'batch_id' => $batch->id, 'scheduled_at' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i')])->assertUnprocessable();
        $this->get(route('mobility.manifest', $batch->id))->assertOk()->assertSee($app->candidate->person->full_name, false)->assertDontSee($other->candidate->person->full_name, false);
        $document = CandidateDocument::create(['candidate_id' => $app->candidate_id, 'application_id' => $app->id, 'document_type_id' => DocumentType::factory()->create()->id, 'file_path' => 'private-test.pdf', 'status' => 'uploaded', 'version' => 1]);
        $custody = DB::table('original_document_custodies')->insertGetId(['candidate_document_id' => $document->id, 'application_id' => $app->id, 'current_holder' => 'JM', 'status' => 'received', 'received_at' => now(), 'received_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['EXPECTED', 'HANDED_OVER', 'RECEIVED'] as $status) {
            if ($status === 'EXPECTED') {
                $this->postJson(route('mobility.api-handover-body'), ['mobility_case_id' => $case->id, 'custody_id' => $custody, 'to_party' => 'Petugas PT', 'status' => $status])->assertOk();
            } elseif ($status === 'RECEIVED') {
                $item = DB::table('mobility_handover_items')->first();
                $this->patchJson(route('mobility.api-handover-update', $item->id), ['to_party' => 'Petugas PT', 'status' => $status])->assertOk();
            } else {
                $this->postJson(route('mobility.handover', $case), ['custody_id' => $custody, 'to_party' => 'Petugas PT', 'status' => $status])->assertOk();
            }
        }
        $this->assertDatabaseHas('original_document_custodies', ['id' => $custody, 'current_holder' => 'Petugas PT', 'status' => 'transferred']);
        $this->assertDatabaseHas('document_custody_histories', ['custody_id' => $custody, 'action' => 'transfer']);
        $this->postJson(route('mobility.handover', $case), ['custody_id' => $custody, 'to_party' => 'Petugas PT', 'status' => 'RECEIVED'])->assertUnprocessable();
    }

    public function test_bulk_approval_cannot_override_departure_lock(): void
    {
        $app = Application::factory()->create();
        $holiday = $this->holiday($app);
        $this->actingAs(User::factory()->create());
        $this->postJson(route('holiday-transport.leave'), $this->leaveData($app, $holiday))->assertOk();
        $leave = DB::table('training_leave_requests')->first();
        DB::table('flight_details')->insert(['application_id' => $app->id, 'departure_at' => now()->addDays(6), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson(route('holiday-transport.bulk'), ['action' => 'approve_allowed', 'ids' => [$leave->id], 'confirm' => 1])->assertUnprocessable();
        $this->assertDatabaseHas('training_leave_requests', ['id' => $leave->id, 'approval_status' => 'pending']);
    }

    public function test_locked_leave_requires_a_scoped_exception_request_and_separate_approval(): void
    {
        $app = Application::factory()->create();
        $holiday = $this->holiday($app);
        $this->actingAs(User::factory()->create());
        DB::table('flight_details')->insert(['application_id' => $app->id, 'departure_at' => now()->addDays(6), 'created_at' => now(), 'updated_at' => now()]);
        $payload = $this->leaveData($app, $holiday);
        $this->postJson(route('holiday-transport.leave'), $payload)->assertUnprocessable();
        $this->postJson(route('holiday-transport.leave'), $payload + ['override_request_reason' => 'Pendampingan khusus dengan kembali lebih awal'])->assertOk();
        $leave = DB::table('training_leave_requests')->first();
        $this->assertSame('pending', $leave->approval_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave.exception_requested']);
        $this->postJson(route('holiday-transport.review', $leave->id), ['action' => 'approve'])->assertUnprocessable();
        $this->assertDatabaseCount('qr_passes', 0);
        DB::table('flight_details')->where('application_id', $app->id)->update(['departure_at' => now()->addDays(8)]);
        $earlier = now('Asia/Jakarta')->addDays(2)->addHours(12);
        $this->assertSame('ALLOWED', app(HolidayEligibility::class)->evaluate($app, $holiday, $earlier->copy()->utc())['result']);
        $this->postJson(route('holiday-transport.review', $leave->id), ['action' => 'approve', 'revised_return_at' => $earlier->format('Y-m-d\TH:i')])->assertUnprocessable();
        $this->assertDatabaseHas('training_leave_requests', ['id' => $leave->id, 'approval_status' => 'pending']);
    }

    public function test_qr_matches_independent_version_10_l_reference_matrix(): void
    {
        $svg = app(QrCode::class)->svg('https://juraganmigran.test/qr/'.str_repeat('A', 48));
        preg_match_all('/M(\d+),(\d+)h1v1h-1z/', $svg, $matches, PREG_SET_ORDER);
        $matrix = array_fill(0, 57, array_fill(0, 57, '0'));
        foreach ($matches as $match) {
            $matrix[(int) $match[2] - 4][(int) $match[1] - 4] = '1';
        }$bits = implode('', array_map(fn ($row) => implode('', $row), $matrix));
        $this->assertSame('0bdbdb14f8c09ee6af4417b3b7dfbf537f5a578e19dc08266bcba86fe6e0fa58', hash('sha256', $bits));
    }
}
