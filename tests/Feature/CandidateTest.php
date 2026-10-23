<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function payload(): array
    {
        return ['candidate_code' => 'CPMI-001', 'status' => 'lead', 'registered_at' => '2026-10-03T09:00', 'person' => ['full_name' => 'Siti Aminah', 'nik' => '1234567890123456', 'whatsapp' => '081234567890', 'birth_date' => '1995-01-01', 'education_level' => 'SMA'], 'contacts' => [['name' => 'Ibu Siti', 'relationship' => 'Ibu', 'phone' => '081234567891', 'contact_type' => 'family', 'is_emergency' => 1, 'is_primary' => 1]]];
    }

    public function test_create_detail_edit_and_soft_delete(): void
    {
        $this->get(route('candidates.create'))->assertOk()->assertSee('Tambah Calon Pekerja');
        $this->post(route('candidates.store'), $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $candidate = Candidate::with(['person', 'contacts'])->firstOrFail();
        $this->assertNotNull($candidate->person->uuid);
        $this->assertSame('Siti Aminah', $candidate->person->full_name);
        $this->assertCount(1, $candidate->contacts);
        $this->get(route('candidates.show', $candidate))->assertOk()->assertSee('Ibu Siti')->assertSee('Kontak darurat')->assertSee('SMA');
        $this->get(route('candidates.edit', $candidate))->assertOk()->assertSee('Siti Aminah');
        $data = $this->payload();
        $data['person']['full_name'] = 'Siti Baru';
        $data['contacts'][0]['name'] = 'Ayah Siti';
        $this->put(route('candidates.update', $candidate), $data)->assertSessionHasNoErrors()->assertRedirect(route('candidates.show', $candidate));
        $this->assertDatabaseHas('persons', ['id' => $candidate->person_id, 'full_name' => 'Siti Baru']);
        $this->assertDatabaseHas('candidate_contacts', ['candidate_id' => $candidate->id, 'name' => 'Ayah Siti']);
        $this->assertDatabaseMissing('candidate_contacts', ['name' => 'Ibu Siti']);
        unset($data['contacts']);
        $this->put(route('candidates.update', $candidate), $data)->assertSessionHasNoErrors();
        $this->assertSame(0, $candidate->contacts()->count());
        $this->delete(route('candidates.destroy', $candidate))->assertRedirect(route('candidates.index'));
        $this->assertSoftDeleted('candidates', ['id' => $candidate->id]);
        $this->assertDatabaseHas('persons', ['id' => $candidate->person_id, 'deleted_at' => null]);
        $this->get(route('candidates.show', $candidate))->assertNotFound();
        $this->get(route('candidates.index'))->assertOk()->assertDontSee('CPMI-001');
    }

    public function test_search_filter_and_pagination(): void
    {
        $candidate = Candidate::factory()->create(['candidate_code' => 'CPMI-CARI', 'status' => 'terdaftar']);
        $candidate->person->update(['full_name' => 'Nama Khusus', 'nik' => '9876543210123456', 'whatsapp' => '089999999999']);
        Candidate::factory()->count(16)->create();
        foreach (['Nama Khusus', '9876543210123456', '089999999999', 'CPMI-CARI'] as $search) {
            $this->get(route('candidates.index', ['search' => $search]))->assertOk()->assertSee('CPMI-CARI')->assertViewHas('candidates', fn ($rows) => $rows->total() === 1);
        }
        $this->get(route('candidates.index', ['status' => 'terdaftar']))->assertOk()->assertViewHas('candidates', fn ($rows) => $rows->total() === 1);
        $this->get(route('candidates.index', ['search' => 'CPMI', 'status' => 'lead', 'per_page' => 10]))->assertOk()->assertViewHas('candidates', fn ($rows) => $rows->count() === 10 && str_contains($rows->nextPageUrl(), 'status=lead') && str_contains($rows->nextPageUrl(), 'search=CPMI'));
        $this->get(route('candidates.index', ['search' => 'tidak ditemukan']))->assertOk()->assertSee('Tidak ada calon pekerja yang sesuai.');
    }

    public function test_validation_prevents_partial_writes_and_duplicate_identifiers(): void
    {
        $data = $this->payload();
        $data['contacts'][0]['name'] = '';
        $this->post(route('candidates.store'), $data)->assertSessionHasErrors('contacts.0.name');
        $this->assertDatabaseCount('persons', 0);
        $this->post(route('candidates.store'), $this->payload())->assertSessionHasNoErrors();
        $this->post(route('candidates.store'), $this->payload())->assertSessionHasErrors(['candidate_code', 'person.nik']);
        $data = $this->payload();
        $data['person']['birth_date'] = '2999-01-01';
        $data['person']['nik'] = '123';
        $data['person']['whatsapp'] = 'bukan nomor';
        $this->post(route('candidates.store'), $data)->assertSessionHasErrors(['person.birth_date', 'person.nik', 'person.whatsapp']);
        $this->assertDatabaseCount('candidates', 1);
    }

    public function test_autosave_persists_canonical_profile_and_rejects_stale_version(): void
    {
        $this->post(route('candidates.store'), $this->payload())->assertSessionHasNoErrors();
        $candidate = Candidate::firstOrFail();
        $data = $this->payload();
        $data['profile_version'] = $candidate->updated_at->toISOString();
        $data['person']['height_cm'] = 162;
        $response = $this->putJson(route('candidates.update', $candidate), $data)->assertOk()->assertJsonPath('saved', true);
        $this->assertDatabaseHas('persons', ['id' => $candidate->person_id, 'height_cm' => 162]);
        $this->putJson(route('candidates.update', $candidate), $data)->assertStatus(409);
        $data['profile_version'] = $response->json('version');
        $this->putJson(route('candidates.update', $candidate), $data)->assertOk();
        $this->assertDatabaseCount('candidates', 1);
    }

    public function test_duplicate_phone_is_rejected_across_normalized_contact_fields(): void
    {
        $this->post(route('candidates.store'), $this->payload())->assertSessionHasNoErrors();
        $data = $this->payload();
        $data['candidate_code'] = 'CPMI-SECOND';
        $data['person']['nik'] = '9999999999999999';
        $data['person']['phone'] = '+62 812-3456-7890';
        unset($data['person']['whatsapp']);
        $this->postJson(route('candidates.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('person.phone');
        $this->assertDatabaseCount('candidates', 1);
    }
}
