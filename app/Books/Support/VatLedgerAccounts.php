<?php

declare(strict_types=1);

namespace App\Books\Support;

/**
 * Eén bron voor de chart-of-accounts-conventie "BTW-tarief ↔ omzetrekening +
 * af-te-dragen-BTW-rekening ↔ aangifte-rubriek". Zowel de boeking (InvoicePoster,
 * per tarief) als de BTW-aangifte (BtwService, per rubriek) las dit eerder elk apart;
 * een wijziging in de grootboekcodes moest dan op twee plekken. Nu één keer.
 */
final class VatLedgerAccounts
{
    /**
     * @var array<int, array{rubriek: string, revenue: string, vat: ?string}> tarief% => rekeningen
     */
    private const MAP = [
        21 => ['rubriek' => '1a', 'revenue' => '8000', 'vat' => '1620'], // hoog
        9 => ['rubriek' => '1b', 'revenue' => '8010', 'vat' => '1621'],  // laag
        0 => ['rubriek' => '1e', 'revenue' => '8020', 'vat' => null],    // 0% / overig
    ];

    /**
     * Omzet- + af-te-dragen-BTW-rekening voor een BTW-tarief, of null bij een onbekend tarief.
     *
     * @return array{0: string, 1: ?string}|null [omzetrekening, BTW-rekening]
     */
    public static function forRate(int $rate): ?array
    {
        $row = self::MAP[$rate] ?? null;

        return $row === null ? null : [$row['revenue'], $row['vat']];
    }

    /**
     * Aangifte-rubrieken (1a/1b/1e) → [omzetrekening, af-te-dragen-BTW-rekening].
     *
     * @return array<string, array{grondslag: string, btw: ?string}>
     */
    public static function rubrieken(): array
    {
        $out = [];

        foreach (self::MAP as $row) {
            $out[$row['rubriek']] = ['grondslag' => $row['revenue'], 'btw' => $row['vat']];
        }

        return $out;
    }
}
