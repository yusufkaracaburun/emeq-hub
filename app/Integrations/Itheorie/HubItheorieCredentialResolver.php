<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie;

use App\Integrations\Itheorie\Settings\ItheorieSettings;
use Emeq\ItheorieApi\Contracts\ItheorieCredentialResolver;
use Emeq\ItheorieApi\Data\ItheorieCredentials;
use RuntimeException;

final readonly class HubItheorieCredentialResolver implements ItheorieCredentialResolver
{
    public function __construct(private ItheorieSettings $settings) {}

    public function resolve(): ItheorieCredentials
    {
        $test = $this->settings->environment === 'test';

        $username = $test ? $this->settings->username_test : $this->settings->username;
        $password = $test ? $this->settings->password_test : $this->settings->password;
        $baseUrl = $test ? $this->settings->base_url_test : $this->settings->base_url;

        if ($username === '' || $password === '' || $baseUrl === '') {
            throw new RuntimeException(sprintf(
                'iTheorie-credentials voor de %s-omgeving ontbreken. Vul ze in onder Beheer → Providers.',
                $test ? 'test' : 'live',
            ));
        }

        if ($this->settings->reseller === '') {
            throw new RuntimeException('iTheorie-resellernummer ontbreekt. Vul het in onder Beheer → Providers.');
        }

        return new ItheorieCredentials(
            username: $username,
            password: $password,
            reseller: $this->settings->reseller,
            baseUrl: $baseUrl,
        );
    }
}
