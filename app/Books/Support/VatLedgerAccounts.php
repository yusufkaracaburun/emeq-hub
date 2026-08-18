<?php

declare(strict_types=1);

namespace App\Books\Support;

final class VatLedgerAccounts
{
    /** @var array<int, array{rubriek: string, revenue: string, vat: ?string}> tarief% => rekeningen */
    private const MAP = [
        21 => ['rubriek' => '1a', 'revenue' => '8000', 'vat' => '1620'],
        9 => ['rubriek' => '1b', 'revenue' => '8010', 'vat' => '1621'],
        0 => ['rubriek' => '1e', 'revenue' => '8020', 'vat' => null],
    ];

    /** @return array{0: string, 1: ?string}|null [omzetrekening, BTW-rekening] */
    public static function forRate(int $rate): ?array
    {
        $row = self::MAP[$rate] ?? null;

        return $row === null ? null : [$row['revenue'], $row['vat']];
    }

    /** @return array<string, array{grondslag: string, btw: ?string}> */
    public static function rubrieken(): array
    {
        $out = [];

        foreach (self::MAP as $row) {
            $out[$row['rubriek']] = ['grondslag' => $row['revenue'], 'btw' => $row['vat']];
        }

        return $out;
    }
}
