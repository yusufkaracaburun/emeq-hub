<?php

declare(strict_types=1);

namespace App\Books\Services;

use Spatie\ArrayToXml\ArrayToXml;

class BtwXmlExporter
{
    public function __construct(private readonly BtwService $btw) {}

    public function export(string $start, string $end): string
    {
        $declaration = $this->btw->declaration($start, $end);

        $rubrieken = [];
        foreach ($declaration['rubrieken'] as $code => $rubriek) {
            $rubrieken[] = [
                '_attributes' => ['code' => $code],
                'grondslag' => (string) $rubriek['grondslag'],
                'btw' => (string) $rubriek['btw'],
            ];
        }

        return ArrayToXml::convert(
            [
                'rubrieken' => ['rubriek' => $rubrieken],
                'verschuldigd' => (string) $declaration['verschuldigd'],
                'voorbelasting' => (string) $declaration['voorbelasting'],
                'saldo' => (string) $declaration['saldo'],
            ],
            [
                'rootElementName' => 'btw-aangifte',
                '_attributes' => [
                    'valuta' => 'EUR',
                    'eenheid' => 'centen',
                    'start' => $declaration['period']['start'],
                    'eind' => $declaration['period']['end'],
                ],
            ],
            xmlEncoding: 'UTF-8',
            domProperties: ['formatOutput' => true],
        );
    }

    public function filename(string $start, string $end): string
    {
        return 'btw-aangifte-'.$start.'-'.$end.'.xml';
    }
}
