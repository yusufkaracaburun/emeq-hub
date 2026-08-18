<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Validation\DocumentInspector;
use App\Http\Concerns\GuardsTokenAbility;
use App\Http\Concerns\ResolvesAccountingConnection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ValidateDocumentRequest;
use App\Integrations\Exceptions\ProviderDisabledException;
use App\Sanctum\TokenAbilities;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group(name: 'Accounting Validate', description: 'Valideer een geëxtraheerd draft-document vóór het boeken; geeft findings + suggesties terug zonder te boeken.', weight: 51)]
class ValidateDocumentController extends Controller
{
    use GuardsTokenAbility;
    use ResolvesAccountingConnection;

    public function __construct(
        private readonly AccountingTargetRegistry $registry,
        private readonly DocumentInspector $inspector,
    ) {}

    public function __invoke(ValidateDocumentRequest $request): JsonResponse
    {
        $this->guardAbility($request, TokenAbilities::accounting(write: false));

        [, $connection] = $this->resolveAccountingConnection($request, $this->registry->providers());

        $provider = $connection->provider->value;

        $payload = $request->validated();
        $report = $this->inspector->inspect($payload);

        try {
            $enricher = $this->registry->enrichesValidation($connection);
        } catch (ProviderDisabledException) {
            $enricher = null;
        }

        if ($enricher !== null) {
            $report = $report->with($enricher->enrichValidation($payload, $connection));
        }

        return response()->json($report->toArray());
    }
}
