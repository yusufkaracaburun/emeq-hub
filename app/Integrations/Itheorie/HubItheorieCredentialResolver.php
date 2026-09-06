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
        if ($this->settings->username === '' || $this->settings->password === '' || $this->settings->reseller === '') {
            throw new RuntimeException('iTheorie-credentials ontbreken. Vul ze in onder Beheer → Providers.');
        }

        return new ItheorieCredentials(
            username: $this->settings->username,
            password: $this->settings->password,
            reseller: $this->settings->reseller,
            baseUrl: $this->settings->base_url !== '' ? $this->settings->base_url : 'https://itheorie.nl/api/connect',
        );
    }
}
