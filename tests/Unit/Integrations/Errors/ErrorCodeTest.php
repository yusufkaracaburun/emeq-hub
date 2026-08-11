<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Errors;

use App\Integrations\Errors\ErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ErrorCodeTest extends TestCase
{
    #[DataProvider('statuses')]
    public function test_the_status_drives_the_category(int $status, ErrorCode $expected): void
    {
        $this->assertSame($expected, ErrorCode::for($status));
    }

    /**
     * @return array<string, array{0: int, 1: ErrorCode}>
     */
    public static function statuses(): array
    {
        return [
            '400' => [400, ErrorCode::ValidationError],
            '401' => [401, ErrorCode::AuthenticationError],
            '403' => [403, ErrorCode::AuthorizationError],
            '404' => [404, ErrorCode::ResourceNotFound],
            '409' => [409, ErrorCode::Conflict],
            '410' => [410, ErrorCode::ResourceNotFound],
            '415' => [415, ErrorCode::ValidationError],
            '422' => [422, ErrorCode::ValidationError],
            '429' => [429, ErrorCode::RateLimited],
            '500' => [500, ErrorCode::InternalError],
            '502' => [502, ErrorCode::ProviderUnavailable],
            '503' => [503, ErrorCode::ProviderUnavailable],
            '504' => [504, ErrorCode::ProviderUnavailable],
        ];
    }

    /**
     * De overrides bestaan juist omdat de status daar te weinig zegt.
     */
    #[DataProvider('overrides')]
    public function test_the_code_overrides_the_status_where_it_carries_more_meaning(int $status, string $error, ErrorCode $expected): void
    {
        $this->assertSame($expected, ErrorCode::for($status, $error));
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: ErrorCode}>
     */
    public static function overrides(): array
    {
        return [
            'ontbrekende mapping is geen invoerfout' => [422, 'mapping_failed', ErrorCode::ReferenceMappingMissing],
            'provider kan het niet' => [422, 'sync_unsupported', ErrorCode::UnsupportedCapability],
            'partner weigerde functioneel' => [422, 'upstream_rejected', ErrorCode::ProviderError],
            'sleutelhergebruik is een conflict' => [422, 'idempotency_key_reuse', ErrorCode::Conflict],
        ];
    }

    public function test_an_unknown_code_falls_back_to_the_status(): void
    {
        $this->assertSame(ErrorCode::Conflict, ErrorCode::for(409, 'iets_nieuws'));
    }

    public function test_an_unmapped_status_falls_back_by_class(): void
    {
        $this->assertSame(ErrorCode::InternalError, ErrorCode::for(507));
        $this->assertSame(ErrorCode::ValidationError, ErrorCode::for(418));
    }

    /**
     * Een validatiefout of conflict opnieuw sturen levert dezelfde fout op; alleen
     * de tijdelijke categorieën zijn het proberen waard.
     */
    public function test_only_transient_categories_are_retryable(): void
    {
        $this->assertTrue(ErrorCode::RateLimited->isRetryable());
        $this->assertTrue(ErrorCode::ProviderUnavailable->isRetryable());
        $this->assertTrue(ErrorCode::InternalError->isRetryable());

        $this->assertFalse(ErrorCode::ValidationError->isRetryable());
        $this->assertFalse(ErrorCode::Conflict->isRetryable());
        $this->assertFalse(ErrorCode::ReferenceMappingMissing->isRetryable());
        $this->assertFalse(ErrorCode::UnsupportedCapability->isRetryable());
        $this->assertFalse(ErrorCode::AuthorizationError->isRetryable());
    }
}
