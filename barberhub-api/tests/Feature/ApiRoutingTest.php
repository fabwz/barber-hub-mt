<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ApiRoutingTest extends TestCase
{
    /** The API is served under the versioned prefix. */
    public function test_api_is_served_under_the_v1_prefix(): void
    {
        $this->get('/api/v1')
            ->assertOk()
            ->assertExactJson(['nombre' => config('app.name'), 'version' => 'v1']);
    }

    /** Unversioned paths are not routed. */
    public function test_unversioned_api_path_is_not_routed(): void
    {
        $this->get('/api')->assertNotFound();
    }
}
