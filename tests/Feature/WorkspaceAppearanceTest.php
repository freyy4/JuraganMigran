<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Services\AccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WorkspaceAppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_and_forms_share_the_theme_stylesheet_and_render_with_existing_values(): void
    {
        $this->actingAs(User::factory()->create());
        $application = Application::factory()->create();
        $pages = [];
        foreach (config('navigation') as $group) {
            foreach ($group['items'] as $item) {
                $permission = app(AccessPolicy::class)->routePermission($item['route']);
                if ($permission && ! auth()->user()->hasPermission($permission)) {
                    continue;
                }
                $pages[$item['key']] = route($item['route'], $item['parameters'] ?? []);
            }
        }
        foreach (['candidates', 'companies', 'job-orders', 'vacancies', 'tasks', 'blockers', 'interviews', 'medical-events', 'training', 'training-batches', 'flights', 'countries', 'agreements', 'sponsor-payments', 'benefits', 'obligations', 'settings.users', 'settings.roles'] as $name) {
            if (Route::has($name.'.create')) {
                $pages['form-'.$name] = route($name.'.create');
            }
        }
        $pages['form-candidate-edit'] = route('candidates.edit', $application->candidate_id);
        $pages['form-master'] = route('master-data.create', ['master' => 'jenis-dokumen']);
        $pages['form-health-template'] = route('health-templates.create');
        $pages['form-document-requirement'] = route('document-requirements.create');
        $manifest = [];
        $directory = getenv('WORKSPACE_QA_FIXTURES');
        if ($directory && ! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        foreach ($pages as $key => $url) {
            $response = $this->followingRedirects()->get($url);
            $this->assertSame(200, $response->status(), $key.' must render');
            $response->assertSee('workspace-appearance.css')->assertSee('data-theme-toggle', false);
            if ($key === 'form-candidate-edit') {
                $response->assertSee($application->candidate->candidate_code)->assertSee('name="person[full_name]"', false);
            }
            if ($directory) {
                file_put_contents($directory.'/'.$key.'.html', $response->getContent());
                $manifest[] = ['key' => $key, 'url' => $url];
            }
        }
        if ($directory) {
            file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        }
        $this->assertGreaterThan(60, count($pages));
    }
}
