<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Snelstart;

use App\Integrations\Snelstart\PassThrough\HeaderForwarder;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class HeaderForwarderTest extends TestCase
{
    public function test_forwards_only_whitelisted_headers(): void
    {
        $request = Request::create('/v1/snelstart/relaties', 'GET');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('If-Match', 'W/"etag-1"');
        $request->headers->set('If-None-Match', 'W/"etag-2"');
        $request->headers->set('Authorization', 'Bearer secret');
        $request->headers->set('Cookie', 'session=abc');
        $request->headers->set('User-Agent', 'Naschool/1.0');
        $request->headers->set('X-Account-Id', 'school-1');
        $request->headers->set('X-Custom-Header', 'foo');

        $forwarded = HeaderForwarder::forward($request);

        $this->assertSame('application/json', $forwarded['Accept']);
        $this->assertSame('application/json', $forwarded['Content-Type']);
        $this->assertSame('W/"etag-1"', $forwarded['If-Match']);
        $this->assertSame('W/"etag-2"', $forwarded['If-None-Match']);
        $this->assertArrayNotHasKey('Authorization', $forwarded);
        $this->assertArrayNotHasKey('Cookie', $forwarded);
        $this->assertArrayNotHasKey('User-Agent', $forwarded);
        $this->assertArrayNotHasKey('X-Account-Id', $forwarded);
        $this->assertArrayNotHasKey('X-Custom-Header', $forwarded);
    }

    public function test_strips_authorization_header_explicitly(): void
    {
        $request = Request::create('/v1/snelstart/relaties', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer xyz',
        ]);

        $forwarded = HeaderForwarder::forward($request);

        $this->assertArrayNotHasKey('Authorization', $forwarded);
    }

    public function test_strips_cookie_header_explicitly(): void
    {
        $request = Request::create('/v1/snelstart/relaties', 'GET', server: [
            'HTTP_COOKIE' => 'session=abc; XSRF-TOKEN=def',
        ]);

        $forwarded = HeaderForwarder::forward($request);

        $this->assertArrayNotHasKey('Cookie', $forwarded);
    }

    public function test_strips_x_account_id_header_explicitly(): void
    {
        $request = Request::create('/v1/snelstart/relaties', 'GET');
        $request->headers->set('X-Account-Id', 'school-1');

        $forwarded = HeaderForwarder::forward($request);

        $this->assertArrayNotHasKey('X-Account-Id', $forwarded);
    }

    public function test_omits_empty_content_type(): void
    {
        $request = Request::create('/v1/snelstart/relaties', 'GET');
        // GET-request zonder body: Content-Type wordt door Symfony niet gezet,
        // of komt als lege string binnen. In beide gevallen mag de forwarder 'm niet doorzetten.
        $request->headers->remove('Content-Type');

        $forwarded = HeaderForwarder::forward($request);

        if (array_key_exists('Content-Type', $forwarded)) {
            $this->assertNotSame('', $forwarded['Content-Type']);
        } else {
            $this->assertArrayNotHasKey('Content-Type', $forwarded);
        }
    }

    public function test_case_insensitive_header_matching(): void
    {
        $request = Request::create('/v1/snelstart/relaties', 'GET');
        $request->headers->set('accept', 'application/json');

        $forwarded = HeaderForwarder::forward($request);

        $this->assertArrayHasKey('Accept', $forwarded);
        $this->assertSame('application/json', $forwarded['Accept']);
    }
}
