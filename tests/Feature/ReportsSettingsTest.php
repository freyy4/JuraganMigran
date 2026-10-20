<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Models\Application;
use App\Models\ApplicationBlocker;
use App\Models\Task;
use App\Models\User;
use App\Services\AccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReportsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    private function role(array $permissions = ['CRM'], bool $admin = false): int
    {
        return DB::table('crm_roles')->insertGetId(['name' => fake()->unique()->word(), 'permissions' => json_encode($admin ? config('governance.roles.super_admin')[2] : $permissions), 'is_admin' => $admin, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_all_menus_forms_and_legacy_links_are_functional(): void
    {
        Application::factory()->create();
        foreach (array_keys(ReportController::MODULES) as $module) {
            $this->get(route('reports.'.$module.'.index'))->assertOk()->assertSee('Unduh CSV');
        }
        foreach (array_keys(SettingsController::MODULES) as $module) {
            $this->get(route('settings.'.$module.'.index'))->assertOk();
            if (! in_array($module, ['general', 'activity'])) {
                $this->get(route('settings.'.$module.'.create'))->assertOk();
            }
        }
        foreach (collect(config('navigation'))->whereIn('label', ['Laporan', 'Pengaturan'])->flatMap(fn ($group) => $group['items']) as $item) {
            $this->get(route('modules.show', $item['key']))->assertRedirect(route($item['route'], array_merge($item['parameters'] ?? [], ['menu' => $item['key']])));
        }
    }

    public function test_reports_use_filtered_cohorts_and_export_safe_csv(): void
    {
        $application = Application::factory()->create(['assigned_cs_id' => $this->admin->id, 'applied_at' => '2026-10-02 18:00:00']);
        $application->candidate->person->update(['full_name' => '=DANGEROUS()']);
        Application::factory()->create(['applied_at' => '2026-10-03 18:00:00']);
        ApplicationBlocker::factory()->create(['application_id' => $application->id, 'status' => 'open']);
        Task::factory()->create(['application_id' => $application->id, 'status' => 'completed']);
        $filters = ['date_from' => '2026-10-03', 'date_to' => '2026-10-03'];
        $this->get(route('reports.cs.index', $filters))->assertOk()->assertViewHas('totalApplications', 1)->assertViewHas('rows', fn ($rows) => $rows->first()[5] === 1 && $rows->first()[6] === 1);
        $response = $this->get(route('reports.progress.index', $filters + ['export' => 'csv']))->assertOk()->assertDownload('laporan-progress.csv');
        $this->assertStringContainsString("'=DANGEROUS()", $response->streamedContent());
        $this->assertStringNotContainsString('password', $response->streamedContent());
        $this->get(route('reports.progress.index', ['search' => $application->application_code]))->assertViewHas('totalApplications', 1);
        $this->get(route('reports.progress.index', ['date_from' => '2026-10-04', 'date_to' => '2026-10-03']))->assertSessionHasErrors('date_to');
    }

    public function test_roles_restrict_routes_and_settings_and_hide_menus(): void
    {
        $user = User::factory()->create(['crm_role_id' => $this->role(['Laporan'])]);
        $this->actingAs($user)->get(route('reports.pipeline.index'))->assertOk()->assertDontSee('href="'.route('settings.users.index', ['menu' => 'menu-11-0']).'"', false);
        $this->get(route('candidates.index'))->assertForbidden();
        $this->get(route('settings.users.index'))->assertForbidden();
        $this->post(route('settings.general.store'), ['site_name' => 'Tidak'])->assertForbidden();
        $this->get(route('finance-file.sponsor', 1))->assertForbidden();
        $this->get(route('modules.show', 'menu-11-0'))->assertForbidden();
        $this->get(route('settings.users.index'))->assertForbidden();
    }

    public function test_users_passwords_status_and_last_administrator_are_protected(): void
    {
        $role = $this->role();
        $data = ['name' => 'Petugas CRM', 'email' => 'petugas@example.test', 'password' => 'password-baru-ku', 'password_confirmation' => 'password-baru-ku', 'crm_role_id' => $role, 'is_active' => 1];
        $this->post(route('settings.users.store'), $data)->assertSessionHasNoErrors();
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->get(route('settings.users.edit', $user->id))->assertOk()->assertDontSee($user->password);
        $this->put(route('settings.users.update', $this->admin->id), ['name' => $this->admin->name, 'email' => $this->admin->email, 'crm_role_id' => $role, 'is_active' => 1])->assertSessionHasErrors('is_admin');
        $this->assertSame($this->admin->crm_role_id, $this->admin->fresh()->crm_role_id);
        $this->put(route('settings.users.update', $user->id), ['name' => $user->name, 'email' => $user->email, 'crm_role_id' => $role, 'is_active' => 0])->assertSessionHasNoErrors();
        auth()->logout();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => $data['password']])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($user->fresh())->get(route('candidates.index'))->assertRedirect(route('login'));
    }

    public function test_role_updates_permissions_and_cannot_remove_last_admin(): void
    {
        $this->post(route('settings.roles.store'), ['name' => 'Laporan saja', 'permissions' => ['Laporan'], 'is_admin' => 0])->assertSessionHasNoErrors();
        $role = DB::table('crm_roles')->where('name', 'Laporan saja')->first();
        $this->get(route('settings.roles.edit', $role->id))->assertOk();
        $this->put(route('settings.roles.update', $role->id), ['name' => 'Laporan dan CRM', 'permissions' => ['Laporan', 'CRM'], 'is_admin' => 0])->assertSessionHasNoErrors();
        $this->assertSame(app(AccessPolicy::class)->expand(['Laporan', 'CRM']), json_decode(DB::table('crm_roles')->where('id', $role->id)->value('permissions'), true));
        $adminRole = $this->role([], true);
        $this->admin->update(['crm_role_id' => $adminRole]);
        $this->put(route('settings.roles.update', $adminRole), ['name' => 'Administrator', 'permissions' => [], 'is_admin' => 0])->assertSessionHasErrors('is_admin');
        $this->assertTrue((bool) DB::table('crm_roles')->where('id', $adminRole)->value('is_admin'));
    }

    public function test_automation_creates_real_tasks_once_and_respects_conditions(): void
    {
        $application = Application::factory()->create(['assigned_cs_id' => $this->admin->id]);
        Application::factory()->create(['status' => 'closed']);
        $this->post(route('settings.automation.store'), ['name' => 'Tindak lanjut aktif', 'status' => 'active', 'task_title' => 'Hubungi CPMI', 'due_days' => 2, 'is_active' => 1])->assertSessionHasNoErrors();
        $rule = DB::table('automation_rules')->first();
        $this->get(route('settings.automation.edit', $rule->id))->assertOk();
        $this->post(route('settings.automation.run', $rule->id))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tasks', ['application_id' => $application->id, 'assigned_to' => $this->admin->id, 'task_type' => 'automation:'.$rule->id, 'title' => 'Hubungi CPMI']);
        $this->post(route('settings.automation.run', $rule->id))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tasks', 1);
        DB::table('automation_rules')->where('id', $rule->id)->update(['is_active' => false]);
        $this->post(route('settings.automation.run', $rule->id))->assertStatus(422);
    }

    public function test_templates_integrations_general_settings_and_audit_do_not_leak_secrets(): void
    {
        $this->post(route('settings.templates.store'), ['name' => 'Tahap', 'subject' => 'Halo {nama}', 'body' => 'Lamaran {kode_lamaran}: {tahap}', 'is_active' => 1])->assertSessionHasNoErrors();
        $template = DB::table('notification_templates')->first();
        $this->get(route('settings.templates.edit', $template->id))->assertOk()->assertSee('Halo Contoh CPMI');
        $data = ['name' => 'Mitra API', 'endpoint' => 'https://example.test/api', 'secret' => 'secret-api-123456'];
        $this->post(route('settings.integrations.store'), $data)->assertSessionHasNoErrors();
        $integration = DB::table('crm_integrations')->first();
        $this->assertSame($data['secret'], Crypt::decryptString($integration->secret));
        $this->get(route('settings.integrations.edit', $integration->id))->assertOk()->assertDontSee($data['secret'])->assertDontSee($integration->secret);
        $this->put(route('settings.integrations.update', $integration->id), ['name' => $data['name'], 'endpoint' => $data['endpoint']])->assertSessionHasNoErrors();
        $this->assertSame($integration->secret, DB::table('crm_integrations')->value('secret'));
        $this->post(route('settings.general.store'), ['site_name' => 'CRM Baru', 'contact_email' => 'kontak@example.test'])->assertSessionHasNoErrors();
        $this->get(route('settings.general.index'))->assertOk()->assertSee('CRM Baru');
        $this->get(route('settings.activity.index'))->assertOk()->assertSee('settings.integrations.store')->assertDontSee($data['secret']);
        $this->post(route('settings.integrations.store'), array_replace($data, ['endpoint' => 'http://invalid.test']))->assertSessionHasErrors('endpoint')->assertSessionMissing('_old_input.secret');
    }

    public function test_restricted_user_lands_on_an_authorized_menu_after_login(): void
    {
        $user = User::factory()->create(['crm_role_id' => $this->role(['Laporan'])]);
        auth()->logout();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('reports.pipeline.index'));
        $this->get(route('login'))->assertRedirect(route('reports.pipeline.index'));
        $this->get('/')->assertOk()->assertSee('Lowongan Kerja');
    }

    public function test_financial_report_keeps_currencies_separate_and_uses_actual_payments(): void
    {
        $application = Application::factory()->create();
        foreach (['IDR' => '100.10', 'USD' => '20.30'] as $currency => $amount) {
            $plan = DB::table('candidate_benefit_plans')->insertGetId(['application_id' => $application->id, 'target_amount' => $amount, 'currency' => $currency, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('candidate_benefit_disbursements')->insert(['benefit_plan_id' => $plan, 'amount' => '10.05', 'disbursed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->get(route('reports.finance.index'))->assertOk()->assertViewHas('rows', function ($rows) {
            return $rows->total() === 2 && $rows->first()[0] === 'IDR' && $rows->first()[4] === '100.10' && $rows->first()[5] === '10.05' && $rows->first()[6] === '90.05' && $rows->last()[0] === 'USD' && $rows->last()[6] === '10.25';
        });
    }

    public function test_guests_cannot_read_reports_or_manage_settings(): void
    {
        auth()->logout();
        $this->get(route('reports.finance.index'))->assertRedirect(route('login'));
        $this->get(route('settings.activity.index'))->assertRedirect(route('login'));
        $this->post(route('settings.users.store'), [])->assertRedirect(route('login'));
    }
}
