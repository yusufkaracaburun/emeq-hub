<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_up_reports_200_when_every_dependency_answers(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertJson([
                'status' => 'up',
                'database' => 'ok',
                'redis' => 'ok',
            ]);
    }

    public function test_up_returns_503_when_redis_is_unreachable(): void
    {
        Redis::shouldReceive('ping')->andThrow(new RuntimeException('Connection refused'));

        $this->get('/up')
            ->assertStatus(503)
            ->assertJson([
                'status' => 'degraded',
                'database' => 'ok',
                'redis' => 'fail',
            ]);
    }

    public function test_up_returns_503_when_redis_answers_but_not_with_pong(): void
    {
        Redis::shouldReceive('ping')->andReturn('');

        $this->get('/up')
            ->assertStatus(503)
            ->assertJson(['status' => 'degraded', 'redis' => 'fail']);
    }
}
