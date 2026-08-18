<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Mollie\Concerns;

use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Testing\TestResponse;
use Mollie\Api\Http\Response as MollieResponse;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\BaseCollection;
use Mollie\Api\Resources\BaseResource;
use Tests\Feature\Integrations\Mollie\Http\Connect\StubMollieConnectClient;
use Throwable;

trait StubsMollieConnectClient
{
    protected ?StubMollieConnectClient $mollieConnectClient = null;

    /** @var array<string, array<int, mixed>> */
    protected array $mollieConnectCaptured = [
        'client_link_create' => [],
        'onboarding_status' => [],
        'organization_get' => [],
        'profile_page' => [],
        'profile_create' => [],
        'profile_get' => [],
        'permission_list' => [],
        'permission_get' => [],
    ];

    /**
     * @param  list<string>  $abilities
     * @return array{0:Consumer, 1:string}
     */
    protected function setupMollieConnectConsumer(array $abilities = [TokenAbilities::MOLLIE_READ]): array
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test-connect', $abilities)->plainTextToken;

        return [$consumer, $token];
    }

    protected function setPartnerToken(string $token = 'access_partner_xyz'): void
    {
        config(['services.mollie.partner_access_token' => $token]);
    }

    /** @param  array<string, callable>  $resolvers */
    protected function bindMollieConnectStubs(array $resolvers): StubMollieConnectClient
    {
        $captured = &$this->mollieConnectCaptured;

        $stubs = [];
        if (isset($resolvers['clientLinks'])) {
            $stubs['clientLinks'] = $this->makeClientLinksStub($resolvers['clientLinks'], $captured);
        }
        if (isset($resolvers['onboarding'])) {
            $stubs['onboarding'] = $this->makeOnboardingStub($resolvers['onboarding'], $captured);
        }
        if (isset($resolvers['organizations'])) {
            $stubs['organizations'] = $this->makeOrganizationsStub($resolvers['organizations'], $captured);
        }
        if (isset($resolvers['profiles'])) {
            $stubs['profiles'] = $this->makeProfilesStub($resolvers['profiles'], $captured);
        }
        if (isset($resolvers['permissions'])) {
            $stubs['permissions'] = $this->makePermissionsStub($resolvers['permissions'], $captured);
        }

        $this->mollieConnectClient = new StubMollieConnectClient($stubs);
        $this->app->instance(MollieApiClient::class, $this->mollieConnectClient);

        return $this->mollieConnectClient;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $extraHeaders
     */
    protected function callMollieConnect(string $token, string $method, string $uri, array $payload = [], array $extraHeaders = []): TestResponse
    {
        $headers = array_merge([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ], $extraHeaders);

        return $this->withHeaders($headers)->json($method, $uri, $payload);
    }

    /**
     * @template T of BaseResource
     *
     * @param  class-string<T>  $resourceClass
     * @param  array<string, mixed>  $bodyJson
     * @return T
     */
    protected function makeMollieResourceWithBody(string $resourceClass, array $bodyJson, MollieApiClient $client): BaseResource
    {
        /** @var T $resource */
        $resource = new $resourceClass($client);

        foreach ($bodyJson as $key => $value) {
            if ($key === '_links' || $key === '_embedded') {
                continue;
            }
            $resource->{$key} = $value;
        }

        $resource->setResponse($this->fakeMollieResponse($bodyJson));

        return $resource;
    }

    /**
     * @template T of \Mollie\Api\Resources\BaseCollection
     *
     * @param  class-string<T>  $collectionClass
     * @param  array<string, mixed>  $bodyJson  Wire-shape, bv. ['count' => 1, '_embedded' => ['profiles' => [...]]].
     * @return T
     */
    protected function makeMollieCollectionWithBody(string $collectionClass, array $bodyJson, MollieApiClient $client): BaseCollection
    {
        /** @var T $collection */
        $collection = new $collectionClass($client, [], null);
        $collection->setResponse($this->fakeMollieResponse($bodyJson));

        return $collection;
    }

    /** @param  array<string, mixed>  $bodyJson */
    private function fakeMollieResponse(array $bodyJson): MollieResponse
    {
        return new class($bodyJson) extends MollieResponse
        {
            /** @param array<string, mixed> $body */
            public function __construct(private readonly array $body) {}

            public function body(): string
            {
                return json_encode($this->body, JSON_THROW_ON_ERROR);
            }

            public function status(): int
            {
                return 200;
            }
        };
    }

    /**
     * @param  callable(string, mixed): (BaseResource|Throwable)  $resolver
     * @param  array<string, array<int, mixed>>  $captured
     */
    private function makeClientLinksStub(callable $resolver, array &$captured): object
    {
        return new class($resolver, $captured)
        {
            public function __construct(
                private $resolver,
                private array &$captured,
            ) {}

            public function create(array $payload): BaseResource
            {
                $this->captured['client_link_create'][] = $payload;
                $result = ($this->resolver)('create', $payload);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    /**
     * @param  callable(string, mixed): (BaseResource|Throwable)  $resolver
     * @param  array<string, array<int, mixed>>  $captured
     */
    private function makeOnboardingStub(callable $resolver, array &$captured): object
    {
        return new class($resolver, $captured)
        {
            public function __construct(
                private $resolver,
                private array &$captured,
            ) {}

            public function status(): BaseResource
            {
                $this->captured['onboarding_status'][] = null;
                $result = ($this->resolver)('status', null);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    /**
     * @param  callable(string, mixed): (BaseResource|Throwable)  $resolver
     * @param  array<string, array<int, mixed>>  $captured
     */
    private function makeOrganizationsStub(callable $resolver, array &$captured): object
    {
        return new class($resolver, $captured)
        {
            public function __construct(
                private $resolver,
                private array &$captured,
            ) {}

            public function get(string $id, bool $testmode = false): BaseResource
            {
                $this->captured['organization_get'][] = $id;
                $result = ($this->resolver)('get', $id);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    /**
     * @param  callable(string, mixed): (mixed|Throwable)  $resolver
     * @param  array<string, array<int, mixed>>  $captured
     */
    private function makeProfilesStub(callable $resolver, array &$captured): object
    {
        return new class($resolver, $captured)
        {
            public function __construct(
                private $resolver,
                private array &$captured,
            ) {}

            public function page(?string $from = null, ?int $limit = null): mixed
            {
                $this->captured['profile_page'][] = ['from' => $from, 'limit' => $limit];
                $result = ($this->resolver)('page', ['from' => $from, 'limit' => $limit]);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function create(array $payload): BaseResource
            {
                $this->captured['profile_create'][] = $payload;
                $result = ($this->resolver)('create', $payload);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function get(string $id, bool $testmode = false): BaseResource
            {
                $this->captured['profile_get'][] = $id;
                $result = ($this->resolver)('get', $id);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    /**
     * @param  callable(string, mixed): (mixed|Throwable)  $resolver
     * @param  array<string, array<int, mixed>>  $captured
     */
    private function makePermissionsStub(callable $resolver, array &$captured): object
    {
        return new class($resolver, $captured)
        {
            public function __construct(
                private $resolver,
                private array &$captured,
            ) {}

            public function list(): mixed
            {
                $this->captured['permission_list'][] = null;
                $result = ($this->resolver)('list', null);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }

            public function get(string $id, bool $testmode = false): BaseResource
            {
                $this->captured['permission_get'][] = $id;
                $result = ($this->resolver)('get', $id);
                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            }
        };
    }
}
