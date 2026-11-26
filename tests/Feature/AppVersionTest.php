<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    public function test_packaged_release_id_is_used_by_the_endpoint_layout_and_assets(): void
    {
        $version = str_repeat('a', 64);
        $bootstrapPath = sys_get_temp_dir().'/rank-it-'.Str::uuid();
        File::makeDirectory($bootstrapPath);

        try {
            File::put($bootstrapPath.'/release-id', $version."\n");
            $this->app->useBootstrapPath($bootstrapPath);

            $this->getJson('/app-version')->assertOk()->assertExactJson(['version' => $version]);
            $this->get('/login')->assertOk()
                ->assertSee('data-app-version="'.$version.'"', false)
                ->assertSee('theme.js?v='.$version)
                ->assertSee('app-update.js?v='.$version)
                ->assertSee('app.css?v='.$version);

            $this->actingAs(User::factory()->make())->get('/profile/password')->assertOk()
                ->assertSeeInOrder(['Log out', 'Version aaaaaaaaaaaa</p>', '</nav>'], false);
        } finally {
            File::deleteDirectory($bootstrapPath);
        }
    }

    public function test_version_endpoint_returns_the_same_version_as_the_page_without_using_a_session(): void
    {
        $this->app->useBootstrapPath(sys_get_temp_dir().'/rank-it-'.Str::uuid());

        $response = $this->getJson('/app-version');

        $response->assertOk()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertHeaderMissing('Set-Cookie')
            ->assertExactJsonStructure(['version']);
        $version = $response->json('version');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $version);

        $this->get('/login')->assertOk()->assertSee('data-app-version="'.$version.'"', false);
    }
}
