<?php

namespace Tests\Feature;

use Tests\TestCase;

class BootstrapTest extends TestCase
{
    public function test_health_route_returns_ok(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_unknown_route_returns_not_found(): void
    {
        $this->get('/unknown-route')->assertNotFound();
    }
}
