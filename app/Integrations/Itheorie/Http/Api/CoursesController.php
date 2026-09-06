<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie\Http\Api;

use App\Integrations\Itheorie\Http\Api\Concerns\ForwardsToItheorie;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Emeq\ItheorieApi\Itheorie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CoursesController
{
    use ForwardsToItheorie;

    #[QueryParameter('page', description: 'Paginanummer.', type: 'integer', default: 1)]
    #[QueryParameter('limit', description: 'Aantal cursussen per pagina.', type: 'integer', default: 50)]
    #[ResponseDoc(502, 'iTheorie gaf een foutmelding terug.')]
    public function index(Request $request, Itheorie $itheorie): JsonResponse
    {
        ['page' => $page, 'limit' => $limit] = $this->pagination($request);

        return $this->forward(
            $request,
            'GET',
            '/itheorie/courses',
            ['page' => $page, 'limit' => $limit],
            static fn (): array => $itheorie->courses($page, $limit),
        );
    }

    #[ResponseDoc(404, 'Cursus bestaat niet bij iTheorie.')]
    #[ResponseDoc(502, 'iTheorie gaf een foutmelding terug.')]
    public function show(Request $request, Itheorie $itheorie, string $course): JsonResponse
    {
        return $this->forward(
            $request,
            'GET',
            '/itheorie/courses/{course}',
            [],
            static fn (): array => $itheorie->course($course),
        );
    }
}
