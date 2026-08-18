<?php

declare(strict_types=1);

namespace App\Books\Services;

use App\Books\Models\Account;
use App\Books\Support\VatLedgerAccounts;
use Illuminate\Support\Carbon;

class BtwService
{
    private const VOORBELASTING = '1530';

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

        foreach (VatLedgerAccounts::rubrieken() as $code => $accounts) {
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

    private function movement(string $code, string $from, string $to): int
    {
        $account = Account::query()->where('code', $code)->first();

        return $account !== null ? $this->accounts->netMovement($account, $from, $to) : 0;
    }

    /** @return array{0: string, 1: string} */
    private function range(string $start, string $end): array
    {
        return [
            Carbon::parse($start)->startOfDay()->toDateTimeString(),
            Carbon::parse($end)->endOfDay()->toDateTimeString(),
        ];
    }
}
