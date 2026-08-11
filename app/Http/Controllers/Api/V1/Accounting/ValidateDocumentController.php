<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Validation\DocumentInspector;
use App\Http\Concerns\GuardsTokenAbility;
use App\Http\Concerns\ResolvesAccountingConnection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ValidateDocumentRequest;
use App\OAuth\Exceptions\ProviderDisabledException;
use App\Sanctum\TokenAbilities;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * Dry-run validatie van een geëxtraheerd draft-document ("Scan & herstel"). Boekt niets:
 * de Hub controleert de bedragen, BTW-behandeling, IBAN/BTW-nummer, geografie en valuta
 * provider-agnostisch en geeft een findings-rapport met concrete suggesties terug. De
 * consumer toont de issues, de gebruiker bevestigt, en pas dán volgt de boek-POST.
 */
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
        [, $connection] = $this->resolveAccountingConnection($request, $this->registry->providers());

        $provider = $connection->provider->value;

        $this->guardAbility($request, ["{$provider}:read", "{$provider}:write", TokenAbilities::ADMIN]);

        $payload = $request->validated();
        $report = $this->inspector->inspect($payload);

        // De kill-switch afvangen in plaats van laten doorslaan: de enrichment doet een
        // live partner-call, en die hoort niet te gebeuren als de provider uit staat.
        // Het rapport degradeert dan naar de agnostische findings in plaats van te falen —
        // een read-only dry-run hoort niet te struikelen over een uitgeschakelde provider.
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
