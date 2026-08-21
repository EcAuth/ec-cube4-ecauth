<?php

namespace Plugin\EcAuthLogin40\Http;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Stream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Guzzle による HttpClientInterface / RequestFactoryInterface / StreamFactoryInterface の実装。
 *
 * **プラグイン内で Guzzle を直接触ってよいのはこのクラスだけ**。サービス層は
 * Http\* のインタフェースにだけ依存する。実装を差し替えるときも、ここと
 * Resource/config/services.yaml のバインドを直せば済むようにしてある。
 *
 * EC-CUBE 4.0/4.1 が同梱するのは Guzzle 6 (guzzlehttp/guzzle 6.4〜6.5、
 * guzzlehttp/psr7 1.6〜1.8) だが、ここで使っている API はいずれも Guzzle 7 /
 * psr7 2.x でも同じように動く。
 *
 * psr7 1.7 で入った GuzzleHttp\Psr7\Utils は 4.0 の 1.6.1 に無く、逆に 1.x の
 * 関数 API (GuzzleHttp\Psr7\stream_for) は 2.x で削除されている。どちらにも
 * 依存しないよう、ストリームは php://temp から自前で組み立てている。
 */
class GuzzleClient implements HttpClientInterface, RequestFactoryInterface, StreamFactoryInterface
{
    /**
     * @var GuzzleClientInterface
     */
    private $client;

    public function __construct(GuzzleClientInterface $client)
    {
        $this->client = $client;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            // http_errors => false は 4xx/5xx を例外にせずレスポンスとして返させる設定。
            // 呼び出し側 (EcAuthApiClient) がステータスコードで分岐するため、
            // ここで例外に変えてしまってはいけない。サービス定義側でも同じ値を
            // 渡しているが、クライアントを差し替えられても壊れないよう明示しておく。
            return $this->client->send($request, ['http_errors' => false]);
        } catch (\Exception $e) {
            // Guzzle の例外は GuzzleHttp\Exception\GuzzleException を実装するが、
            // その型に依存せず \Exception で受けて自前の例外へ変換する。
            throw new HttpClientException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        $resource = fopen('php://temp', 'r+');

        if ($resource === false) {
            throw new HttpClientException('リクエストボディ用のストリームを作成できませんでした');
        }

        if ($content !== '') {
            fwrite($resource, $content);
            rewind($resource);
        }

        return new Stream($resource);
    }
}
