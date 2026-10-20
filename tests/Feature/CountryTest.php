<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\JobOrder;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_create_edit_status_search_and_pagination(): void
    {
        $this->get(route('countries.create'))->assertOk();
        $this->post(route('countries.store'), ['code' => ' id ', 'name' => ' Indonesia ', 'is_active' => 1])->assertSessionHasNoErrors()->assertRedirect(route('countries.index'));
        $country = Country::firstOrFail();
        $this->assertEquals('ID', $country->code);
        $this->get(route('countries.edit', $country))->assertOk()->assertSee('Indonesia');
        $this->put(route('countries.update', $country), ['code' => 'IDN', 'name' => 'Indonesia', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->patch(route('countries.status', $country), ['is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($country->fresh()->is_active);
        $this->get(route('countries.index', ['search' => 'IDN', 'is_active' => 0]))->assertOk()->assertSee('Nonaktif')->assertViewHas('countries', fn ($rows) => $rows->total() === 1);
        $this->get(route('countries.index', ['search' => 'Indonesia', 'is_active' => 1]))->assertViewHas('countries', fn ($rows) => $rows->total() === 0);
        $this->patch(route('countries.status', $country), ['is_active' => 1])->assertSessionHasNoErrors();
        Country::factory()->count(15)->create();
        $this->get(route('countries.index', ['is_active' => 1, 'per_page' => 10]))->assertViewHas('countries', fn ($rows) => $rows->count() === 10 && str_contains($rows->nextPageUrl(), 'is_active=1'));
    }

    public function test_validation_and_authentication(): void
    {
        Country::factory()->create(['code' => 'ID']);
        $this->post(route('countries.store'), ['code' => 'id', 'name' => 'Duplikat', 'is_active' => 1])->assertSessionHasErrors('code');
        $this->post(route('countries.store'), ['code' => '123', 'name' => '', 'is_active' => 'invalid'])->assertSessionHasErrors(['code', 'name', 'is_active']);
        $this->post(route('countries.store'), ['code' => 'ABCD', 'name' => 'Nama', 'is_active' => 1])->assertSessionHasErrors('code');
        $this->get(route('countries.index', ['is_active' => 'invalid']))->assertSessionHasErrors('is_active');
        $country = Country::first();
        $this->patch(route('countries.status', $country), ['is_active' => null])->assertSessionHasErrors('is_active');
        $this->assertDatabaseCount('countries', 1);
        auth()->logout();
        $this->get(route('countries.index'))->assertRedirect(route('login'));
        $this->post(route('countries.store'), ['code' => 'JP', 'name' => 'Jepang', 'is_active' => 1])->assertRedirect(route('login'));
    }

    public function test_job_orders_only_allow_active_countries_or_existing_country(): void
    {
        $active = Country::factory()->create(['name' => 'Negara Aktif']);
        $inactive = Country::factory()->create(['name' => 'Negara Nonaktif', 'is_active' => false]);
        $order = JobOrder::factory()->create(['country_id' => $inactive->id]);
        $this->get(route('job-orders.create'))->assertViewHas('countries', fn ($rows) => $rows->contains('id', $active->id) && ! $rows->contains('id', $inactive->id));
        $data = ['company_id' => $order->company_id, 'country_id' => $inactive->id, 'quota' => 10, 'status' => 'draft'];
        $this->post(route('job-orders.store'), $data)->assertSessionHasErrors('country_id');
        $this->get(route('job-orders.edit', $order))->assertOk()->assertSee('Negara Nonaktif (nonaktif)');
        $this->put(route('job-orders.update', $order), $data)->assertSessionHasNoErrors();
        $this->assertEquals($order->id, $inactive->jobOrders()->first()->id);
        $this->patch(route('countries.status', $inactive), ['is_active' => 1]);
        $this->post(route('job-orders.store'), $data)->assertSessionHasNoErrors();
    }

    public function test_vacancies_derive_country_from_order_and_keep_historical_selection(): void
    {
        $inactive = Country::factory()->create(['name' => 'Negara Lama', 'is_active' => false]);
        $oldOrder = JobOrder::factory()->create(['country_id' => $inactive->id]);
        $activeOrder = JobOrder::factory()->create();
        $vacancy = Vacancy::factory()->create(['job_order_id' => $oldOrder->id]);
        $this->get(route('vacancies.create'))->assertOk()->assertSee('Negara tujuan')->assertViewHas('orders', fn ($rows) => $rows->contains('id', $activeOrder->id) && ! $rows->contains('id', $oldOrder->id))->assertViewHas('countries', fn ($rows) => ! $rows->contains('id', $inactive->id));
        $data = ['code' => 'VAC-NEW', 'job_order_id' => $oldOrder->id, 'title' => 'Lowongan Baru', 'position_name' => 'Pekerja', 'quota' => 10, 'status' => 'draft'];
        $this->post(route('vacancies.store'), $data)->assertSessionHasErrors('job_order_id');
        $this->get(route('vacancies.edit', $vacancy))->assertOk()->assertSee('Negara Lama (nonaktif)')->assertViewHas('orders', fn ($rows) => $rows->contains('id', $oldOrder->id));
        $this->put(route('vacancies.update', $vacancy), array_replace($data, ['code' => $vacancy->code]))->assertSessionHasNoErrors();
        $this->post(route('vacancies.store'), array_replace($data, ['job_order_id' => $activeOrder->id]))->assertSessionHasNoErrors();
        $this->get(route('vacancies.show', Vacancy::where('code', 'VAC-NEW')->first()))->assertOk()->assertSee($activeOrder->country->name);
    }
}
