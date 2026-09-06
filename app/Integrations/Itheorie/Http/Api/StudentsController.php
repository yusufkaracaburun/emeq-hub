<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie\Http\Api;

use App\Integrations\Itheorie\Http\Api\Concerns\ForwardsToItheorie;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Emeq\ItheorieApi\Itheorie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentsController
{
    use ForwardsToItheorie;

    #[ResponseDoc(404, 'Geen leerling gevonden bij deze toegangscode.')]
    #[ResponseDoc(502, 'iTheorie gaf een foutmelding terug.')]
    public function show(Request $request, Itheorie $itheorie, string $accessCode): JsonResponse
    {
        return $this->forward(
            $request,
            'GET',
            '/itheorie/students/{accessCode}',
            [],
            static fn (): array => $itheorie->student($accessCode),
        );
    }

    #[ResponseDoc(404, 'Geen leerling gevonden bij deze toegangscode.')]
    #[ResponseDoc(502, 'iTheorie gaf een foutmelding terug.')]
    public function showDetailed(Request $request, Itheorie $itheorie, string $accessCode): JsonResponse
    {
        return $this->forward(
            $request,
            'GET',
            '/itheorie/students/{accessCode}/detailed',
            [],
            static fn (): array => $itheorie->studentDetailed($accessCode),
        );
    }
}
