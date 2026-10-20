<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_shows_public_website_for_guests(): void
    {
        $this->get('/')->assertOk()->assertSee('Pekerjaan Bagus');
    }

    public function test_root_remains_public_for_authenticated_users(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/')->assertOk()->assertSee('Lowongan Kerja');
    }

    public function test_removed_homepage_builder_endpoints_are_unavailable(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/admin/homepage/builder')->assertNotFound();
        foreach (['preview', 'save', 'publish'] as $action) {
            $this->postJson('/admin/homepage/'.$action, [])->assertNotFound();
        }
    }
}
