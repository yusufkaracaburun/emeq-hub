<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabledUnlessOwner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MultiFactorAuthenticationOwnerExemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        Role::firstOrCreate(['name' => 'super-admin']);
    }

    public function test_owner_email_bypasses_the_middleware(): void
    {
        $owner = User::factory()->create(['email' => 'info@emeq.nl']);
        $owner->assignRole('super-admin');
        $this->actingAs($owner);

        $middleware = new EnsureMultiFactorAuthenticationIsEnabledUnlessOwner;
        $called = false;

        $middleware->handle(Request::create('/admin'), function () use (&$called) {
            $called = true;

            return 'next-response';
        });

        $this->assertTrue($called);
    }
}
