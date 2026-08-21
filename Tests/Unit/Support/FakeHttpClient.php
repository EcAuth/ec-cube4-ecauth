<?php

namespace Plugin\EcAuthLogin40\Tests\Unit\Support;

use Nyholm\Psr7\Response;
use Plugin\EcAuthLogin40\Http\HttpClientExceptionInterface;
use Plugin\EcAuthLogin40\Http\HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 応答をキューで返す HttpClientInterface のテストダブル。
 */
class FakeHttpClient implements HttpClientInterface
{
    /**
     * @var array<int, array{status: int, body: string}|\Throwable>
     */
    private $queue;

    /**
     * 送信されたリクエストの記録。
     *
     * @var array<int, RequestInterface>
     */
    public $requests = [];

    /**
     * @param array<int, array{status: int, body: string}|\Throwable> $queue
     */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \RuntimeException('FakeHttpClient received an unexpected request');
        }
        if ($next instanceof \Throwable) {
            /* @var HttpClientExceptionInterface&\Throwable $next */
            throw $next;
        }

        return new Response($next['status'], ['Content-Type' => 'application/json'], $next['body']);
    }
}
