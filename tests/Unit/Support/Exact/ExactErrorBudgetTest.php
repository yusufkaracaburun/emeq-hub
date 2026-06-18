<?php

namespace Tests\Unit\Support\Exact;

use App\Models\Connection;
use App\Support\Exact\ExactErrorBudget;
use Tests\TestCase;

class ExactErrorBudgetTest extends TestCase
{
    private function budget(): ExactErrorBudget
    {
        return new ExactErrorBudget($this->app->make('cache')->store('array'));
    }

    private function connection(int $id = 1): Connection
    {
        return (new Connection)->forceFill(['id' => $id]);
    }

    public function test_opens_after_threshold_counting_4xx(): void
    {
        config(['hub-providers.exact.error_budget' => ['enabled' => true, 'threshold' => 3, 'window' => 3600]]);
        $budget = $this->budget();
        $conn = $this->connection();

        $this->assertFalse($budget->isOpen($conn, '/crm/Accounts'));

        foreach ([400, 401, 403] as $status) {
            $budget->record($conn, '/crm/Accounts', $status);
        }

        $this->assertTrue($budget->isOpen($conn, '/crm/Accounts'));
    }

    public function test_non_counting_statuses_do_not_trip(): void
    {
        config(['hub-providers.exact.error_budget' => ['enabled' => true, 'threshold' => 2, 'window' => 3600]]);
        $budget = $this->budget();
        $conn = $this->connection();

        foreach ([200, 429, 500, 503] as $status) {
            $budget->record($conn, '/crm/Accounts', $status);
        }

        $this->assertFalse($budget->isOpen($conn, '/crm/Accounts'));
    }

    public function test_disabled_budget_never_opens(): void
    {
        config(['hub-providers.exact.error_budget' => ['enabled' => false, 'threshold' => 1, 'window' => 3600]]);
        $budget = $this->budget();
        $conn = $this->connection();

        $budget->record($conn, '/crm/Accounts', 403);

        $this->assertFalse($budget->isOpen($conn, '/crm/Accounts'));
    }

    public function test_isolates_per_endpoint(): void
    {
        config(['hub-providers.exact.error_budget' => ['enabled' => true, 'threshold' => 2, 'window' => 3600]]);
        $budget = $this->budget();
        $conn = $this->connection();

        $budget->record($conn, '/crm/Accounts', 403);
        $budget->record($conn, '/crm/Accounts', 403);

        $this->assertTrue($budget->isOpen($conn, '/crm/Accounts'));
        $this->assertFalse($budget->isOpen($conn, '/financial/GLAccounts'));
    }
}
