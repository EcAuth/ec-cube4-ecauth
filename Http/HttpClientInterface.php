<?php

namespace Plugin\EcAuthLogin40\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP クライアントの抽象。PSR-18 (Psr\Http\Client\ClientInterface) と同じ形にしてある。
 *
 * ## なぜ PSR-18 をそのまま使わないのか
 *
 * EC-CUBE 4.0/4.1 の依存に psr/http-client (PSR-18) と psr/http-factory (PSR-17) が
 * 含まれない。本体が持つのは psr/http-message (PSR-7) 1.0.1 と guzzlehttp/guzzle 6.x で、
 * Guzzle 6 は PSR-18 を実装していない (7.0 から)。
 *
 * プラグイン側で psr/http-client を require する手も一応あるが、4.0 系では避けたい。
 * Composer v1 のメタデータ提供が終了しているため、ec-cube/plugin-installer 以外の
 * 依存を持つプラグインは、利用者が EC-CUBE 本体の composer.json に vcs リポジトリを
 * 手で書き足さないとインストールできなくなる。
 * https://doc4.ec-cube.net/plugin_eccube40
 *
 * そこで「依存を増やさない」ことを優先し、PSR-18/PSR-17 に相当する最小限の
 * インタフェースだけをプラグイン内に置く。PSR-7 は本体にあるのでそのまま使う。
 * 実装は Http\GuzzleClient 1 クラスに封じてあり、サービス以外は Guzzle を知らない。
 */
interface HttpClientInterface
{
    /**
     * @throws HttpClientExceptionInterface 通信そのものに失敗した場合
     */
    public function sendRequest(RequestInterface $request): ResponseInterface;
}
