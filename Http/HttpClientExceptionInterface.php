<?php

namespace Plugin\EcAuthLogin40\Http;

/**
 * 通信の失敗を表す例外。PSR-18 の Psr\Http\Client\ClientExceptionInterface に相当する。
 *
 * 呼び出し側はこのインタフェースだけを catch する。Guzzle 固有の例外型
 * (GuzzleHttp\Exception\*) は Http\GuzzleClient の中で捕まえてここに変換するため、
 * サービス層に漏れてこない。
 */
interface HttpClientExceptionInterface extends \Throwable
{
}
