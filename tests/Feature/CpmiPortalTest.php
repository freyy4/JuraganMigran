<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class CpmiPortalTest extends TestCase
{
    use RefreshDatabase;

    private function account(Application $application): User
    {
        $user = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cpmi')->value('id')]);
        DB::table('portal_accounts')->insert(['candidate_id' => $application->candidate_id, 'user_id' => $user->id, 'normalized_phone' => '+628'.random_int(1000000000, 9999999999), 'status' => 'active', 'phone_verified_at' => now(), 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public function test_portal_uses_session_identity_and_never_exposes_other_candidates_or_internal_notes(): void
    {
        $mine = Application::factory()->create();
        $other = Application::factory()->create();
        $name = $mine->candidate->person->full_name;
        $otherName = $other->candidate->person->full_name;
        $user = $this->account($mine);
        DB::table('cpmi_requests')->insert(['candidate_id' => $mine->candidate_id, 'type' => 'callback', 'status' => 'REVIEWING', 'payload' => '{}', 'public_note' => 'Kami akan menghubungi Anda.', 'internal_note' => 'Catatan rahasia internal', 'idempotency_key' => Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->get(route('portal.home', ['candidate_id' => $other->candidate_id]))->assertOk()->assertSee($name)->assertDontSee($otherName);
        foreach (['process', 'documents', 'schedules', 'requests', 'help', 'profile', 'notifications'] as $page) {
            $this->get(route('portal.'.$page))->assertOk()->assertDontSee('Catatan rahasia internal');
        }
        $this->getJson(route('portal.api.requests', ['candidate_id' => $other->candidate_id]))->assertOk()->assertDontSee('Catatan rahasia internal');
        $this->get(route('candidates.index'))->assertForbidden();
    }

    public function test_mobile_home_prioritizes_own_actions_and_safe_notifications(): void
    {
        $mine = Application::factory()->create();
        $other = Application::factory()->create();
        $user = $this->account($mine);
        foreach ([$mine->candidate_id => 'Jadwal Anda telah ditetapkan', $other->candidate_id => 'Notifikasi pribadi orang lain'] as $id => $title) {
            DB::table('portal_notifications')->insert(['candidate_id' => $id, 'category' => 'SCHEDULE', 'title' => $title, 'body_safe' => 'Periksa jadwal pada portal.', 'deep_link' => '/portal/schedules', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($user)->get(route('portal.home'))->assertOk()->assertSee('Progres Anda')->assertSee('Ringkasan Anda')->assertSee('Dokumen terverifikasi')->assertSee('data-install', false)->assertSee('portal.webmanifest')->assertSee('TAHAP ANDA SEKARANG')->assertSee('Upload dokumen')->assertSee('Hubungi CS')->assertSee('Jadwal Anda telah ditetapkan')->assertSee('Ada notifikasi belum dibaca')->assertDontSee('Notifikasi pribadi orang lain')->assertDontSee('Exposure / klaim');
    }

    public function test_portal_login_accepts_email_and_rejects_wrong_password_or_staff_account(): void
    {
        $user = $this->account(Application::factory()->create());
        $this->post(route('portal.login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('identifier');
        $this->assertGuest();
        $staff = User::factory()->create();
        $this->post(route('portal.login.store'), ['email' => $staff->email, 'password' => 'password'])
            ->assertSessionHasErrors('identifier');
        $this->assertGuest();
        $this->trustPortalDevice($user);
        $this->post(route('portal.login.store'), ['email' => '  '.strtoupper($user->email).'  ', 'password' => 'password'])
            ->assertRedirect(route('portal.home'));
        $this->assertAuthenticatedAs($user);
        $this->get(route('portal.profile'))->assertOk()->assertSee($user->email)->assertSee('Email login');
    }

    public function test_login_supports_installation_with_complete_public_assets(): void
    {
        $this->get(route('portal.login'))->assertOk()
            ->assertSee('portal.webmanifest')->assertSee('authentication.js')
            ->assertSee('data-password-toggle', false)->assertSee('data-install', false);

        $manifest = json_decode(file_get_contents(public_path('portal.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('/portal', $manifest['start_url']);
        $this->assertSame('/portal', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        foreach ($manifest['icons'] as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($path);
            $dimensions = getimagesize($path);
            $this->assertSame($icon['sizes'], $dimensions[0].'x'.$dimensions[1]);
        }
        $this->assertFileExists(public_path('portal-sw.js'));
        $this->assertFileExists(public_path('portal-offline.html'));
    }

    public function test_profile_correction_is_idempotent_and_does_not_overwrite_verified_identity(): void
    {
        $application = Application::factory()->create();
        $person = $application->candidate->person;
        $user = $this->account($application);
        $payload = ['description' => 'Mohon perbaiki nama saya.', 'idempotency_key' => (string) Str::uuid(), 'full_name' => 'Nama palsu'];
        $this->actingAs($user)->postJson(route('portal.profile.update'), $payload)->assertOk();
        $this->postJson(route('portal.profile.update'), $payload)->assertOk();
        $this->assertDatabaseCount('cpmi_requests', 1);
        $this->assertSame($person->full_name, $person->fresh()->full_name);
        $this->assertDatabaseHas('cpmi_requests', ['candidate_id' => $application->candidate_id, 'type' => 'profile_correction']);
    }

    public function test_notification_ownership_and_revoked_account_are_enforced(): void
    {
        $mine = Application::factory()->create();
        $other = Application::factory()->create();
        $user = $this->account($mine);
        $notification = DB::table('portal_notifications')->insertGetId(['candidate_id' => $other->candidate_id, 'category' => 'REQUEST_UPDATE', 'title' => 'Rahasia', 'body_safe' => 'Rahasia', 'deep_link' => '/portal/requests', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->postJson(route('portal.notifications.read', $notification))->assertNotFound();
        DB::table('portal_accounts')->where('user_id', $user->id)->update(['status' => 'revoked']);
        $this->get(route('portal.home'))->assertRedirect(route('portal.login'))->assertSessionHasErrors('email');
    }

    public function test_invitation_is_single_use_and_rejects_identity_number_as_password(): void
    {
        $application = Application::factory()->create();
        $application->candidate->person->update(['full_name' => 'Siti Aminah', 'whatsapp' => '081234567890', 'nik' => '3271000000000012']);
        $token = Str::random(64);
        DB::table('portal_invitations')->insert(['candidate_id' => $application->candidate_id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'contact_verified' => true, 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->post(route('portal.activate', $token), ['password' => '3271000000000012', 'password_confirmation' => '3271000000000012', 'consent' => '1'])->assertSessionHasErrors('password');
        $this->post(route('portal.activate', $token), ['password' => 'Portal!password123', 'password_confirmation' => 'Portal!password123', 'consent' => '1'])->assertRedirect(route('portal.home'));
        $this->get(route('portal.invitation', $token))->assertNotFound();
        $this->get(route('portal.home'))->assertOk();
        $this->assertDatabaseHas('portal_accounts', ['candidate_id' => $application->candidate_id, 'status' => 'active']);
        $this->assertDatabaseHas('users', ['email' => 'sitiaminah@juraganmigran.my.id']);
    }

    public function test_generated_portal_email_handles_duplicate_names_and_legacy_recovery(): void
    {
        $application = Application::factory()->create();
        $application->candidate->person->update(['full_name' => 'Siti Aminah']);
        User::factory()->create(['email' => 'sitiaminah@juraganmigran.my.id']);
        $user = $this->account($application);
        $user->update(['email' => 'portal-old@portal.invalid']);
        $token = Str::random(64);
        DB::table('portal_invitations')->insert(['candidate_id' => $application->candidate_id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'contact_verified' => true, 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->post(route('portal.activate', $token), ['password' => 'Portal!password123', 'password_confirmation' => 'Portal!password123', 'consent' => '1'])->assertRedirect(route('portal.home'));
        $this->assertSame('sitiaminah.2@juraganmigran.my.id', $user->fresh()->email);
        $this->assertDatabaseHas('portal_accounts', ['candidate_id' => $application->candidate_id, 'user_id' => $user->id]);
    }

    public function test_private_upload_retry_and_signed_file_access_are_bound_to_the_logged_in_cpmi(): void
    {
        Storage::fake('documents');
        $mine = Application::factory()->create();
        $other = Application::factory()->create();
        $type = DocumentType::factory()->create();
        $requirement = $mine->vacancy->documentRequirements()->create(['document_type_id' => $type->id, 'is_required' => true]);
        $otherRequirement = $other->vacancy->documentRequirements()->create(['document_type_id' => $type->id, 'is_required' => true]);
        $user = $this->account($mine);
        $key = (string) Str::uuid();
        $payload = ['upload_key' => $key, 'candidate_id' => $other->candidate_id, 'file' => UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf')];
        $this->actingAs($user)->postJson(route('portal.upload', $otherRequirement->id), $payload)->assertNotFound();
        $this->postJson(route('portal.upload', $requirement->id), $payload)->assertOk();
        $this->postJson(route('portal.upload', $requirement->id), $payload)->assertOk();
        $this->assertDatabaseCount('candidate_documents', 1);
        $document = CandidateDocument::firstOrFail();
        $this->assertSame($mine->candidate_id, $document->candidate_id);
        $signed = URL::temporarySignedRoute('portal.document.file', now()->addMinutes(5), $document->id);
        $this->get(route('portal.document.file', $document->id))->assertNotFound();
        $this->get($signed)->assertOk()->assertDownload();
        $otherUser = $this->account($other);
        $this->actingAs($otherUser)->get($signed)->assertNotFound();
        $this->actingAs($user);
        $this->travel(6)->minutes();
        $this->get($signed)->assertNotFound();
    }

    public function test_upload_is_not_saved_when_required_malware_scanner_is_unavailable(): void
    {
        Storage::fake('documents');
        config(['documents.scan_enabled' => true, 'documents.scanner_binary' => '/missing-jm-scanner']);
        $application = Application::factory()->create();
        $type = DocumentType::factory()->create();
        $requirement = $application->vacancy->documentRequirements()->create(['document_type_id' => $type->id, 'is_required' => true]);
        $this->actingAs($this->account($application))->postJson(route('portal.upload', $requirement->id), ['upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('candidate_documents', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }
}
