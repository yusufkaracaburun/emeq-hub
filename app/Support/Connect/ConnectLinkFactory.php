<?php

declare(strict_types=1);

namespace App\Support\Connect;

use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class ConnectLinkFactory
{
    public const TTL_MINUTES = 15;

    public const MODE_VIEW = 'view';

    /**
     * @param  CarbonImmutable|null  $expiresAt  Vervaltijd van een bestaande link, om die
     *                                           over te nemen in plaats van te verlengen.
     * @param  array<string, string>  $carried
     * @return array{url: string, expires_at: CarbonImmutable}
     */
    public function mint(Account $account, ?string $returnUrl = null, ?CarbonImmutable $expiresAt = null, array $carried = []): array
    {
        $expiresAt ??= CarbonImmutable::now()->addMinutes(self::TTL_MINUTES);

        $parameters = ['account' => $account->getKey()];

        if ($returnUrl !== null) {
            $parameters['return_url'] = $returnUrl;
        }

        $parameters = [...$parameters, ...$carried];

        return [
            'url' => URL::temporarySignedRoute('connect.show', $expiresAt, $parameters),
            'expires_at' => $expiresAt,
        ];
    }

    public function startUrl(Request $request, Account $account, string $provider, string $route = 'connect.start'): string
    {
        $parameters = [
            'account' => $account->getKey(),
            'provider' => $provider,
        ];

        $returnUrl = $request->query('return_url');

        if (is_string($returnUrl) && $returnUrl !== '') {
            $parameters['return_url'] = $returnUrl;
        }

        return URL::temporarySignedRoute($route, $this->inheritedExpiry($request), [...$parameters, ...$this->carried($request)]);
    }

    public function manageUrl(Request $request, Account $account, string $provider): string
    {
        return URL::temporarySignedRoute('connect.manage.show', $this->inheritedExpiry($request), [
            'account' => $account->getKey(),
            'provider' => $provider,
            ...$this->carried($request),
        ]);
    }

    /** @param  array<string, int|string>  $parameters */
    public function manageActionUrl(Request $request, Account $account, string $provider, string $route, array $parameters = []): string
    {
        return URL::temporarySignedRoute($route, $this->inheritedExpiry($request), [
            'account' => $account->getKey(),
            'provider' => $provider,
            ...$parameters,
            ...$this->carried($request),
        ]);
    }

    /**
     * @param  array{name: string, email: string}|null  $actor
     * @return array<string, string>
     */
    public function sessionParameters(string $mode, ?array $actor): array
    {
        return array_filter([
            'mode' => $mode === self::MODE_VIEW ? self::MODE_VIEW : null,
            'actor' => $actor === null ? null : 'consumer:'.substr(hash('sha256', mb_strtolower(trim($actor['email']))), 0, 12),
        ]);
    }

    /** @return array<string, string> */
    public function carried(Request $request): array
    {
        return array_filter([
            'mode' => $request->query('mode'),
            'actor' => $request->query('actor'),
        ], is_string(...));
    }

    public function actor(Request $request): ?string
    {
        $actor = $request->query('actor');

        return is_string($actor) ? $actor : null;
    }

    public function isViewOnly(Request $request): bool
    {
        return $request->query('mode') === self::MODE_VIEW;
    }

    public function inheritedExpiry(Request $request): CarbonImmutable
    {
        $expires = $request->query('expires');

        return is_numeric($expires)
            ? CarbonImmutable::createFromTimestamp((int) $expires)
            : CarbonImmutable::now()->addMinutes(self::TTL_MINUTES);
    }
}
