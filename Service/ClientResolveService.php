<?php

namespace Plugin\EcAuthLogin43\Service;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;

class ClientResolveService
{
    private const DEFAULT_DISCOVERY_URL = 'https://api.ec-auth.io';

    private const CLIENT_RESOLVE_PATH = '/platform/v1/client-resolve';

    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var RequestFactoryInterface
     */
    private $requestFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        LoggerInterface $logger,
    ) {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->logger = $logger;
    }

    /**
     * Client ID から Base URL を解決する。
     *
     * @return array{
     *     success: bool,
     *     status: int,
     *     tenant_name?: string,
     *     base_url?: string,
     *     organization_name?: string,
     *     error?: string,
     * }
     */
    public function resolve(string $clientId): array
    {
        if ($clientId === '') {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'client_id_empty',
            ];
        }

        $url = $this->getDiscoveryUrl().self::CLIENT_RESOLVE_PATH
            .'?'.http_build_query(['client_id' => $clientId]);

        $request = $this->requestFactory
            ->createRequest('GET', $url)
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $content = json_decode((string) $response->getBody(), true) ?? [];

            if ($statusCode === 200 && isset($content['tenant_name'], $content['base_url'])) {
                return [
                    'success' => true,
                    'status' => $statusCode,
                    'tenant_name' => $content['tenant_name'],
                    'base_url' => $content['base_url'],
                    'organization_name' => $content['organization_name'] ?? '',
                ];
            }

            $this->logger->error('EcAuth client-resolve error', [
                'status' => $statusCode,
                'response' => $content,
            ]);

            return [
                'success' => false,
                'status' => $statusCode,
                'error' => $content['error'] ?? 'unknown_error',
            ];
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('EcAuth client-resolve request failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Discovery エンドポイントの Base URL を取得する。
     * 環境変数 ECAUTH_CLIENT_RESOLVE_URL で上書き可能（ステージング・開発環境用）。
     */
    private function getDiscoveryUrl(): string
    {
        $override = getenv('ECAUTH_CLIENT_RESOLVE_URL');
        if ($override !== false && $override !== '') {
            return rtrim($override, '/');
        }

        return self::DEFAULT_DISCOVERY_URL;
    }
}
