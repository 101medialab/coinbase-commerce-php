<?php

namespace CoinbaseCommerce\Tests;

use CoinbaseCommerce\ApiClient;
use CoinbaseCommerce\ApiResponse;
use CoinbaseCommerce\Exceptions\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @backupStaticAttributes enabled
 */
class HttpCompatibilityTest extends TestCase
{
    public function testCustomClientPreservesRequestAndResponse()
    {
        $body = '{"data":{"id":"charge-id"}}';
        $response = new Response(201, ['X-Request-ID' => 'request-id'], $body);
        $history = [];
        $handler = HandlerStack::create(new MockHandler([$response]));
        $handler->push(Middleware::history($history));

        $client = ApiClient::init('test-key', 'https://example.com/', '2018-03-22');
        $client->setHttpClient(new Client(['handler' => $handler]));
        $result = $client->post('charges', ['name' => 'Test charge'], []);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://example.com/charges', (string)$request->getUri());
        $this->assertSame('test-key', $request->getHeaderLine('X-CC-Api-Key'));
        $this->assertSame('2018-03-22', $request->getHeaderLine('X-CC-Version'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame(['name' => 'Test charge'], \json_decode((string)$request->getBody(), true));
        $this->assertSame(201, $result->code);
        $this->assertSame($body, $result->body);
        $this->assertSame(['data' => ['id' => 'charge-id']], $result->bodyArray);
        $this->assertSame(['X-Request-ID' => ['request-id']], $result->headers);
        $this->assertSame('request-id', $result->requestId);
        $this->assertSame($result, $client->getLastResponse());
    }

    public function testEmptyResponseBody()
    {
        $response = new ApiResponse(new Response(204));

        $this->assertSame(204, $response->code);
        $this->assertSame('', $response->body);
        $this->assertNull($response->bodyArray);
        $this->assertNull($response->requestId);
    }

    public function testHttpErrorPreservesPsr7Messages()
    {
        $response = new Response(400, [], '{"error":{"type":"validation_error","message":"Invalid charge"}}');
        $client = ApiClient::init('test-key', 'https://example.com/');
        $client->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]));

        try {
            $client->get('charges', ['limit' => 2]);
            $this->fail('Expected validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame(400, $exception->getStatusCode());
            $this->assertSame('Invalid charge', $exception->getMessage());
            $this->assertSame('https://example.com/charges?limit=2', (string)$exception->getRequest()->getUri());
            $this->assertSame($response, $exception->getResponse());
            $this->assertInstanceOf(\GuzzleHttp\Exception\RequestException::class, $exception->getPrevious());
        }
    }
}
