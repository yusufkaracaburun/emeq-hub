<?php

declare(strict_types=1);

namespace App\Support\Connect;

use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Mint de getekende handoff-links waarmee een consumer-app zijn eigen
 * eindgebruiker naar de Hub stuurt om zelf te koppelen.
 *
 * Waarom getekend en niet publiek: de Hub kent geen eindgebruiker-auth. De
 * consumer-app bewijst in zijn eigen sessie wie er inlogt en mint dan — met
 * zijn PAT — een link die het Account vastlegt. De handtekening maakt die link
 * tamper-proof, zodat niemand een ander `account` kan invullen en zo een
 * koppeling aan andermans administratie kan hangen (cross-tenant-leak).
 *
 * De link is kortlevend maar binnen dat venster herbruikbaar: de eindgebruiker
 * moet na een afgebroken of geweigerde autorisatie opnieuw kunnen klikken
 * zonder dat de consumer-app een nieuwe link hoeft te minten.
 */
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

    /**
     * Per-provider start-URL voor de knoppen op de handoff-pagina.
     *
     * De vervaltijd wordt overgenomen van de binnenkomende link in plaats van
     * opnieuw op nu+TTL gezet: anders zou elke paginaweergave het venster
     * verlengen en was de TTL in de praktijk oneindig oprekbaar.
     */
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

    /**
     * De beheerdrawer-payload voor één gekoppelde provider — zelfde getekende
     * bewijslast als `startUrl()`, alleen naar de drawer-route in plaats van
     * de connect/disconnect-actie.
     */
    public function manageUrl(Request $request, Account $account, string $provider): string
    {
        return URL::temporarySignedRoute('connect.manage.show', $this->inheritedExpiry($request), [
            'account' => $account->getKey(),
            'provider' => $provider,
        ]);
    }

    /**
     * Overige drawer-acties (mapping opslaan, relatie herkoppelen/ontkoppelen, zoeken):
     * zelfde `account`/`provider`-paar, plus eventuele route-specifieke parameters
     * (bv. de `ConnectionAccountingRef`-id). Die laatste tekent mee, dus een geruild
     * `ref` maakt de handtekening ongeldig.
     *
     * @param  array<string, int|string>  $parameters
     */
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
