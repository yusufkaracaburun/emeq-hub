<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Enums\Provider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Bewaakt de indeling van app/Integrations. De regel is kort genoeg om te
 * onthouden en te automatiseren: heet een map zoals een Provider-enum-case, dan
 * is de inhoud van die provider; anders is het gedeeld en mag er geen enkele
 * provider in voorkomen.
 *
 * Zonder deze tests zakt die scheiding terug — een gedeelde klasse die "even"
 * Exact importeert is precies hoe de vorige indeling ontstond.
 */
final class IntegrationLayerStructureTest extends TestCase
{
    private const INTEGRATIONS = __DIR__.'/../../app/Integrations';

    /**
     * Gedeelde mappen. Elke andere map direct onder app/Integrations moet een
     * Provider-case zijn — een nieuwe naam dwingt een bewuste keuze af.
     *
     * @var list<string>
     */
    private const SHARED = ['Contracts', 'Errors', 'Exceptions', 'OAuth', 'PassThrough', 'Webhooks'];

    public function test_elke_map_is_gedeeld_of_een_provider(): void
    {
        $allowed = array_merge(
            self::SHARED,
            array_map(fn (Provider $p): string => $p->name, Provider::cases()),
        );

        foreach (glob(self::INTEGRATIONS.'/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);

            $this->assertContains($name, $allowed, sprintf(
                'app/Integrations/%s is niet gedeeld en heet niet zoals een Provider-case. '
                .'Kies één van beide: hernoem naar de enum-case, of zet de inhoud in een '
                .'gedeelde map en haal de providernaam eruit.',
                $name,
            ));
        }
    }

    public function test_de_gedeelde_laag_kent_geen_enkele_provider(): void
    {
        foreach (self::SHARED as $shared) {
            foreach ($this->phpFilesIn(self::INTEGRATIONS.'/'.$shared) as $file) {
                $code = $this->codeWithoutComments($file);

                foreach (Provider::cases() as $provider) {
                    $this->assertStringNotContainsString(
                        'App\\Integrations\\'.$provider->name.'\\',
                        $code,
                        sprintf(
                            '%s ligt in de gedeelde laag maar verwijst naar %s. Gedeelde code '
                            .'kiest geen provider — laat de registry dat doen.',
                            $this->relative($file),
                            $provider->name,
                        ),
                    );
                }
            }
        }
    }

    public function test_een_provider_kent_geen_andere_provider(): void
    {
        foreach (Provider::cases() as $owner) {
            foreach ($this->phpFilesIn(self::INTEGRATIONS.'/'.$owner->name) as $file) {
                $code = $this->codeWithoutComments($file);

                foreach (Provider::cases() as $other) {
                    if ($other === $owner) {
                        continue;
                    }

                    $this->assertStringNotContainsString(
                        'App\\Integrations\\'.$other->name.'\\',
                        $code,
                        sprintf(
                            '%s hoort bij %s maar verwijst naar %s. Wat twee providers delen '
                            .'hoort in de gedeelde laag, niet in een van de twee.',
                            $this->relative($file),
                            $owner->name,
                            $other->name,
                        ),
                    );
                }
            }
        }
    }

    /**
     * De afhankelijkheid wijst één kant op: de HTTP- en admin-laag mogen een
     * integratie aanroepen, een integratie kent ze niet.
     */
    public function test_integraties_kennen_de_http_en_admin_laag_niet(): void
    {
        $forbidden = ['App\\Http\\Controllers\\', 'App\\Filament\\'];

        foreach ($this->phpFilesIn(self::INTEGRATIONS) as $file) {
            $code = $this->codeWithoutComments($file);

            foreach ($forbidden as $namespace) {
                $this->assertStringNotContainsString($namespace, $code, sprintf(
                    '%s verwijst naar %s. Een integratie wordt aangeroepen, niet andersom.',
                    $this->relative($file),
                    rtrim($namespace, '\\'),
                ));
            }
        }
    }

    /**
     * Het canonieke boekhouddomein beschrijft wat de Hub belooft. Zodra het een
     * specifieke partner noemt is die belofte niet langer canoniek.
     */
    public function test_het_canonieke_domein_noemt_geen_partner(): void
    {
        foreach ($this->phpFilesIn(__DIR__.'/../../app/Accounting') as $file) {
            $code = $this->codeWithoutComments($file);

            foreach (Provider::cases() as $provider) {
                $this->assertStringNotContainsString(
                    'App\\Integrations\\'.$provider->name.'\\',
                    $code,
                    sprintf(
                        '%s hoort bij het canonieke domein maar verwijst naar %s. Laat de '
                        .'AccountingTargetRegistry de adapter kiezen.',
                        $this->relative($file),
                        $provider->name,
                    ),
                );
            }
        }
    }

    /** @return list<SplFileInfo> */
    private function phpFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Commentaar telt niet mee: een klasse mag uitleggen hoe Exact zich gedraagt
     * zonder ervan af te hangen.
     */
    private function codeWithoutComments(SplFileInfo $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    private function relative(SplFileInfo $file): string
    {
        $root = realpath(__DIR__.'/../../').'/';

        return str_replace($root, '', (string) realpath($file->getPathname()));
    }
}
