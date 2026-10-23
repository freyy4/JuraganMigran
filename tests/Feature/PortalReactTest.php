<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalReactTest extends TestCase
{
    use RefreshDatabase;

    private function account(Application $application): User
    {
        $user = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cpmi')->value('id')]);
        DB::table('portal_accounts')->insert(['candidate_id' => $application->candidate_id, 'user_id' => $user->id, 'normalized_phone' => '+628'.random_int(1000000000, 9999999999), 'status' => 'active', 'phone_verified_at' => now(), 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public function test_login_shell_does_not_load_dashboard_react_or_expose_candidate_data(): void
    {
        $response = $this->get(route('portal.login'));
        $response->assertOk()->assertSee('authentication.js')->assertDontSee('portal-react.js')->assertSee('name="csrf-token"', false)->assertDontSee('portal-react-bootstrap');
    }

    public function test_staff_session_is_redirected_to_portal_login_without_losing_admin_access(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff)->get(route('portal.home'))->assertRedirect(route('portal.login'))->assertSessionHasErrors('email');
        $this->assertAuthenticatedAs($staff);
        $this->get(route('portal.login'))->assertOk()->assertSee('akun admin/petugas')->assertSee('Kembali ke dasbor admin');
        $this->getJson(route('portal.api.summary'))->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('portal_accounts', 0);
    }

    public function test_revoked_cpmi_sees_activation_guidance_without_receiving_private_data(): void
    {
        $application = Application::factory()->create();
        $name = $application->candidate->person->full_name;
        $user = $this->account($application);
        DB::table('portal_accounts')->where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->actingAs($user)->get(route('portal.documents'))->assertRedirect(route('portal.login'))->assertSessionHasErrors('email');
        $this->get(route('portal.login'))->assertOk()->assertSee('Hubungi CS')->assertDontSee($name)->assertDontSee('Kembali ke dasbor admin');
        $this->getJson(route('portal.api.summary'))->assertForbidden();
    }

    public function test_react_bootstrap_escapes_candidate_values_and_contains_only_own_safe_profile(): void
    {
        $mine = Application::factory()->create();
        $other = Application::factory()->create();
        $otherName = $other->candidate->person->full_name;
        $type = DocumentType::factory()->create(['name' => 'Paspor', 'allowed_file_types' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_file_size_kb' => 5120]);
        $mine->vacancy->documentRequirements()->create(['document_type_id' => $type->id, 'is_required' => true]);
        $mine->candidate->person->update(['full_name' => '</script><script>alert("test")</script>', 'nik' => '3171999999999999']);
        $user = $this->account($mine);
        $response = $this->actingAs($user)->get(route('portal.home', ['candidate_id' => $other->candidate_id]));
        $response->assertOk()->assertSee('portal-react-root')->assertSee('portal-react.js')->assertDontSee('<script>alert("test")</script>', false);
        preg_match('/<script id="portal-react-bootstrap" type="application\/json">(.*?)<\/script>/s', $response->getContent(), $matches);
        $bootstrap = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($mine->candidate->person->fresh()->full_name, $bootstrap['data']['name']);
        $this->assertSame($user->email, $bootstrap['data']['profile']['login_email']);
        $this->assertStringNotContainsString('3171999999999999', $matches[1]);
        $this->assertStringNotContainsString($otherName, $matches[1]);
        $this->assertArrayNotHasKey('password', $bootstrap['data']['profile']);
        $this->assertArrayNotHasKey('normalized_phone', $bootstrap['data']['profile']);

        if (getenv('JM_REACT_VISUAL_QA')) {
            $html = preg_replace('~https?://[^/]+/(css|js|images)/~', 'file://'.public_path().'/$1/', $response->getContent());
            file_put_contents('/tmp/jm-react-portal.html', $html);
        }
    }

    public function test_json_login_regenerates_session_and_only_accepts_cpmi_credentials(): void
    {
        $user = $this->account(Application::factory()->create());
        $this->trustPortalDevice($user);
        $this->postJson(route('portal.login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized()->assertJsonPath('code','INVALID_CREDENTIALS');
        $this->assertGuest();
        $this->postJson(route('portal.login.store'), ['email' => $user->email, 'password' => 'password'])->assertOk()->assertJsonPath('redirect', route('portal.home'))->assertHeader('Cache-Control', 'no-store, private');
        $this->assertAuthenticatedAs($user);
        $this->getJson(route('portal.api.summary'))->assertOk()->assertJsonPath('profile.login_email', $user->email);
    }

    public function test_portal_json_requires_active_account_and_never_redirects_to_html_login(): void
    {
        $this->getJson(route('portal.api.summary'))->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $user = $this->account(Application::factory()->create());
        $this->actingAs($user)->getJson(route('portal.api.summary'))->assertOk();
        DB::table('portal_accounts')->where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->getJson(route('portal.api.summary'))->assertForbidden();
    }

    public function test_notification_preferences_and_session_revocation_support_react_without_touching_other_accounts(): void
    {
        $mine = Application::factory()->create();
        $user = $this->account($mine);
        $otherUser = $this->account(Application::factory()->create());
        $this->actingAs($user)->postJson(route('portal.preferences'), ['in_app_notifications' => false, 'whatsapp_notifications' => true])->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('portal_accounts', ['user_id' => $user->id, 'in_app_notifications' => false, 'whatsapp_notifications' => true]);
        $this->getJson(route('portal.api.summary'))->assertJsonPath('profile.whatsapp_notifications', true);
        $this->postJson(route('portal.revokeSessions'))->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('portal_accounts', ['user_id' => $otherUser->id, 'status' => 'active']);
    }

    public function test_document_constraints_are_supplied_from_master_data_and_private_files_stay_out_of_service_worker_cache(): void
    {
        $application = Application::factory()->create();
        $type = DocumentType::factory()->create(['allowed_file_types' => ['pdf'], 'max_file_size_kb' => 1024]);
        $application->vacancy->documentRequirements()->create(['document_type_id' => $type->id, 'is_required' => true]);
        $this->actingAs($this->account($application))->getJson(route('portal.api.documents'))->assertOk()->assertJsonPath('0.allowed_file_types', ['pdf'])->assertJsonPath('0.max_file_size_kb', 1024);
        $worker = file_get_contents(public_path('portal-sw.js'));
        $this->assertStringContainsString("'/js/portal-react.js'", $worker);
        $this->assertStringNotContainsString('cache.put', $worker);
        $this->assertStringNotContainsString("'/api/portal", $worker);
        $this->assertFileExists(public_path('js/portal-react.js'));
    }
}
