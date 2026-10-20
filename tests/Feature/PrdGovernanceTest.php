<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Services\AccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrdGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['crm_role_id' => DB::table('crm_roles')->where('code', $role)->value('id')]);
    }

    private function technicalUser(): User
    {
        $role = DB::table('crm_roles')->insertGetId(['name' => 'Technical Admin', 'active' => true, 'is_admin' => true, 'permissions' => json_encode(config('governance.roles.super_admin')[2]), 'scope_type' => 'TECHNICAL_ONLY', 'created_at' => now(), 'updated_at' => now()]);

        return User::factory()->create(['crm_role_id' => $role]);
    }

    public function test_cs_can_read_assigned_prescreen_but_cannot_get_other_candidates_or_finance(): void
    {
        $cs = $this->user('cs');
        $other = $this->user('cs');
        $mine = Application::factory()->create(['assigned_cs_id' => $cs->id]);
        $theirs = Application::factory()->create(['assigned_cs_id' => $other->id]);
        $myName = $mine->candidate->person->full_name;
        $otherName = $theirs->candidate->person->full_name;
        $this->actingAs($cs)->get(route('candidates.index'))->assertOk()->assertSee($myName)->assertDontSee($otherName);
        $this->get(route('applications.show', $theirs->id))->assertNotFound();
        $this->get(route('candidates.show', $theirs->candidate_id))->assertNotFound();
        $this->get(route('health-screenings.index'))->assertOk()->assertSee($myName)->assertDontSee($otherName);
        $this->get(route('health-screenings.show', $theirs))->assertNotFound();
        $this->post(route('sponsor-payments.store'), ['amount' => '10'])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'access.denied']);
    }

    public function test_super_admin_has_all_menus_and_operations_and_finance_keep_their_permissions(): void
    {
        $system = $this->user('super_admin');
        $this->actingAs($system)->get(route('settings.users.index'))->assertOk();
        $this->get(route('candidates.index'))->assertOk();
        $this->get(route('receivables.index'))->assertOk();
        $this->get(route('dashboard'))->assertOk()->assertSee('Dashboard Superadmin');
        $this->actingAs($this->user('finance'))->get(route('receivables.index'))->assertOk();
        $this->get(route('health-screenings.index'))->assertForbidden();
        $this->get(route('candidates.index'))->assertForbidden();
        $this->actingAs($this->user('admin_operations'))->get(route('acquisition.groups'))->assertOk();
        $this->get(route('sponsor-payments.index'))->assertForbidden();
    }

    public function test_team_sponsor_scope_and_assignment_expiry_are_enforced(): void
    {
        $supervisor = $this->user('supervisor', ['team_id' => 9]);
        $cs = $this->user('cs', ['team_id' => 9]);
        $other = $this->user('cs', ['team_id' => 10]);
        $mine = Application::factory()->create(['assigned_cs_id' => $cs->id]);
        $outside = Application::factory()->create(['assigned_cs_id' => $other->id]);
        $policy = app(AccessPolicy::class);
        $this->assertTrue($policy->canCandidate($supervisor, $mine->candidate_id, 'candidate.read.basic'));
        $this->assertFalse($policy->canCandidate($supervisor, $outside->candidate_id, 'candidate.read.basic'));
        $finance = $this->user('finance');
        $role = DB::table('crm_roles')->insertGetId(['name' => 'Finance sponsor A', 'permissions' => json_encode(['finance.cpmi_benefit.read']), 'scope_type' => 'SPONSOR', 'scope_refs' => json_encode([$mine->vacancy->jobOrder->company_id]), 'created_at' => now(), 'updated_at' => now()]);
        $finance->update(['crm_role_id' => $role]);
        $this->assertTrue($policy->applicationIds($finance, 'finance.cpmi_benefit.read')->where('applications.id', $mine->id)->exists());
        $this->assertFalse($policy->applicationIds($finance, 'finance.cpmi_benefit.read')->where('applications.id', $outside->id)->exists());
        $technical = $this->technicalUser();
        DB::table('user_role_assignments')->insert(['user_id' => $technical->id, 'role_id' => DB::table('crm_roles')->where('code', 'finance')->value('id'), 'valid_from' => now()->subDay(), 'valid_until' => now()->subMinute(), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($policy->has($technical, 'finance.payment.record'));
    }

    public function test_break_glass_is_resource_scoped_audited_and_expires(): void
    {
        $technical = $this->technicalUser();
        $application = Application::factory()->create();
        $other = Application::factory()->create();
        DB::table('access_grants')->insert(['type' => 'break_glass', 'user_id' => $technical->id, 'candidate_id' => $application->candidate_id, 'permissions' => json_encode(['candidate.read.basic']), 'reason' => 'Security investigation', 'status' => 'approved', 'approved_by' => $this->user('super_admin')->id, 'valid_from' => now()->subMinute(), 'valid_until' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($technical)->get(route('candidates.show', $application->candidate_id))->assertOk()->assertSee('Akses darurat aktif');
        $this->get(route('candidates.show', $other->candidate_id))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'candidates.show']);
        $this->travel(11)->minutes();
        $this->get(route('candidates.show', $application->candidate_id))->assertNotFound();
    }

    public function test_management_is_aggregate_only_and_exports_need_separate_permission(): void
    {
        Application::factory()->create();
        $management = $this->user('management');
        $this->actingAs($management)->get(route('reports.pipeline.index'))->assertOk();
        $this->get(route('reports.progress.index'))->assertForbidden();
        $this->get(route('reports.pipeline.index', ['export' => 'csv']))->assertForbidden();
        $this->get(route('candidates.index'))->assertForbidden();
    }

    public function test_contact_identity_and_location_are_masked_without_sensitive_grants(): void
    {
        $cs = $this->user('cs');
        $application = Application::factory()->create(['assigned_cs_id' => $cs->id]);
        $application->candidate->person->update(['nik' => '3271000000000012', 'address' => 'Alamat rahasia keluarga']);
        $this->actingAs($cs)->get(route('candidates.show', $application->candidate_id))->assertOk()->assertDontSee('3271000000000012')->assertDontSee('Alamat rahasia keluarga')->assertSee('••••');
    }
}
