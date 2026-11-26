<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppVersionTest extends TestCase
{
    public function test_version_endpoint_returns_the_same_version_as_the_page_without_using_a_session(): void
    {
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
