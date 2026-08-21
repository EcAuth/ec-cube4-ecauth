<?php

namespace Plugin\EcAuthLogin40\Http;

use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 ストリームのファクトリ。PSR-17 の
 * Psr\Http\Message\StreamFactoryInterface と同じ形にしてある。
 *
 * 自前で持つ理由は HttpClientInterface の docblock を参照。
 */
interface StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface;
}
