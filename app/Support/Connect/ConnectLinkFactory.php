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

    /**
     * @param  CarbonImmutable|null  $expiresAt  Vervaltijd van een bestaande link, om die
     *                                           over te nemen in plaats van te verlengen.
     * @return array{url: string, expires_at: CarbonImmutable}
     */
    public function mint(Account $account, ?string $returnUrl = null, ?CarbonImmutable $expiresAt = null): array
    {
        $expiresAt ??= CarbonImmutable::now()->addMinutes(self::TTL_MINUTES);

        $parameters = ['account' => $account->getKey()];

        if ($returnUrl !== null) {
            $parameters['return_url'] = $returnUrl;
        }

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

        return URL::temporarySignedRoute($route, $this->inheritedExpiry($request), $parameters);
    }

    public function manageUrl(Request $request, Account $account, string $provider): string
    {
        return URL::temporarySignedRoute('connect.manage.show', $this->inheritedExpiry($request), [
            'account' => $account->getKey(),
            'provider' => $provider,
        ]);
    }

    /** @param  array<string, int|string>  $parameters */
    public function manageActionUrl(Request $request, Account $account, string $provider, string $route, array $parameters = []): string
    {
        return URL::temporarySignedRoute($route, $this->inheritedExpiry($request), [
            'account' => $account->getKey(),
            'provider' => $provider,
            ...$parameters,
        ]);
    }

    public function inheritedExpiry(Request $request): CarbonImmutable
    {
        $expires = $request->query('expires');

        return is_numeric($expires)
            ? CarbonImmutable::createFromTimestamp((int) $expires)
            : CarbonImmutable::now()->addMinutes(self::TTL_MINUTES);
    }
}
