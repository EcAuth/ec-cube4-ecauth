<?php

namespace Plugin\EcAuthLogin40\Tests\Unit\Support;

use Plugin\EcAuthLogin40\Http\HttpClientExceptionInterface;

/**
 * ネットワーク障害を再現するための HttpClientExceptionInterface 実装。
 */
class FakeClientException extends \RuntimeException implements HttpClientExceptionInterface
{
}
