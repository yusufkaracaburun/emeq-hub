<?php

declare(strict_types=1);

namespace App\Books\Services;

use Spatie\ArrayToXml\ArrayToXml;

/*
 * Exporteert de BTW-aangifte als plat XML-artefact (rubriek-codes + bedragen in
 * centen) voor hand-off/import bij de accountant. Dit is NIET het gecertificeerde
 * SBR/XBRL-Digipoort-formaat — digitaal indienen bij de Belastingdienst vereist
 * PKIoverheid-cert + de OB-taxonomie en is een apart traject.
 *
 * XML-generatie via spatie/array-to-xml: één-richtings array→XML is precies de
 * operatie hier (de declaration is al een array), de lib doet niets meer dan dat,
 * en het past in de Spatie-gebaseerde dependency-stack van de Hub. Geen reader
 * nodig (zoals saloonphp/xml-wrangler biedt) en geen parser (orchestral/parser is
 * juist lees-georiënteerd).
 */
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
