<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Consumer;
use App\Mollie\MollieAccessTokenResolver;
use App\Sanctum\TokenAbilities;
use Illuminate\Testing\TestResponse;
use Mollie\Api\Http\Response as MollieResponse;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\BaseCollection;
use Mollie\Api\Resources\BaseResource;
use Tests\Feature\Api\V1\Mollie\Connect\StubMollieConnectClient;
use Throwable;

/**
 * Test-helper voor Mollie-Connect-pass-through-tests. Binds een
 * StubMollieConnectClient direct via $this->app->instance() (geen
 * Mollie-facade-wrapper — Connect-base resolved de client via
 * app(MollieApiClient::class)), capture't access-token per call zodat
 * TokenResolverIntegrationTest beide token-paden symmetrisch kan asserten,
 * en levert een makeMollieResourceWithBody-helper die een echte Mollie
 * Response op de resource zet zodat AbstractMolliePassThroughController::
 * resourceToArray() de geneste velden (_links / _embedded) behoudt via
 * $response->body() i.p.v. de json_encode-fallback.
 *
 * D-07: Connect-routes hebben geen X-Account-Id-header — callMollieConnect()
 * volgt callMollie() (StubsMollieClient) shape maar laat die header weg.
 */
trait StubsMollieConnectClient
{
    protected ?StubMollieConnectClient $mollieConnectClient = null;

    /**
     * Per-resource captured ops + args zodat tests kunnen asserten welke
     * payload naar de stub ging.
     *
     * @var array<string, array<int, mixed>>
     */
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
     * Setup een Consumer + PAT voor Connect-tests. Geen Account/Connection —
     * D-07: Connect-routes hebben geen Account-context.
     *
     * @param  list<string>  $abilities
     * @return array{0:Consumer, 1:string}
     */
    protected function setupMollieConnectConsumer(array $abilities = [TokenAbilities::MOLLIE_READ]): array
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test-connect', $abilities)->plainTextToken;

        return [$consumer, $token];
    }

    /**
     * Zet de partner-access-token in config + forget de singleton-resolver
     * zodat hij vers gebound wordt met de nieuwe config bij de volgende call.
     */
    protected function setPartnerToken(string $token = 'access_partner_xyz'): void
    {
        config(['services.mollie.partner_access_token' => $token]);
        $this->app->forgetInstance(MollieAccessTokenResolver::class);
    }

    /**
     * Bouwt anonymous-class-stubs per Connect-resource, bindt een
     * StubMollieConnectClient via $this->app->instance(MollieApiClient::class, ...)
     * zodat de Connect-controllers (Plan 13-02 — app(MollieApiClient::class))
     * exact deze instance terugkrijgen.
     *
     * Resolver-signature per resource:
     *  - 'clientLinks'    : callable(string $op, mixed $arg): BaseResource|Throwable    ($op = 'create')
     *  - 'onboarding'     : callable(string $op, mixed $arg): BaseResource|Throwable    ($op = 'status')
     *  - 'organizations'  : callable(string $op, mixed $arg): BaseResource|Throwable    ($op = 'get'; $arg = id-string)
     *  - 'profiles'       : callable(string $op, mixed $arg): mixed|Throwable           ($op = 'page'|'create'|'get')
     *  - 'permissions'    : callable(string $op, mixed $arg): mixed|Throwable           ($op = 'list'|'get')
     *
     * @param  array<string, callable>  $resolvers
     */
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
     * Roep een Connect-route aan zonder X-Account-Id-header (D-07).
     *
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
     * Bouwt een Mollie-resource-instance met een echte Response zodat
     * $resource->getResponse()->body() de exacte JSON (incl. _links / _embedded)
     * teruggeeft. Voorkomt dat AbstractMolliePassThroughController::
     * resourceToArray() terugvalt op json_encode($resource) waar geneste velden
     * verdwijnen (BLOCKER-5-fix).
     *
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
     * Bouwt een collectie-instance (PermissionCollection / ProfileCollection /
     * …) met een echte Response zodat collectionToArray() de wire-shape (incl.
     * _embedded / _links / count) verbatim terugkrijgt.
     *
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

    /**
     * Bouwt een anonymous subclass van Mollie\Api\Http\Response met een no-op
     * constructor (geen PSR-request/response/pending-request nodig) en
     * geoverridete body() die de gewenste JSON teruggeeft. Implementeert het
     * ResourceOrigin-contract via parent-extends.
     *
     * @param  array<string, mixed>  $bodyJson
     */
    private function fakeMollieResponse(array $bodyJson): MollieResponse
    {
        return new class($bodyJson) extends MollieResponse
        {
            /** @param array<string, mixed> $body */
            public function __construct(private readonly array $body)
            {
                // Bewust geen parent::__construct() — we vullen alleen body()/status().
            }

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
