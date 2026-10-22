<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Services\CrmQuickReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmSettingsControlCenterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'super_admin', int $team = 1): User
    {
        return User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', $role)->value('id'), 'team_id' => $team]);
    }

    private function channel(): int
    {
        return DB::table('whatsapp_channels')->insertGetId(['name' => 'JM API', 'waba_id' => '888899991111', 'phone_number_id' => '444455556666', 'display_phone_number' => '+628123456789', 'access_token' => Crypt::encryptString('never-show-this-token'), 'status' => 'CONNECTED', 'team_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_control_center_renders_tabs_real_status_and_no_credentials(): void
    {
        $this->channel();
        $admin = $this->admin('admin_operations');
        $view = $this->actingAs($admin)->get(route('crm.settings', ['tab' => 'tags']))->assertOk()
            ->assertSee('Pengaturan CRM')->assertSee('Panduan & Tips', false)
            ->assertSee('data-sc-section="tags"', false)->assertSee('data-sc-section="automation"', false)
            ->assertSee('data-initial-tab="tags"', false)->assertDontSee('never-show-this-token')
            ->assertDontSee('888899991111')->assertDontSee('444455556666')->assertDontSee('Advanced · Developer Setup');
        if ($directory = getenv('CRM_SETTINGS_QA_FIXTURES')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            file_put_contents($directory.'/limited-settings.html', $view->getContent());
            DB::table('crm_tags')->insert(['name' => 'Program Jepang', 'category' => 'PROGRAM', 'color_token' => 'blue', 'created_at' => now(), 'updated_at' => now()]);
            file_put_contents($directory.'/settings.html', $this->actingAs($this->admin())->get(route('crm.settings'))->assertOk()->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_tag_builder_validates_duplicates_and_soft_disables_used_tags(): void
    {
        $admin = $this->admin();
        $data = ['name' => 'Prioritas tinggi', 'category' => 'PRIORITY', 'color_token' => 'amber'];
        $this->actingAs($admin)->postJson(route('crm.save-tag'), $data)->assertRedirect();
        $id = DB::table('crm_tags')->where('name', $data['name'])->value('id');
        $this->postJson(route('crm.save-tag'), $data)->assertUnprocessable();
        $lead = DB::table('acquisition_leads')->insertGetId(['public_token' => (string) Str::uuid(), 'name' => 'Lead', 'normalized_phone' => '+628123456789', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('crm_lead_tags')->insert(['lead_id' => $lead, 'tag_id' => $id, 'added_by' => $admin->id, 'created_at' => now()]);
        $this->postJson(route('crm.save-tag'), $data + ['id' => $id, 'is_active' => false])->assertRedirect();
        $this->assertDatabaseHas('crm_tags', ['id' => $id, 'category' => 'PRIORITY', 'is_active' => false]);
        $this->assertDatabaseHas('crm_lead_tags', ['lead_id' => $lead, 'tag_id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'crm.tag_updated']);
        $this->postJson(route('crm.save-tag'), ['name' => 'Hasil MCU', 'category' => 'PROGRAM', 'color_token' => 'blue'])->assertUnprocessable();
    }

    public function test_quick_reply_variables_validate_and_resolve_against_real_lead_interest(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('crm.save-quick-reply'), ['name' => 'Salam', 'body' => 'Halo {{nama}}, {{program}} di {{negara}}. Saya {{nama_cs}}.'])->assertRedirect();
        $this->postJson(route('crm.save-quick-reply'), ['name' => 'Tidak valid', 'body' => 'Halo {{secret}}'])->assertUnprocessable();
        $application = Application::factory()->create();
        $leadId = DB::table('acquisition_leads')->insertGetId(['public_token' => (string) Str::uuid(), 'name' => 'Siti', 'normalized_phone' => '+628123456789', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('crm_lead_interests')->insert(['lead_id' => $leadId, 'vacancy_id' => $application->vacancy_id]);
        $context = CrmQuickReply::context(DB::table('acquisition_leads')->find($leadId), $admin->name);
        $rendered = CrmQuickReply::render(DB::table('crm_quick_replies')->where('name', 'Salam')->value('body'), $context);
        $this->assertStringContainsString('Halo Siti', $rendered);
        $this->assertStringContainsString($application->vacancy->title, $rendered);
        $this->assertStringNotContainsString('{{', $rendered);
    }

    public function test_cs_cannot_modify_configuration_and_scoped_admin_cannot_edit_other_team(): void
    {
        $cs = $this->admin('cs');
        $other = $this->admin('cs', 2);
        $this->actingAs($cs)->get(route('crm.settings'))->assertForbidden();
        $this->postJson(route('crm.save-agent'), ['user_id' => $other->id, 'availability' => 'OFFLINE', 'conversation_cap' => 10])->assertForbidden();
        $admin = $this->admin('admin_operations');
        DB::table('crm_roles')->where('id', $admin->crm_role_id)->update(['scope_type' => 'TEAM']);
        $this->actingAs($admin)->postJson(route('crm.save-agent'), ['user_id' => $other->id, 'availability' => 'OFFLINE', 'conversation_cap' => 10])->assertForbidden();
        $this->postJson(route('crm.save-agent'), ['user_id' => $cs->id, 'availability' => 'ONLINE', 'conversation_cap' => 12])->assertRedirect();
        $this->assertDatabaseHas('crm_agent_profiles', ['user_id' => $cs->id, 'conversation_cap' => 12]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'crm.agent_configured']);
    }

    public function test_stage_editor_preserves_conversion_boundary_and_uses_existing_order_endpoint(): void
    {
        $this->actingAs($this->admin());
        $stage = DB::table('crm_stages')->where('code', 'NEW')->first();
        $this->postJson(route('crm.save-stage', $stage->id), ['name' => 'Lead Baru', 'display_order' => 0, 'color_token' => 'blue', 'sla_hours' => 24, 'transitions' => ['CONVERTED']])->assertUnprocessable();
        $this->postJson(route('crm.save-stage', $stage->id), ['name' => 'Lead Baru', 'display_order' => 0, 'color_token' => 'blue', 'sla_hours' => 24, 'transitions' => ['CONTACTED']])->assertRedirect();
        $ids = DB::table('crm_stages')->orderByDesc('display_order')->pluck('id')->all();
        $this->postJson(route('crm.reorder-stages'), ['ids' => $ids])->assertOk();
        $this->assertSame($ids, DB::table('crm_stages')->orderBy('display_order')->pluck('id')->all());
        $this->assertDatabaseHas('crm_stages', ['id' => $stage->id, 'sla_hours' => 24]);
    }
}
