<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAccountRequest;
use App\Http\Resources\Api\V1\AccountResource;
use App\Sanctum\TokenAbilities;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group(name: 'Accounts', description: 'Eindgebruiker-tenants per Consumer (external_id-aliasing).', weight: 20)]
class AccountController extends Controller
{
    public function store(StoreAccountRequest $request): JsonResponse|AccountResource
    {
        $this->guardAbility($request, [
            TokenAbilities::SNELSTART_WRITE,
            TokenAbilities::MOLLIE_WRITE,
            TokenAbilities::CONSUMER_MANAGE_ACCOUNTS,
            TokenAbilities::ADMIN,
        ]);

        try {
            $account = $request->user()->accounts()->create([
                'external_id' => $request->string('external_id')->toString(),
                'display_name' => $request->input('display_name'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'error' => 'account_exists',
                'message' => 'Account met deze external_id bestaat al voor deze Consumer.',
            ], Response::HTTP_CONFLICT);
        }

        return (new AccountResource($account))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function guardAbility(Request $request, array $allowed): void
    {
        $token = $request->user()?->currentAccessToken();
        $has = $token && collect($allowed)->contains(fn (string $ability) => $token->can($ability));

        abort_unless($has, Response::HTTP_FORBIDDEN, 'insufficient_ability');
    }
}
