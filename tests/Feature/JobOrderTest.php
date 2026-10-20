<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\JobOrder;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function payload(): array
    {
        return ['company_id' => Company::factory()->create()->id, 'country_id' => Country::factory()->create()->id, 'reference_number' => 'REF-KHUSUS', 'quota' => 50, 'valid_from' => '2026-10-01', 'valid_until' => '2027-10-01', 'status' => 'draft'];
    }

    public function test_crud_generates_unique_codes_and_keeps_code_on_edit(): void
    {
        $data = $this->payload();
        $this->get(route('job-orders.create'))->assertOk()->assertSee('Kode dibuat otomatis');
        $this->post(route('job-orders.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $order = JobOrder::firstOrFail();
        $this->assertStringStartsWith('JO-', $order->code);
        $this->post(route('job-orders.store'), $data)->assertSessionHasNoErrors();
        $this->assertNotSame($order->code, JobOrder::orderByDesc('id')->first()->code);
        $this->get(route('job-orders.show', $order))->assertOk()->assertSee('REF-KHUSUS')->assertSee('Belum ada lowongan');
        $this->get(route('job-orders.edit', $order))->assertOk()->assertSee('REF-KHUSUS');
        $data['quota'] = 60;
        $data['status'] = 'approved';
        $data['code'] = 'TIDAK-BOLEH-BERUBAH';
        $this->put(route('job-orders.update', $order), $data)->assertSessionHasNoErrors()->assertRedirect(route('job-orders.show', $order));
        $this->assertDatabaseHas('job_orders', ['id' => $order->id, 'quota' => 60, 'status' => 'approved', 'code' => $order->code]);
        $this->delete(route('job-orders.destroy', $order))->assertRedirect(route('job-orders.index'));
        $this->assertDatabaseMissing('job_orders', ['id' => $order->id]);
        $this->get(route('job-orders.show', $order))->assertNotFound();
    }

    public function test_search_filters_and_pagination(): void
    {
        $data = $this->payload();
        $company = Company::findOrFail($data['company_id']);
        $company->update(['name' => 'Sponsor Khusus']);
        $order = JobOrder::factory()->create([...$data, 'code' => 'JO-KHUSUS']);
        JobOrder::factory()->count(16)->create(['status' => 'draft']);
        foreach (['JO-KHUSUS', 'REF-KHUSUS', 'Sponsor Khusus'] as $search) {
            $this->get(route('job-orders.index', ['search' => $search]))->assertOk()->assertSee('JO-KHUSUS')->assertViewHas('orders', fn ($rows) => $rows->total() === 1);
        }
        $this->get(route('job-orders.index', ['company_id' => $company->id, 'country_id' => $data['country_id'], 'status' => 'draft']))->assertOk()->assertViewHas('orders', fn ($rows) => $rows->total() === 1);
        $this->get(route('job-orders.index', ['company_id' => $company->id, 'status' => 'closed']))->assertOk()->assertSee('Tidak ada permintaan');
        $this->get(route('job-orders.index', ['status' => 'draft', 'search' => 'JO-']))->assertOk()->assertViewHas('orders', fn ($rows) => $rows->count() === 15 && str_contains($rows->nextPageUrl(), 'status=draft') && str_contains($rows->nextPageUrl(), 'search=JO-'));
    }

    public function test_detail_limits_related_vacancies_and_deletion_keeps_related_data(): void
    {
        $order = JobOrder::factory()->create();
        Vacancy::factory()->create(['job_order_id' => $order->id, 'code' => 'VAC-TERKAIT']);
        $deleted = Vacancy::factory()->create(['job_order_id' => $order->id, 'code' => 'VAC-DIHAPUS']);
        $deleted->delete();
        Vacancy::factory()->create(['code' => 'VAC-LAIN']);
        $this->get(route('job-orders.show', $order))->assertOk()->assertSee('VAC-TERKAIT')->assertDontSee('VAC-DIHAPUS')->assertDontSee('VAC-LAIN');
        $this->from(route('job-orders.show', $order))->delete(route('job-orders.destroy', $order))->assertSessionHasErrors('delete');
        $this->assertDatabaseHas('job_orders', ['id' => $order->id]);
        $this->assertDatabaseHas('vacancies', ['code' => 'VAC-TERKAIT']);
        $orderOnlyDeleted = JobOrder::factory()->create();
        Vacancy::factory()->create(['job_order_id' => $orderOnlyDeleted->id])->delete();
        $this->delete(route('job-orders.destroy', $orderOnlyDeleted))->assertSessionHasErrors('delete');
        $this->assertDatabaseHas('job_orders', ['id' => $orderOnlyDeleted->id]);
        Vacancy::factory()->count(15)->create(['job_order_id' => $order->id]);
        $this->get(route('job-orders.show', [$order, 'page' => 2]))->assertOk()->assertViewHas('vacancies', fn ($rows) => $rows->count() === 1);
    }

    public function test_validation_rejects_bad_dates_quota_and_foreign_keys(): void
    {
        $data = $this->payload();
        $data['valid_until'] = '2026-09-01';
        $data['quota'] = -1;
        $data['company_id'] = 999999;
        $data['country_id'] = 999999;
        $data['status'] = 'unknown';
        $this->post(route('job-orders.store'), $data)->assertSessionHasErrors(['valid_until', 'quota', 'company_id', 'country_id', 'status']);
        $this->assertDatabaseCount('job_orders', 0);
        $data = $this->payload();
        $data['quota'] = 4294967296;
        $this->post(route('job-orders.store'), $data)->assertSessionHasErrors('quota');
        $data['quota'] = 0;
        $data['valid_until'] = $data['valid_from'];
        $this->post(route('job-orders.store'), $data)->assertSessionHasNoErrors();
        $data['valid_from'] = null;
        $this->post(route('job-orders.store'), $data)->assertSessionHasNoErrors();
        $data['valid_until'] = null;
        $this->post(route('job-orders.store'), $data)->assertSessionHasNoErrors();
    }

    public function test_deleted_partner_preserves_history_but_cannot_be_selected_for_new_order(): void
    {
        $data = $this->payload();
        $order = JobOrder::factory()->create($data);
        $company = Company::findOrFail($data['company_id']);
        $company->delete();
        $this->get(route('job-orders.index'))->assertOk()->assertSee($company->name);
        $this->get(route('job-orders.edit', $order))->assertOk()->assertSee($company->name);
        $this->put(route('job-orders.update', $order), $data)->assertSessionHasNoErrors();
        $this->post(route('job-orders.store'), $data)->assertSessionHasErrors('company_id');
    }
}
