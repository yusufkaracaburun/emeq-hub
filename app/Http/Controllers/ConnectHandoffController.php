<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounting\AccountingTargetRegistry;
use App\Actions\Connect\ProviderNotConnectableException;
use App\Actions\Connect\RevokeConnection;
use App\Actions\Connect\StartProviderConnection;
use App\Enums\Provider;
use App\Integrations\Exceptions\ProviderDisabledException;
use App\Jobs\Webhooks\ForwardConnectionRevokedToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Support\Connect\ConnectLinkFactory;
use App\Support\Connect\ProviderConnectStatus;
use App\Support\Seo\SeoMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ConnectHandoffController extends Controller
{
    public function __construct(
        private readonly ProviderConnectStatus $statuses,
        private readonly ConnectLinkFactory $links,
        private readonly AccountingTargetRegistry $accountingTargets,
    ) {}

    public function show(Request $request, Account $account): Response
    {
        $providers = collect($this->statuses->for($account))
            ->filter(fn (array $provider): bool => $provider['connectable'])
            ->map(fn (array $provider): array => [
                ...$provider,
                'start_url' => $this->links->startUrl($request, $account, $provider['key'], 'connect.start'),
                'disconnect_url' => $provider['status'] === 'connected'
                    ? $this->links->startUrl($request, $account, $provider['key'], 'connect.disconnect')
                    : null,
                'manage_url' => $provider['status'] === 'connected' && $this->accountingTargets->supports($provider['key'])
                    ? $this->links->manageUrl($request, $account, $provider['key'])
                    : null,
            ])
            ->values()
            ->all();

        return Inertia::render('connect/index', [
            'state' => 'manage',
            'consumerName' => $account->consumer?->name,
            'accountName' => $account->display_name,
            'providers' => $providers,
            'returnUrl' => $this->returnUrl($request),
            'expiresAt' => $this->links->inheritedExpiry($request)->toIso8601String(),
            'seo' => SeoMeta::make(
                'Je koppelingen',
                'Beheer welke systemen zijn gekoppeld.',
            ),
        ]);
    }

    public function start(
        Request $request,
        Account $account,
        string $provider,
        StartProviderConnection $startConnection,
    ): HttpResponse {
        $providerEnum = Provider::tryFrom($provider);

        abort_if($providerEnum === null, 404);

        try {
            $result = $startConnection->handle($account, $providerEnum, $this->returnUrl($request));
        } catch (ProviderNotConnectableException) {
            abort(404);
        } catch (ProviderDisabledException) {
            abort(503);
        }

        return Inertia::location($result['redirect_url']);
    }

    public function disconnect(
        Request $request,
        Account $account,
        string $provider,
        RevokeConnection $revokeConnection,
    ): RedirectResponse {
        $providerEnum = Provider::tryFrom($provider);

        abort_if($providerEnum === null, 404);

        $connection = $account->connections()
            ->where('provider', $providerEnum->value)
            ->whereNull('revoked_at')
            ->first();

        if ($connection instanceof Connection) {
            $revokeConnection->handle($connection);

            ForwardConnectionRevokedToConsumerJob::dispatch(
                $connection,
                'end_user_handoff',
                (string) Str::uuid(),
            );
        }

        return redirect()->to($this->links->mint(
            $account,
            $this->returnUrl($request),
            $this->links->inheritedExpiry($request),
        )['url']);
    }

    private function returnUrl(Request $request): ?string
    {
        $returnUrl = $request->query('return_url');

        return is_string($returnUrl) && $returnUrl !== '' ? $returnUrl : null;
    }
}
