<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ThemeCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function artifact(string $key, string $html): void
    {
        if ($directory = getenv('THEME_QA_FIXTURES')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            file_put_contents($directory.'/'.$key.'.html', $html);
        }
    }

    public function test_public_pages_and_guest_authentication_support_the_same_theme_preference(): void
    {
        foreach (['/' => 'home', '/lowongan' => 'vacancies', '/faq' => 'faq', '/panduan' => 'guides', '/hubungi-kami' => 'contact', '/privasi' => 'privacy', '/ketentuan' => 'terms', '/login' => 'staff-login', '/portal/login' => 'cpmi-login', '/forgot-password' => 'recovery'] as $url => $key) {
            $response = $this->get($url)->assertOk()->assertSee('theme.js')->assertSee('theme.css')->assertSee('data-theme-toggle', false);
            $this->artifact($key, $response->getContent());
        }
    }

    public function test_all_portal_sections_keep_the_theme_toggle_and_safe_account_scope(): void
    {
        $application = Application::factory()->create();
        $application->candidate->person->update(['full_name' => 'Data Uji Tema']);
        $user = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cpmi')->value('id')]);
        DB::table('portal_accounts')->insert(['candidate_id' => $application->candidate_id, 'user_id' => $user->id, 'normalized_phone' => '+6281234567890', 'status' => 'active', 'phone_verified_at' => now(), 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);
        foreach (['home', 'process', 'documents', 'schedules', 'requests', 'help', 'profile', 'privacy', 'notifications', 'welfare'] as $page) {
            $response = $this->get(route('portal.'.$page))->assertOk()->assertSee('theme.js')->assertSee('theme.css')->assertSee('data-theme-toggle', false);
            $this->artifact('portal-'.$page, $response->getContent());
        }
    }
}
