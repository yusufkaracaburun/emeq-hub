<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Validators;

use App\Accounting\Validation\Contracts\DocumentValidator;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Severity;
use App\Accounting\Validation\Support\Money;

/**
 * Reconcilieert de geëxtraheerde bedragen. Per regel: niet-numeriek bedrag → flag, en
 * (indien aanwezig) bedrag vs aantal × stukprijs. Op documentniveau: de som van de
 * regels tegen de aangeleverde subtotaal / BTW-totaal / totaal (met korting). De Hub
 * stelt het herberekende bedrag voor maar past het nooit toe. Mismatches zijn warnings —
 * de bookbare payload (regels) blijft welgevormd; het is OCR-extractie die niet sluit.
 */
final class ArithmeticValidator implements DocumentValidator
{
    public function validate(array $payload): array
    {
        $lines = is_array($payload['lines'] ?? null) ? array_values($payload['lines']) : [];

        $findings = [];
        $net = 0.0;
        $tax = 0.0;

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $amount = Money::toFloat($line['amount'] ?? null);

            if ($amount === null) {
                $findings[] = new Finding(
                    code: 'arithmetic.amount_not_numeric',
                    severity: Severity::Warning,
                    path: "lines.{$index}.amount",
                    message: 'Regelbedrag is niet numeriek.',
                    current: $line['amount'] ?? null,
                    suggestion: null,
                );

                continue;
            }

            $amount = round($amount, 2);
            $rate = Money::toFloat($line['tax_rate'] ?? null) ?? 0.0;
            $net += $amount;
            $tax += round($amount * $rate / 100, 2);

            $quantity = Money::toFloat($line['quantity'] ?? null);
            $unitPrice = Money::toFloat($line['unit_price'] ?? null);

            if ($quantity !== null && $unitPrice !== null) {
                $expected = round($quantity * $unitPrice, 2);

                if (! Money::close($amount, $expected)) {
                    $findings[] = new Finding(
                        code: 'arithmetic.line_amount_mismatch',
                        severity: Severity::Warning,
                        path: "lines.{$index}.amount",
                        message: 'Regelbedrag wijkt af van aantal × stukprijs.',
                        current: $amount,
                        suggestion: $expected,
                    );
                }
            }
        }

        $net = round($net, 2);
        $tax = round($tax, 2);

        $findings = array_merge($findings, $this->reconcileTotals($payload, $net, $tax));

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Finding>
     */
    private function reconcileTotals(array $payload, float $net, float $tax): array
    {
        $findings = [];

        $subtotal = Money::toFloat($payload['subtotal'] ?? null);
        if ($subtotal !== null && ! Money::close($subtotal, $net)) {
            $findings[] = new Finding(
                code: 'arithmetic.subtotal_mismatch',
                severity: Severity::Warning,
                path: 'subtotal',
                message: 'Opgegeven subtotaal wijkt af van de som van de regels (netto).',
                current: $subtotal,
                suggestion: $net,
            );
        }

        $taxTotal = Money::toFloat($payload['tax_total'] ?? null);
        if ($taxTotal !== null && ! Money::close($taxTotal, $tax)) {
            $findings[] = new Finding(
                code: 'arithmetic.tax_total_mismatch',
                severity: Severity::Warning,
                path: 'tax_total',
                message: 'Opgegeven BTW-totaal wijkt af van de berekende BTW over de regels.',
                current: $taxTotal,
                suggestion: $tax,
            );
        }

        $total = Money::toFloat($payload['total'] ?? null);
        if ($total !== null) {
            $discount = Money::toFloat($payload['discount'] ?? null) ?? 0.0;
            $expected = round($net + $tax - $discount, 2);

            if (! Money::close($total, $expected)) {
                $findings[] = new Finding(
                    code: 'arithmetic.total_mismatch',
                    severity: Severity::Warning,
                    path: 'total',
                    message: 'Opgegeven totaal wijkt af van netto + BTW − korting.',
                    current: $total,
                    suggestion: $expected,
                );
            }
        }

        return $findings;
    }
}
