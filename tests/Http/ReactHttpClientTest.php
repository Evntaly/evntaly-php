<?php

namespace Evntaly\Tests\Http;

use Evntaly\Http\ReactHttpClient;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

class ReactHttpClientTest extends TestCase
{
    private $loop;
    private $browser;
    private $client;

    protected function setUp(): void
    {
        $this->loop = $this->createMock(LoopInterface::class);
        $this->browser = $this->createMock(Browser::class);
        $this->client = new ReactHttpClient('https://api.example.com', $this->loop, [
            'browser' => $this->browser,
            'timeout' => 5,
            'maxRetries' => 2,
            'headers' => ['X-Test' => 'test-value'],
        ]);
    }

    public function testSetBaseUrl()
    {
        $client = $this->client->setBaseUrl('https://api.newurl.com');
        $this->assertSame($client, $this->client);

        // Test the base URL is used in the request
        $deferred = new Deferred();
        $promise = $deferred->promise();

        $this->browser->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('GET'),
                $this->equalTo('https://api.newurl.com/test-endpoint'),
                $this->anything()
            )
            ->willReturn($promise);

        $this->client->requestAsync('GET', '/test-endpoint');
        $deferred->resolve('response');
    }

    public function testSetHeaders()
    {
        $client = $this->client->setHeaders(['X-Custom' => 'custom-value']);
        $this->assertSame($client, $this->client);

        // Test the headers are set in the request
        $deferred = new Deferred();
        $promise = $deferred->promise();

        $this->browser->expects($this->once())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($headers) {
                    return isset($headers['X-Custom']) && $headers['X-Custom'] === 'custom-value';
                })
            )
            ->willReturn($promise);

        $this->client->requestAsync('GET', '/test-endpoint');
        $deferred->resolve('response');
    }

    public function testSetMaxRetries()
    {
        $client = $this->client->setMaxRetries(5);
        $this->assertSame($client, $this->client);
    }

    public function testSetTimeout()
    {
        $client = $this->client->setTimeout(10);
        $this->assertSame($client, $this->client);
    }

    public function testRequestAsync()
    {
        $deferred = new Deferred();
        $promise = $deferred->promise();

        $this->browser->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('GET'),
                $this->equalTo('https://api.example.com/test-endpoint'),
                $this->callback(function ($headers) {
                    return isset($headers['X-Test']) && $headers['X-Test'] === 'test-value';
                })
            )
            ->willReturn($promise);

        $result = $this->client->requestAsync('GET', '/test-endpoint');
        $this->assertInstanceOf(PromiseInterface::class, $result);

        // Resolve the promise
        $deferred->resolve('success-response');

        // Test with POST data
        $deferred = new Deferred();
        $promise = $deferred->promise();

        $this->browser->expects($this->once())
            ->method('request')
            ->with(
                $this->equalTo('POST'),
                $this->equalTo('https://api.example.com/test-post'),
                $this->callback(function ($headers) {
                    return isset($headers['Content-Type']) &&
                           $headers['Content-Type'] === 'application/json';
                }),
                $this->equalTo(json_encode(['key' => 'value']))
            )
            ->willReturn($promise);

        $result = $this->client->requestAsync('POST', '/test-post', ['key' => 'value']);
        $deferred->resolve('success-response');
    }

    public function testBatchRequestAsync()
    {
        $deferred1 = new Deferred();
        $deferred2 = new Deferred();

        $this->browser->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $deferred1->promise(),
                $deferred2->promise()
            );

        $requests = [
            'req1' => ['method' => 'GET', 'endpoint' => '/endpoint1'],
            'req2' => ['method' => 'POST', 'endpoint' => '/endpoint2', 'data' => ['key' => 'value']],
        ];

        $results = $this->client->batchRequestAsync($requests);

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertArrayHasKey('req1', $results);
        $this->assertArrayHasKey('req2', $results);
        $this->assertInstanceOf(PromiseInterface::class, $results['req1']);
        $this->assertInstanceOf(PromiseInterface::class, $results['req2']);

        // Resolve the promises
        $deferred1->resolve('response1');
        $deferred2->resolve('response2');
    }

    public function testHasPendingRequests()
    {
        $deferred = new Deferred();
        $promise = $deferred->promise();

        $this->browser->expects($this->once())
            ->method('request')
            ->willReturn($promise);

        $this->assertFalse($this->client->hasPendingRequests());

        $this->client->requestAsync('GET', '/test-endpoint');

        $this->assertTrue($this->client->hasPendingRequests());

        $deferred->resolve('response');

        $this->assertFalse($this->client->hasPendingRequests());
    }

    public function testGetPendingRequestCount()
    {
        $deferred1 = new Deferred();
        $deferred2 = new Deferred();

        $this->browser->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $deferred1->promise(),
                $deferred2->promise()
            );

        $this->assertEquals(0, $this->client->getPendingRequestCount());

        $this->client->requestAsync('GET', '/endpoint1');
        $this->assertEquals(1, $this->client->getPendingRequestCount());

        $this->client->requestAsync('GET', '/endpoint2');
        $this->assertEquals(2, $this->client->getPendingRequestCount());

        $deferred1->resolve('response1');
        $this->assertEquals(1, $this->client->getPendingRequestCount());

        $deferred2->resolve('response2');
        $this->assertEquals(0, $this->client->getPendingRequestCount());
    }

    public function testCancelPendingRequests()
    {
        $deferred1 = new Deferred();
        $deferred2 = new Deferred();

        $this->browser->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $deferred1->promise(),
                $deferred2->promise()
            );

        $this->client->requestAsync('GET', '/endpoint1');
        $this->client->requestAsync('GET', '/endpoint2');

        $this->assertEquals(2, $this->client->getPendingRequestCount());

        $cancelCount = $this->client->cancelPendingRequests();

        $this->assertEquals(2, $cancelCount);
        $this->assertEquals(0, $this->client->getPendingRequestCount());
    }
}
