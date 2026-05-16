<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Billing\Account\AccountSubscriptionManager;
use App\Billing\Account\Exceptions\InvalidStateTransitionException;
use App\Billing\Account\SubscriptionStatus;
use App\Filament\Resources\AccountSubscriptions\Pages\ListAccountSubscriptions;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\StubsMollieClient;
use Tests\TestCase;

/**
 * Plan 09-08 Task 3 — feature-tests voor state-flip actions van
 * AccountSubscriptionResource (T-07-03-03 invariant — manager-only delegation).
 *
 * Bewijst:
 *  - Pause-action zichtbaar alleen op Active
 *  - Resume-action zichtbaar alleen op Paused
 *  - Cancel-action zichtbaar op Active OF Paused
 *  - Pause-action delegeert via AccountSubscriptionManager (state flipt naar Paused)
 *  - Illegale transition gooit InvalidStateTransitionException + geen DB-mutatie
 *    (T-07-03-03 + StateTransitions D-04 invariant)
 */
class AccountSubscriptionStateActionsTest extends TestCase
{
    use RefreshDatabase;
    use StubsMollieClient;

    private function seedRoles(): void
    {
        Role::firstOrCreate(['name' => 'super-admin']);
        Role::firstOrCreate(['name' => 'staff']);
        Permission::firstOrCreate(['name' => 'view-account-subscriptions']);
    }

    private function actingAsStaff(): User
    {
        $this->seedRoles();
        $user = User::factory()->create();
        $user->assignRole('staff');
        $user->givePermissionTo('view-account-subscriptions');
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array{0: Consumer, 1: Account, 2: Connection}
     */
    private function setupTenancy(string $externalId = 'school-X'): array
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => $externalId]);
        $connection = Connection::factory()->forMollie()->active()->for($account)->create();

        return [$consumer, $account, $connection];
    }

    public function test_pause_action_visible_on_active_subscription_only(): void
    {
        $this->actingAsStaff();
        [, , $connection] = $this->setupTenancy();

        $active = AccountSubscription::factory()->forConnection($connection)->active()->create();
        $paused = AccountSubscription::factory()->forConnection($connection)->paused()->create();
        $canceled = AccountSubscription::factory()->forConnection($connection)->canceled()->create();
        $pending = AccountSubscription::factory()->forConnection($connection)->pending()->create();

        $component = Livewire::test(ListAccountSubscriptions::class);

        $component->assertTableActionVisible('pause', $active);
        $component->assertTableActionHidden('pause', $paused);
        $component->assertTableActionHidden('pause', $canceled);
        $component->assertTableActionHidden('pause', $pending);
    }

    public function test_pause_action_flips_status_via_manager(): void
    {
        $this->actingAsStaff();
        [, , $connection] = $this->setupTenancy('school-pause');

        $active = AccountSubscription::factory()->forConnection($connection)->active()->create();

        Livewire::test(ListAccountSubscriptions::class)
            ->callTableAction('pause', $active)
            ->assertHasNoTableActionErrors();

        $fresh = $active->fresh();
        $this->assertSame(SubscriptionStatus::Paused, $fresh->status);
        $this->assertNotNull($fresh->paused_at);
    }

    public function test_resume_action_visible_only_on_paused_subscription(): void
    {
        $this->actingAsStaff();
        [, , $connection] = $this->setupTenancy('school-resume');

        $active = AccountSubscription::factory()->forConnection($connection)->active()->create();
        $paused = AccountSubscription::factory()->forConnection($connection)->paused()->create();
        $canceled = AccountSubscription::factory()->forConnection($connection)->canceled()->create();

        $component = Livewire::test(ListAccountSubscriptions::class);

        $component->assertTableActionVisible('resume', $paused);
        $component->assertTableActionHidden('resume', $active);
        $component->assertTableActionHidden('resume', $canceled);
    }

    public function test_cancel_action_visible_on_active_and_paused_not_canceled(): void
    {
        $this->actingAsStaff();
        [, , $connection] = $this->setupTenancy('school-cancel-vis');

        $active = AccountSubscription::factory()->forConnection($connection)->active()->create();
        $paused = AccountSubscription::factory()->forConnection($connection)->paused()->create();
        $canceled = AccountSubscription::factory()->forConnection($connection)->canceled()->create();
        $pending = AccountSubscription::factory()->forConnection($connection)->pending()->create();

        $component = Livewire::test(ListAccountSubscriptions::class);

        $component->assertTableActionVisible('cancel', $active);
        $component->assertTableActionVisible('cancel', $paused);
        $component->assertTableActionHidden('cancel', $canceled);
        $component->assertTableActionHidden('cancel', $pending);
    }

    /**
     * D-10 (IN-02): cancelAction's Throwable-catch toont generieke notification
     * met sha256-fingerprint i.p.v. raw $e->getMessage(). Bewijst dat
     * exception-message-leak via Filament Notification dicht is.
     */
    public function test_cancel_action_shows_generic_notification_with_fingerprint_on_throwable(): void
    {
        $this->actingAsStaff();
        [, , $connection] = $this->setupTenancy('school-cancel-leak');

        $active = AccountSubscription::factory()->forConnection($connection)->active()->create();

        $managerMock = $this->createMock(AccountSubscriptionManager::class);
        $managerMock->method('cancel')
            ->willThrowException(new \RuntimeException('mollie-test-error-cancel'));
        $this->app->instance(AccountSubscriptionManager::class, $managerMock);

        Livewire::test(ListAccountSubscriptions::class)
            ->callTableAction('cancel', $active)
            ->assertNotified(
                Notification::make()
                    ->title('Cancel-actie mislukt')
                    ->body('Zie logs voor details — fingerprint: '.substr(hash('sha256', 'mollie-test-error-cancel'), 0, 12))
                    ->danger()
            );
    }

    /**
     * D-10 (IN-02) — pauseAction symmetrisch met cancelAction.
     */
    public function test_pause_action_shows_generic_notification_with_fingerprint_on_throwable(): void
    {
        $this->actingAsStaff();
        [, , $connection] = $this->setupTenancy('school-pause-leak');

        $active = AccountSubscription::factory()->forConnection($connection)->active()->create();

        $managerMock = $this->createMock(AccountSubscriptionManager::class);
        $managerMock->method('pause')
            ->willThrowException(new \RuntimeException('mollie-test-error-pause'));
        $this->app->instance(AccountSubscriptionManager::class, $managerMock);

        Livewire::test(ListAccountSubscriptions::class)
            ->callTableAction('pause', $active)
            ->assertNotified(
                Notification::make()
                    ->title('Pause-actie mislukt')
                    ->body('Zie logs voor details — fingerprint: '.substr(hash('sha256', 'mollie-test-error-pause'), 0, 12))
                    ->danger()
            );
    }

    /**
     * D-10 (IN-02) — resumeAction symmetrisch met cancelAction.
     */
    public function test_resume_action_shows_generic_notification_with_fingerprint_on_throwable(): void
    {
        $this->actingAsStaff();
        [, , $connection] = $this->setupTenancy('school-resume-leak');

        $paused = AccountSubscription::factory()->forConnection($connection)->paused()->create();

        $managerMock = $this->createMock(AccountSubscriptionManager::class);
        $managerMock->method('resume')
            ->willThrowException(new \RuntimeException('mollie-test-error-resume'));
        $this->app->instance(AccountSubscriptionManager::class, $managerMock);

        Livewire::test(ListAccountSubscriptions::class)
            ->callTableAction('resume', $paused)
            ->assertNotified(
                Notification::make()
                    ->title('Resume-actie mislukt')
                    ->body('Zie logs voor details — fingerprint: '.substr(hash('sha256', 'mollie-test-error-resume'), 0, 12))
                    ->danger()
            );
    }

    /**
     * Plan 09-08 success-criterium 5 + HUB-04 success-criterium 8:
     * Illegale state-transitie → geen DB-mutatie + InvalidStateTransitionException.
     *
     * Filament-UI filtert cancel-action al uit op canceled (visibility-test
     * boven), maar de manager-laag MOET ook hard-faili op illegale transities.
     * Hier testen we de manager direct met een echt illegale transitie
     * (pause op canceled — StateTransitions kent geen Canceled→Paused-pair).
     * Dit bewijst dat T-07-03-03 invariant in stand blijft ook als Filament's
     * visibility-filter zou worden omzeild.
     */
    public function test_illegal_transition_throws_without_db_mutation(): void
    {
        $this->actingAsStaff();
        [, , $connection] = $this->setupTenancy('school-illegal');

        $canceled = AccountSubscription::factory()
            ->forConnection($connection)
            ->canceled()
            ->create();

        $originalCanceledAt = $canceled->canceled_at;

        // Manager-laag throwt InvalidStateTransitionException op illegale
        // transition (Canceled → Paused is geen legale pair per StateTransitions).
        try {
            app(AccountSubscriptionManager::class)->pause($canceled, 'forced_illegal_transition');
            $this->fail('Expected InvalidStateTransitionException, geen exception gegooid.');
        } catch (InvalidStateTransitionException $e) {
            $this->assertSame(SubscriptionStatus::Canceled, $e->from);
            $this->assertSame(SubscriptionStatus::Paused, $e->to);
        }

        // DB-row blijft Canceled — geen mutatie ondanks de pause-poging.
        $fresh = $canceled->fresh();
        $this->assertSame(SubscriptionStatus::Canceled, $fresh->status);
        $this->assertEquals($originalCanceledAt?->timestamp, $fresh->canceled_at?->timestamp);
        $this->assertNull($fresh->paused_at);
    }
}
