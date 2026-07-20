<?php

namespace Tests\Unit\Support\Exact;

use App\Support\Exact\ExactPathWhitelist;
use Tests\TestCase;

class ExactPathWhitelistTest extends TestCase
{
    private function whitelist(array $allowed = ['crm/Accounts', 'financial/GLAccounts']): ExactPathWhitelist
    {
        config(['hub-providers.exact.allowed_paths' => $allowed]);

        return new ExactPathWhitelist;
    }

    public function test_allows_exact_resource_match(): void
    {
        $this->assertTrue($this->whitelist()->allows('crm/Accounts'));
        $this->assertTrue($this->whitelist()->allows('/crm/Accounts'));
    }

    public function test_allows_odata_key_predicate(): void
    {
        $this->assertTrue($this->whitelist()->allows("crm/Accounts(guid'abc-123')"));
    }

    public function test_allows_query_string(): void
    {
        $this->assertTrue($this->whitelist()->allows('financial/GLAccounts?$filter=Code eq 1000'));
    }

    public function test_allows_navigation_subpath(): void
    {
        $this->assertTrue($this->whitelist()->allows('crm/Accounts/guid'));
    }

    public function test_is_case_insensitive(): void
    {
        $this->assertTrue($this->whitelist()->allows('CRM/accounts'));
    }

    public function test_blocks_resource_outside_whitelist(): void
    {
        $this->assertFalse($this->whitelist()->allows('subscription/Subscriptions'));
        $this->assertFalse($this->whitelist()->allows('sysadmin/Divisions'));
    }

    public function test_blocks_prefix_trap(): void
    {
        // financial/GLAccountsGroups must NOT match the financial/GLAccounts prefix.
        $this->assertFalse($this->whitelist()->allows('financial/GLAccountsGroups'));
    }

    public function test_blocks_empty_path(): void
    {
        $this->assertFalse($this->whitelist()->allows(''));
        $this->assertFalse($this->whitelist()->allows('/'));
    }

    public function test_empty_config_disables_whitelist(): void
    {
        // Kill-switch: empty list = whitelist off, everything allowed.
        $this->assertTrue($this->whitelist([])->allows('anything/AtAll'));
    }
}
