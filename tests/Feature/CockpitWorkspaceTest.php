<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CockpitWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', $role)->value('id')]);
    }

    public function test_legacy_admin_and_custom_admin_land_on_menu_dashboard_without_changing_permissions(): void
    {
        $owner = $this->user('migration_owner');
        $menu = $this->actingAs($owner)->get(route('dashboard'));
        $menu->assertOk()->assertViewIs('cockpit.menu')->assertViewHas('groups', config('navigation'));
        if (getenv('JM_THEME_VISUAL_QA')) {
            foreach (['menu' => $menu, 'system' => $this->get(route('cockpit.index', 'system')), 'finance' => $this->get(route('cockpit.index', 'finance'))] as $name => $response) {
                $html = preg_replace_callback('~https?://[^/" ]+/(css|js)/([^" ]+)~', fn ($match) => 'file://'.public_path($match[1].'/'.$match[2]), $response->getContent());
                file_put_contents('/tmp/jm-theme-'.$name.'.html', $html);
            }
        }
        $this->get(route('cockpit.index', 'system'))->assertOk()->assertViewIs('cockpit.system');
        $role = DB::table('crm_roles')->insertGetId(['name' => 'Super Admin Kustom', 'is_admin' => true, 'active' => true, 'scope_type' => 'TECHNICAL_ONLY', 'permissions' => json_encode(config('governance.roles.super_admin')[2]), 'created_at' => now(), 'updated_at' => now()]);
        $owner->update(['crm_role_id' => $role]);
        $this->get(route('dashboard'))->assertOk()->assertViewIs('cockpit.menu')->assertViewHas('groups', fn ($groups) => ! collect($groups)->flatMap(fn ($group) => $group['items'])->contains('route', 'candidates.index'));
        $this->get(route('candidates.index'))->assertForbidden();
        $this->actingAs($this->user('cs'))->get(route('dashboard'))->assertOk()->assertDontSee('System Cockpit');
        $this->get(route('cockpit.index', 'system'))->assertForbidden();
    }

    public function test_cs_priorities_and_schedules_are_scoped_and_completed_tasks_are_excluded(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 09:00', 'Asia/Jakarta')->utc());
        $cs = $this->user('cs');
        $other = $this->user('cs');
        $mine = Application::factory()->create(['assigned_cs_id' => $cs->id]);
        $outside = Application::factory()->create(['assigned_cs_id' => $other->id]);
        Task::factory()->create(['application_id' => $mine->id, 'title' => 'Telepon pendaftar saya', 'task_type' => 'follow_up', 'due_at' => now()->addHour()]);
        Task::factory()->create(['application_id' => $mine->id, 'title' => 'Tugas terlambat saya', 'due_at' => now()->subHour()]);
        Task::factory()->create(['application_id' => $mine->id, 'title' => 'Sudah tuntas', 'due_at' => now()->subDay(), 'status' => 'completed']);
        Task::factory()->create(['application_id' => $outside->id, 'title' => 'Rahasia tim lain', 'due_at' => now()->subHour()]);
        foreach ([$mine->id => 'Lead milik saya', $outside->id => 'Lead di luar cakupan'] as $id => $name) {
            DB::table('acquisition_leads')->insert(['name' => $name, 'public_token' => Str::uuid(), 'normalized_phone' => '+628'.random_int(1000000000, 9999999999), 'assigned_cs_id' => $id === $mine->id ? $cs->id : $other->id, 'status' => 'new', 'followup_status' => 'NOT_CONTACTED', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('interviews')->insert(['application_id' => $id, 'scheduled_at' => now()->addHours(2), 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        }
        $response = $this->actingAs($cs)->get(route('cockpit.index', 'cs'))->assertOk()->assertSee('Lead milik saya')->assertSee('Telepon pendaftar saya')->assertDontSee('Rahasia tim lain')->assertDontSee('Sudah tuntas')->assertDontSee('Lead di luar cakupan');
        $response->assertViewHas('stats', fn ($stats) => $stats[0]['value'] === '1' && $stats[1]['value'] === '1' && $stats[2]['value'] === '1');
        $response->assertViewHas('schedules', fn ($schedules) => $schedules->count() === 1 && $schedules->first()->application_id === $mine->id);
        $this->get(route('acquisition.leads', ['status' => 'new']))->assertOk()->assertSee('Lead milik saya')->assertDontSee('Lead di luar cakupan');
        $this->get(route('cockpit.index', 'finance'))->assertForbidden();
    }

    public function test_finance_uses_remaining_amounts_actual_cash_and_separate_currencies(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 09:00', 'Asia/Jakarta')->utc());
        $finance = $this->user('finance');
        $application = Application::factory()->create();
        $agreement = DB::table('commercial_agreements')->insertGetId(['company_id' => $application->vacancy->jobOrder->company_id, 'agreement_number' => 'AGR-COCKPIT', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $milestone = DB::table('agreement_milestones')->insertGetId(['agreement_id' => $agreement, 'name' => 'Awal', 'trigger_type' => 'manual', 'amount' => '100.10', 'currency' => 'IDR', 'sequence' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['IDR' => '100.10', 'USD' => '900.00'] as $currency => $amount) {
            $id = DB::table('sponsor_receivables')->insertGetId(['application_id' => $application->id, 'agreement_milestone_id' => $milestone, 'amount' => $amount, 'currency' => $currency, 'due_date' => now()->subDays(35)->toDateString(), 'invoice_number' => 'INV-'.$currency, 'status' => 'partial', 'created_at' => now(), 'updated_at' => now()]);
            if ($currency === 'IDR') {
                DB::table('sponsor_payments')->insert(['receivable_id' => $id, 'amount' => '40.05', 'paid_at' => now(), 'reference_number' => 'PAY-COCKPIT', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $benefit = DB::table('candidate_benefit_plans')->insertGetId(['application_id' => $application->id, 'target_amount' => '50.10', 'currency' => 'IDR', 'basis' => 'Hak tertulis', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('candidate_benefit_disbursements')->insert(['benefit_plan_id' => $benefit, 'amount' => '20.05', 'purpose' => 'Hak', 'disbursed_at' => now(), 'recipient' => 'Penerima', 'created_at' => now(), 'updated_at' => now()]);
        $response = $this->actingAs($finance)->get(route('cockpit.index', 'finance'))->assertOk()->assertDontSee($application->candidate->person->full_name)->assertSee('Belum tersedia');
        $response->assertViewHas('stats', fn ($stats) => $stats[0]['value'] === 'IDR 60,05' && $stats[1]['value'] === 'IDR 30,05' && $stats[2]['value'] === 'IDR 40,05');
        $response->assertViewHas('aging', fn ($aging) => $aging['31–60 hari'] === 6005);
        $response->assertViewHas('cashflow', fn ($points) => $points->last()['in'] === 4005 && $points->last()['out'] === 2005);
        $this->get(route('receivables.index', ['aging' => '31-60', 'currency' => 'IDR']))->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->currency === 'IDR');
        $this->get(route('receivables.index', ['aging' => 'current', 'currency' => 'IDR']))->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 0);
        $this->get(route('cockpit.index', ['role' => 'finance', 'currency' => 'USD']))->assertOk()->assertViewHas('stats', fn ($stats) => $stats[0]['value'] === 'USD 900,00' && $stats[2]['value'] === 'USD 0,00');
    }

    public function test_technical_cockpit_only_exposes_safe_audit_metadata_and_honest_health(): void
    {
        $admin = $this->user('super_admin');
        $application = Application::factory()->create();
        DB::table('audit_logs')->insert(['user_id' => $admin->id, 'action' => 'access.denied', 'old_values' => json_encode(['secret' => 'sensitive-business-payload']), 'new_values' => json_encode(['candidate' => $application->candidate->person->full_name]), 'created_at' => now()]);
        DB::table('crm_integrations')->insert(['name' => 'API mitra', 'endpoint' => 'https://example.test', 'secret' => 'api-secret-do-not-display', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin)->get(route('cockpit.index', 'super-admin'))->assertOk()->assertSee('System Cockpit')->assertSee('API mitra')->assertSee('Koneksi belum diuji')->assertSee('uptime belum diukur')->assertViewHas('roles', fn ($roles) => $roles->contains('name', 'Super Admin'))->assertSee('access.denied')->assertDontSee('sensitive-business-payload')->assertDontSee('api-secret-do-not-display')->assertDontSee($application->candidate->person->full_name);
        $this->get(route('cockpit.index', ['role' => 'system', 'level' => 'WARN']))->assertOk()->assertViewHas('logs', fn ($logs) => $logs->count() === 1 && $logs->first()->level === 'WARN');
        $this->get(route('cockpit.index', ['role' => 'system', 'level' => 'ERROR']))->assertOk()->assertViewHas('logs', fn ($logs) => $logs->isEmpty());
        $this->get(route('cockpit.index', 'cs'))->assertOk();
    }
}
