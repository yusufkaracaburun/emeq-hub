<?php

declare(strict_types=1);

namespace App\Integrations\Mollie\Http\Connect;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Mollie · Connect', description: 'Mollie Connect partner-resources (onboarding, organizations, profiles, permissions, client-links).', weight: 60)]
class OnboardingController extends AbstractMollieConnectPassThroughController
{
    public function me(Request $request): Response
    {
        return $this->handle($request, '/v2/onboarding/me', function (Request $r) {
            $onboarding = $this->dispatchMollieCall(
                fn () => $this->client($r)->onboarding->status(),
            );

            return $this->resourceToArray($onboarding);
        });
    }
}
