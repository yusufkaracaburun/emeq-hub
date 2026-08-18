<?php

namespace Tests\Feature\Console;

use App\Console\Commands\HubConsumerCreate;
use App\Models\Consumer;
use App\Services\ConsumerOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class HubConsumerCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_consumer_with_default_admin_ability(): void
    {
        $this->artisan('hub:consumer:create', [
            '--slug' => 'naschool-test',
            '--name' => 'Naschool Test',
        ])->assertExitCode(0);

        $consumer = Consumer::where('slug', 'naschool-test')->first();
        $this->assertNotNull($consumer);
        $this->assertSame('Naschool Test', $consumer->name);

        $token = PersonalAccessToken::where('tokenable_id', $consumer->id)
            ->where('tokenable_type', Consumer::class)
            ->first();
        $this->assertNotNull($token);
        $this->assertSame(['*'], $token->abilities);
    }

    public function test_fails_with_invalid_exit_when_slug_missing(): void
    {
        $this->artisan('hub:consumer:create', ['--name' => 'Only Name'])
            ->expectsOutputToContain('verplicht')
            ->assertExitCode(2);

        $this->assertSame(0, Consumer::count());
    }

    public function test_fails_with_invalid_exit_when_name_missing(): void
    {
        $this->artisan('hub:consumer:create', ['--slug' => 'only-slug'])
            ->assertExitCode(2);

        $this->assertSame(0, Consumer::count());
    }

    public function test_duplicate_slug_returns_failure_exit_code(): void
    {
        $this->artisan('hub:consumer:create', [
            '--slug' => 'dup',
            '--name' => 'First',
        ])->assertExitCode(0);

        $this->artisan('hub:consumer:create', [
            '--slug' => 'dup',
            '--name' => 'Second',
        ])->assertExitCode(1);

        $this->assertSame(1, Consumer::where('slug', 'dup')->count());
    }

    public function test_abilities_csv_is_split_into_array(): void
    {
        $this->artisan('hub:consumer:create', [
            '--slug' => 'abilities-test',
            '--name' => 'Abilities Test',
            '--abilities' => 'snelstart:read,snelstart:write',
        ])->assertExitCode(0);

        $consumer = Consumer::where('slug', 'abilities-test')->first();
        $token = PersonalAccessToken::where('tokenable_id', $consumer->id)->first();

        $this->assertSame(['snelstart:read', 'snelstart:write'], $token->abilities);
    }

    public function test_unknown_ability_is_rejected_before_consumer_creation(): void
    {
        $this->artisan('hub:consumer:create', [
            '--slug' => 'typo-test',
            '--name' => 'Typo Test',
            '--abilities' => 'snelstart:reed',
        ])
            ->expectsOutputToContain('Onbekende abilities: snelstart:reed')
            ->assertExitCode(2);

        $this->assertSame(0, Consumer::where('slug', 'typo-test')->count());
    }

    public function test_handle_resolves_consumer_onboarding_from_container(): void
    {
        $resolved = $this->app->make(ConsumerOnboarding::class);
        $this->assertInstanceOf(ConsumerOnboarding::class, $resolved);

        $reflection = new \ReflectionMethod(HubConsumerCreate::class, 'handle');
        $params = $reflection->getParameters();
        $this->assertCount(1, $params, 'handle() neemt nu een ConsumerOnboarding parameter');
        $this->assertSame(
            ConsumerOnboarding::class,
            $params[0]->getType()?->getName(),
            'handle() ontvangt ConsumerOnboarding via container-DI'
        );

        $this->artisan('hub:consumer:create', [
            '--slug' => 'di-test',
            '--name' => 'DI Test',
        ])->assertExitCode(0);

        $consumer = Consumer::where('slug', 'di-test')->first();
        $this->assertNotNull($consumer);
        $token = PersonalAccessToken::where('tokenable_id', $consumer->id)->first();
        $this->assertNotNull($token);
    }
}
