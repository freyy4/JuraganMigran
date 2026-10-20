<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\JobOrder;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function payload(): array
    {
        return ['code' => 'SP-001', 'name' => 'Mitra Nusantara', 'type' => 'sponsor', 'status' => 'active', 'phone' => '081234567890', 'contacts' => [['name' => 'Rina Utama', 'position' => 'Direktur', 'phone' => '081234567891', 'email' => 'rina@example.com', 'is_primary' => 1], ['name' => 'Andi Kontak', 'is_primary' => 0]]];
    }

    public function test_crud_preserves_contact_ids_and_soft_deletes_company(): void
    {
        $country = Country::factory()->create();
        $data = [...$this->payload(), 'country_id' => $country->id, 'status' => 'draft'];
        $this->get(route('companies.create'))->assertOk()->assertSee($country->name);
        $this->post(route('companies.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $company = Company::firstOrFail();
        $contact = $company->contacts()->where('is_primary', true)->firstOrFail();
        $this->assertCount(2, $company->contacts);
        $this->get(route('companies.index'))->assertOk()->assertSee('Rina Utama')->assertSee($country->name);
        $this->get(route('companies.show', $company))->assertOk()->assertSee('Andi Kontak')->assertSee('PIC utama');
        $this->get(route('companies.edit', $company))->assertOk()->assertSee('Mitra Nusantara');
        $data['name'] = 'Mitra Baru';
        $data['contacts'] = [['id' => $contact->id, 'name' => 'Rina Baru', 'is_primary' => 1]];
        $this->put(route('companies.update', $company), $data)->assertSessionHasNoErrors()->assertRedirect(route('companies.show', $company));
        $this->assertDatabaseHas('company_contacts', ['id' => $contact->id, 'name' => 'Rina Baru']);
        $this->assertSame(1, $company->contacts()->count());
        $this->delete(route('companies.destroy', $company))->assertRedirect(route('companies.index'));
        $this->assertSoftDeleted('companies', ['id' => $company->id]);
        $this->assertDatabaseHas('company_contacts', ['id' => $contact->id]);
        $this->get(route('companies.show', $company))->assertNotFound();
        $this->get(route('companies.index'))->assertDontSee('Mitra Baru');
    }

    public function test_search_filters_and_primary_contact_selection(): void
    {
        $company = Company::factory()->create(['code' => 'KHUSUS-001', 'name' => 'Perusahaan Khusus', 'type' => 'clinic', 'status' => 'inactive', 'phone' => '0899999999']);
        $company->contacts()->create(['name' => 'Kontak Biasa', 'is_primary' => false]);
        $company->contacts()->create(['name' => 'Kontak Pilihan', 'is_primary' => true]);
        Company::factory()->count(26)->create();
        foreach (['KHUSUS-001', 'Perusahaan Khusus', '0899999999', 'Kontak Pilihan'] as $search) {
            $this->get(route('companies.index', ['search' => $search]))->assertOk()->assertSee('Kontak Pilihan')->assertDontSee('Kontak Biasa')->assertViewHas('companies', fn ($rows) => $rows->total() === 1);
        }
        $this->get(route('companies.index', ['search' => 'KHUSUS', 'type' => 'clinic', 'status' => 'inactive']))->assertOk()->assertViewHas('companies', fn ($rows) => $rows->total() === 1);
        $this->get(route('companies.index', ['type' => 'clinic', 'status' => 'active']))->assertOk()->assertSee('Tidak ada perusahaan yang ditemukan.');
        $this->get(route('companies.index', ['type' => 'sponsor', 'status' => 'active']))->assertOk()->assertViewHas('companies', fn ($rows) => $rows->count() === 25 && str_contains($rows->nextPageUrl(), 'type=sponsor') && str_contains($rows->nextPageUrl(), 'status=active'));
    }

    public function test_detail_shows_only_related_orders_and_active_vacancies(): void
    {
        $company = Company::factory()->create();
        $order = JobOrder::factory()->create(['company_id' => $company->id, 'code' => 'JO-TERKAIT']);
        Vacancy::factory()->create(['job_order_id' => $order->id, 'code' => 'VAC-TERKAIT']);
        $deleted = Vacancy::factory()->create(['job_order_id' => $order->id, 'code' => 'VAC-DIHAPUS']);
        $deleted->delete();
        $otherOrder = JobOrder::factory()->create(['code' => 'JO-LAIN']);
        Vacancy::factory()->create(['job_order_id' => $otherOrder->id, 'code' => 'VAC-LAIN']);
        $this->get(route('companies.show', $company))->assertOk()->assertSee('JO-TERKAIT')->assertSee('VAC-TERKAIT')->assertDontSee('JO-LAIN')->assertDontSee('VAC-LAIN')->assertDontSee('VAC-DIHAPUS');
        $this->delete(route('companies.destroy', $company))->assertRedirect();
        $this->assertDatabaseHas('job_orders', ['id' => $order->id]);
        $this->assertDatabaseHas('vacancies', ['code' => 'VAC-TERKAIT', 'deleted_at' => null]);
    }

    public function test_validation_rejects_invalid_fields_and_foreign_contacts(): void
    {
        $data = $this->payload();
        $data['type'] = 'invalid';
        $data['country_ids'] = [9999];
        $data['email'] = 'invalid';
        $data['contacts'][0]['name'] = '';
        $this->post(route('companies.store'), $data)->assertSessionHasErrors(['type', 'country_ids.0', 'email', 'contacts.0.name']);
        $this->assertDatabaseCount('companies', 0);
        $this->post(route('companies.store'), $this->payload())->assertSessionHasNoErrors();
        $this->post(route('companies.store'), $this->payload())->assertSessionHasErrors('code');
        $company = Company::firstOrFail();
        $other = Company::factory()->create();
        $contact = $other->contacts()->create(['name' => 'PIC Milik Lain']);
        $data = $this->payload();
        $data['contacts'][0]['id'] = $contact->id;
        $this->put(route('companies.update', $company), $data)->assertSessionHasErrors('contacts.0.id');
        $this->assertDatabaseHas('company_contacts', ['id' => $contact->id, 'name' => 'PIC Milik Lain']);
        $data = $this->payload();
        $data['contacts'][1]['is_primary'] = 1;
        $this->put(route('companies.update', $company), $data)->assertSessionHasErrors('contacts');
        $data = $this->payload();
        unset($data['contacts']);
        $this->put(route('companies.update', $company), $data)->assertSessionHasNoErrors();
        $this->assertSame(0, $company->contacts()->count());
    }

    public function test_related_lists_paginate_independently(): void
    {
        $company = Company::factory()->create();
        $orders = JobOrder::factory()->count(11)->create(['company_id' => $company->id]);
        Vacancy::factory()->count(11)->create(['job_order_id' => $orders->first()->id]);
        $this->get(route('companies.show', [$company, 'job_orders_page' => 2]))->assertOk()->assertViewHas('jobOrders', fn ($rows) => $rows->count() === 1)->assertViewHas('vacancies', fn ($rows) => $rows->count() === 10);
    }

    public function test_forms_save_multiple_countries_and_preserve_existing_inactive_selections(): void
    {
        $first = Country::factory()->create();
        $second = Country::factory()->create();
        $inactive = Country::factory()->create(['is_active' => false]);
        $this->get(route('companies.create'))->assertOk()->assertSee('country_ids[]', false)->assertViewHas('countries', fn ($rows) => ! $rows->contains('id', $inactive->id));
        $data = [...$this->payload(), 'country_ids' => [$first->id, $second->id]];
        $this->post(route('companies.store'), $data)->assertSessionHasNoErrors();
        $company = Company::firstOrFail();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $company->countries->modelKeys());
        $this->get(route('companies.index'))->assertOk()->assertSee($first->name)->assertSee($second->name);
        $this->get(route('companies.show', $company))->assertOk()->assertSee($first->name)->assertSee($second->name);
        $this->get(route('companies.edit', $company))->assertOk()->assertSee('country_ids[]', false);
        $data['country_ids'] = [$first->id, $first->id];
        $this->put(route('companies.update', $company), $data)->assertSessionHasErrors('country_ids.0');
        $data['country_ids'] = [$inactive->id];
        $this->put(route('companies.update', $company), $data)->assertSessionHasErrors('country_ids.0');
        $second->update(['is_active' => false]);
        $this->get(route('companies.edit', $company))->assertOk()->assertSee($second->name.' (nonaktif)');
        $data['country_ids'] = [$second->id];
        $this->put(route('companies.update', $company), $data)->assertSessionHasNoErrors();
        $this->assertEquals([$second->id], $company->fresh()->countries->modelKeys());
        $this->assertEquals($second->id, $company->fresh()->country_id);
        $this->assertTrue($second->partnerCompanies()->whereKey($company->id)->exists());
        $data['country_ids'] = [];
        $this->put(route('companies.update', $company), $data)->assertSessionHasNoErrors();
        $this->assertCount(0, $company->fresh()->countries);
        $this->assertNull($company->fresh()->country_id);
    }

    public function test_migration_copies_legacy_country_and_checkbox_form_can_clear_selection(): void
    {
        $country = Country::factory()->create();
        $company = Company::factory()->create(['country_id' => $country->id]);
        $migration = require database_path('migrations/2026_10_03_083824_create_company_countries_table.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('company_countries', ['company_id' => $company->id, 'country_id' => $country->id]);
        $data = [...$this->payload(), 'code' => $company->code, 'countries_present' => '1'];
        $this->put(route('companies.update', $company), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('company_countries', ['company_id' => $company->id]);
        $this->assertNull($company->fresh()->country_id);
    }
}
