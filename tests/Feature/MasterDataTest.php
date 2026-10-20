<?php

namespace Tests\Feature;

use App\Models\ApplicationBlocker;
use App\Models\Country;
use App\Models\District;
use App\Models\DocumentType;
use App\Models\PipelineStage;
use App\Models\Province;
use App\Models\Regency;
use App\Models\RequirementDefinition;
use App\Models\Task;
use App\Models\User;
use App\Models\Vacancy;
use App\Models\Village;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_region_hierarchy_forms_validation_and_filtering(): void
    {
        $country = Country::factory()->create();
        $parent = $country;
        foreach (['provinsi' => [Province::class, 'country_id'], 'kabupaten-kota' => [Regency::class, 'province_id'], 'kecamatan' => [District::class, 'regency_id'], 'desa-kelurahan' => [Village::class, 'district_id']] as $master => $config) {
            [$model,$field] = $config;
            $this->get(route('master-data.create', $master))->assertOk();
            $data = [$field => $parent->id, 'code' => 'KODE', 'name' => 'Wilayah '.$master];
            $this->post(route('master-data.store', $master), $data)->assertSessionHasNoErrors()->assertRedirect(route('master-data.index', $master));
            $record = $model::firstOrFail();
            $this->assertEquals($parent->id, $record->parent->id);
            $this->get(route('master-data.index', [$master, 'parent_id' => $parent->id, 'search' => 'Wilayah']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
            $this->get(route('master-data.edit', [$master, $record->id]))->assertOk()->assertSee($record->name);
            $this->put(route('master-data.update', [$master, $record->id]), array_replace($data, ['name' => 'Nama Diperbarui']))->assertSessionHasNoErrors();
            $this->assertEquals('Nama Diperbarui', $record->fresh()->name);
            $this->post(route('master-data.store', $master), array_replace($data, [$field => 99999]))->assertSessionHasErrors($field);
            $parent = $record;
        }
        $this->assertEquals(1, Province::first()->regencies()->count());
        $this->assertEquals(1, Regency::first()->districts()->count());
        $this->assertEquals(1, District::first()->villages()->count());
    }

    public function test_document_requirement_and_stage_master_create_edit_and_active_filters(): void
    {
        foreach (['jenis-dokumen' => [DocumentType::class, ['category' => 'Identitas', 'has_expiry' => 1]], 'jenis-persyaratan' => [RequirementDefinition::class, ['category' => 'Umum', 'data_type' => 'integer', 'unit' => 'tahun']], 'tahapan-proses' => [PipelineStage::class, ['sequence' => 3, 'sla_hours' => 24, 'is_terminal' => 0]]] as $master => $config) {
            [$model,$extra] = $config;
            $data = ['code' => 'KODE', 'name' => 'Nama Master', 'is_active' => 1, ...$extra];
            $this->get(route('master-data.create', $master))->assertOk();
            $this->post(route('master-data.store', $master), $data)->assertSessionHasNoErrors();
            $record = $model::firstOrFail();
            $this->post(route('master-data.store', $master), $data)->assertSessionHasErrors('code');
            $this->get(route('master-data.edit', [$master, $record->id]))->assertOk();
            $this->put(route('master-data.update', [$master, $record->id]), array_replace($data, ['is_active' => 0]))->assertSessionHasNoErrors();
            $this->get(route('master-data.index', [$master, 'is_active' => 0]))->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
            $this->get(route('master-data.index', [$master, 'is_active' => 1]))->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        }
        $definition = RequirementDefinition::first();
        Vacancy::factory()->create()->requirements()->create(['requirement_definition_id' => $definition->id, 'operator' => 'gte', 'value' => '18']);
        $this->put(route('master-data.update', ['jenis-persyaratan', $definition->id]), ['code' => $definition->code, 'name' => $definition->name, 'category' => $definition->category, 'data_type' => 'date', 'is_active' => 1])->assertSessionHasErrors('data_type');
        $this->assertEquals('integer', $definition->fresh()->data_type);
    }

    public function test_references_are_database_values_and_navigation_links_are_available(): void
    {
        Vacancy::factory()->count(2)->create(['position_name' => 'Posisi Khusus']);
        Task::factory()->create(['task_type' => 'Tugas Khusus']);
        ApplicationBlocker::factory()->create(['category' => 'Kategori Khusus']);
        foreach (['posisi-pekerjaan' => 'Posisi Khusus', 'jenis-tugas' => 'Tugas Khusus', 'kategori-kendala' => 'Kategori Khusus', 'jenis-perusahaan' => 'Sponsor'] as $reference => $name) {
            $this->get(route('master-reference.index', $reference))->assertOk()->assertSee($name);
            $this->get(route('master-reference.index', [$reference, 'search' => 'TIDAK_ADA_DATA']))->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        }
        $this->get(route('master-data.index', 'jenis-dokumen'))->assertOk()->assertSee('Jenis Dokumen')->assertSee('data-master/jenis-dokumen')->assertSee('aria-current="page"', false);
        $this->get(route('master-data.index', 'unknown'))->assertNotFound();
        $this->get(route('master-data.index', 'provinsi.model'))->assertNotFound();
        $this->get(route('master-reference.index', 'unknown'))->assertNotFound();
        auth()->logout();
        $this->get(route('master-data.index', 'provinsi'))->assertRedirect(route('login'));
    }

    public function test_search_pagination_and_invalid_fields(): void
    {
        Province::factory()->count(26)->create(['name' => 'Provinsi Tes']);
        $this->get(route('master-data.index', ['provinsi', 'search' => 'Provinsi Tes']))->assertViewHas('rows', fn ($rows) => $rows->count() === 25 && str_contains($rows->nextPageUrl(), 'search='));
        $this->post(route('master-data.store', 'tahapan-proses'), ['code' => 'A', 'name' => 'Tahap', 'sequence' => -1, 'sla_hours' => -1, 'is_active' => true, 'is_terminal' => false])->assertSessionHasErrors(['sequence', 'sla_hours']);
        $this->post(route('master-data.store', 'jenis-dokumen'), ['code' => 'A', 'name' => 'Nama', 'is_active' => 'invalid', 'has_expiry' => 'invalid'])->assertSessionHasErrors(['is_active', 'has_expiry']);
        $this->post(route('master-data.store', 'jenis-persyaratan'), ['code' => 'A', 'name' => 'Nama', 'category' => 'Kategori', 'data_type' => 'invalid', 'is_active' => true])->assertSessionHasErrors('data_type');
    }
}
