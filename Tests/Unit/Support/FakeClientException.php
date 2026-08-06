<?php

namespace Plugin\EcAuthLogin43\Tests\Unit\Support;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * ネットワーク障害を再現するための PSR-18 例外。
 */
class FakeClientException extends \RuntimeException implements ClientExceptionInterface
{
}
