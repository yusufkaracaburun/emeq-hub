<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait GuardsTokenAbility
{
    /** @param  list<string>  $allowed */
    protected function guardAbility(Request $request, array $allowed): void
    {
        $token = $request->user()?->currentAccessToken();
        $has = $token && collect($allowed)->contains(fn (string $ability): bool => $token->can($ability));

        abort_unless($has, Response::HTTP_FORBIDDEN, 'insufficient_ability');
    }
}
