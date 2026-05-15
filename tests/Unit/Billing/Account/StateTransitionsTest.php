<?php

declare(strict_types=1);

namespace Tests\Unit\Billing\Account;

use App\Billing\Account\Exceptions\InvalidStateTransitionException;
use App\Billing\Account\StateTransitions;
use App\Billing\Account\SubscriptionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StateTransitionsTest extends TestCase
{
    /**
     * @return array<string, array{0: SubscriptionStatus, 1: SubscriptionStatus}>
     */
    public static function legalPairs(): array
    {
        return [
            'pending → active' => [SubscriptionStatus::Pending, SubscriptionStatus::Active],
            'pending → canceled' => [SubscriptionStatus::Pending, SubscriptionStatus::Canceled],
            'active → paused' => [SubscriptionStatus::Active, SubscriptionStatus::Paused],
            'active → canceled' => [SubscriptionStatus::Active, SubscriptionStatus::Canceled],
            'active → completed' => [SubscriptionStatus::Active, SubscriptionStatus::Completed],
            'active → unknown' => [SubscriptionStatus::Active, SubscriptionStatus::Unknown],
            'paused → active' => [SubscriptionStatus::Paused, SubscriptionStatus::Active],
            'paused → canceled' => [SubscriptionStatus::Paused, SubscriptionStatus::Canceled],
            'paused → unknown' => [SubscriptionStatus::Paused, SubscriptionStatus::Unknown],
        ];
    }

    /**
     * @return array<string, array{0: SubscriptionStatus, 1: SubscriptionStatus}>
     */
    public static function illegalPairs(): array
    {
        return [
            'pending → paused (skip active)' => [SubscriptionStatus::Pending, SubscriptionStatus::Paused],
            'pending → completed (skip active)' => [SubscriptionStatus::Pending, SubscriptionStatus::Completed],
            'pending → unknown' => [SubscriptionStatus::Pending, SubscriptionStatus::Unknown],
            'paused → completed' => [SubscriptionStatus::Paused, SubscriptionStatus::Completed],
            'canceled → active' => [SubscriptionStatus::Canceled, SubscriptionStatus::Active],
            'canceled → pending' => [SubscriptionStatus::Canceled, SubscriptionStatus::Pending],
            'canceled → paused' => [SubscriptionStatus::Canceled, SubscriptionStatus::Paused],
            'canceled → completed' => [SubscriptionStatus::Canceled, SubscriptionStatus::Completed],
            'canceled → unknown' => [SubscriptionStatus::Canceled, SubscriptionStatus::Unknown],
            'completed → active' => [SubscriptionStatus::Completed, SubscriptionStatus::Active],
            'completed → pending' => [SubscriptionStatus::Completed, SubscriptionStatus::Pending],
            'completed → paused' => [SubscriptionStatus::Completed, SubscriptionStatus::Paused],
            'completed → canceled' => [SubscriptionStatus::Completed, SubscriptionStatus::Canceled],
            'completed → unknown' => [SubscriptionStatus::Completed, SubscriptionStatus::Unknown],
            'unknown → active' => [SubscriptionStatus::Unknown, SubscriptionStatus::Active],
            'unknown → pending' => [SubscriptionStatus::Unknown, SubscriptionStatus::Pending],
            'unknown → paused' => [SubscriptionStatus::Unknown, SubscriptionStatus::Paused],
            'unknown → canceled' => [SubscriptionStatus::Unknown, SubscriptionStatus::Canceled],
            'unknown → completed' => [SubscriptionStatus::Unknown, SubscriptionStatus::Completed],
        ];
    }

    /**
     * @return array<string, array{0: SubscriptionStatus}>
     */
    public static function allStates(): array
    {
        return [
            'pending' => [SubscriptionStatus::Pending],
            'active' => [SubscriptionStatus::Active],
            'paused' => [SubscriptionStatus::Paused],
            'canceled' => [SubscriptionStatus::Canceled],
            'completed' => [SubscriptionStatus::Completed],
            'unknown' => [SubscriptionStatus::Unknown],
        ];
    }

    #[DataProvider('legalPairs')]
    public function test_legal_transitions_do_not_throw(SubscriptionStatus $from, SubscriptionStatus $to): void
    {
        StateTransitions::assertTransition($from, $to);

        $this->assertTrue(StateTransitions::isLegal($from, $to));
    }

    #[DataProvider('illegalPairs')]
    public function test_illegal_transitions_throw_with_from_to_properties(SubscriptionStatus $from, SubscriptionStatus $to): void
    {
        $this->assertFalse(StateTransitions::isLegal($from, $to));

        try {
            StateTransitions::assertTransition($from, $to);
            $this->fail(sprintf(
                'Expected InvalidStateTransitionException for %s → %s, got none.',
                $from->value,
                $to->value,
            ));
        } catch (InvalidStateTransitionException $e) {
            $this->assertSame($from, $e->from);
            $this->assertSame($to, $e->to);
            $this->assertStringContainsString($from->value, $e->getMessage());
            $this->assertStringContainsString($to->value, $e->getMessage());
        }
    }

    #[DataProvider('allStates')]
    public function test_self_transition_is_noop(SubscriptionStatus $state): void
    {
        StateTransitions::assertTransition($state, $state);

        $this->assertTrue(StateTransitions::isLegal($state, $state));
    }

    /**
     * Terminal states (canceled, completed, unknown) accepteren GEEN outbound
     * transitions naar een andere state — alleen self-transition is toegestaan
     * (idempotency). Dit dekt D-04's "terminal" annotatie + threat T-07-02-02.
     */
    public function test_terminal_states_block_all_outbound_transitions(): void
    {
        $terminals = [
            SubscriptionStatus::Canceled,
            SubscriptionStatus::Completed,
            SubscriptionStatus::Unknown,
        ];

        $nonTerminals = [
            SubscriptionStatus::Pending,
            SubscriptionStatus::Active,
            SubscriptionStatus::Paused,
        ];

        foreach ($terminals as $terminal) {
            // Naar elke non-terminal: moet falen
            foreach ($nonTerminals as $target) {
                $this->assertFalse(
                    StateTransitions::isLegal($terminal, $target),
                    sprintf('Terminal %s → %s should be illegal', $terminal->value, $target->value),
                );
            }

            // Tussen terminals (behalve self): ook falen
            foreach ($terminals as $otherTerminal) {
                if ($terminal === $otherTerminal) {
                    continue;
                }
                $this->assertFalse(
                    StateTransitions::isLegal($terminal, $otherTerminal),
                    sprintf('Terminal %s → %s should be illegal', $terminal->value, $otherTerminal->value),
                );
            }
        }
    }

    public function test_invalid_state_transition_exception_factory_returns_instance_with_properties(): void
    {
        $exception = InvalidStateTransitionException::for(
            SubscriptionStatus::Pending,
            SubscriptionStatus::Paused,
        );

        $this->assertSame(SubscriptionStatus::Pending, $exception->from);
        $this->assertSame(SubscriptionStatus::Paused, $exception->to);
        $this->assertSame(
            'Ongeldige state-transition: pending → paused.',
            $exception->getMessage(),
        );
    }

    public function test_paused_to_active_is_bidirectional_with_active_to_paused(): void
    {
        // D-04: `paused → active` is bidirectioneel met `active → paused`.
        $this->assertTrue(StateTransitions::isLegal(SubscriptionStatus::Active, SubscriptionStatus::Paused));
        $this->assertTrue(StateTransitions::isLegal(SubscriptionStatus::Paused, SubscriptionStatus::Active));
    }

    public function test_subscription_status_enum_has_exact_six_cases_with_string_values(): void
    {
        $cases = SubscriptionStatus::cases();

        $this->assertCount(6, $cases);

        $values = array_map(fn (SubscriptionStatus $c) => $c->value, $cases);
        $this->assertSame(
            ['pending', 'active', 'paused', 'canceled', 'completed', 'unknown'],
            $values,
        );
    }
}
