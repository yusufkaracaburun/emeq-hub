<?php

declare(strict_types=1);

namespace App\Books\Services;

use App\Books\Models\Account;
use Illuminate\Support\Carbon;

/*
 * Bouwt de Nederlandse BTW-aangifte over een periode bovenop de saldo-primitieven
 * van AccountService — net als ReportService voor W&V/Balans. Grootboek-based: de
 * aangifte spiegelt de geboekte realiteit (alleen geboekte facturen + memoriaal
 * dragen journaalposten), dus concept-facturen lekken niet in de aangifte.
 *
 * Data-subset: alleen wat het grootboek kent. Rubriek 1a/1b/1e (binnenlandse
 * leveringen per tarief) + 5a verschuldigd + 5b voorbelasting + saldo. Rubriek
 * 2/3/4 (verlegd/ICP/EU) vereist een EU-/verlegd-vlag op de regels die er nu niet
 * is — buiten scope, het rapport meldt ze als nul.
 */
class BtwService
{
    /** @var array<string, array{grondslag: string, btw: ?string}> rubriek => [omzetrekening, af-te-dragen-BTW-rekening] */
    private const RUBRIEKEN = [
        '1a' => ['grondslag' => '8000', 'btw' => '1620'], // hoog 21%
        '1b' => ['grondslag' => '8010', 'btw' => '1621'], // laag 9%
        '1e' => ['grondslag' => '8020', 'btw' => null],   // 0% / overig
    ];

    private const VOORBELASTING = '1530'; // te vorderen BTW (5b)

    public function __construct(private readonly AccountService $accounts) {}

    /**
     * @return array{
     *     period: array{start: string, end: string},
     *     rubrieken: array<string, array{grondslag: int, btw: int}>,
     *     verschuldigd: int,
     *     voorbelasting: int,
     *     saldo: int
     * }
     */
    public function declaration(string $start, string $end): array
    {
        [$from, $to] = $this->range($start, $end);

        $rubrieken = [];
        $verschuldigd = 0;

        foreach (self::RUBRIEKEN as $code => $accounts) {
            $btw = $accounts['btw'] !== null ? $this->movement($accounts['btw'], $from, $to) : 0;

            $rubrieken[$code] = [
                'grondslag' => $this->movement($accounts['grondslag'], $from, $to),
                'btw' => $btw,
            ];

            $verschuldigd += $btw;
        }

        $voorbelasting = $this->movement(self::VOORBELASTING, $from, $to);

        return [
            'period' => ['start' => $start, 'end' => $end],
            'rubrieken' => $rubrieken,
            'verschuldigd' => $verschuldigd,
            'voorbelasting' => $voorbelasting,
            'saldo' => $verschuldigd - $voorbelasting,
        ];
    }

    /**
     * Nettobeweging van één grootboekrekening over de periode. Ontbrekende
     * rekening → 0, zodat een read-rapport nooit breekt op een niet-geseede code.
     */
    private function movement(string $code, string $from, string $to): int
    {
        $account = Account::query()->where('code', $code)->first();

        return $account !== null ? $this->accounts->netMovement($account, $from, $to) : 0;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(string $start, string $end): array
    {
        return [
            Carbon::parse($start)->startOfDay()->toDateTimeString(),
            Carbon::parse($end)->endOfDay()->toDateTimeString(),
        ];
    }
}
