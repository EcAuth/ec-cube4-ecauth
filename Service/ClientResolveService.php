<?php

namespace Plugin\EcAuthLogin43\Service;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;

class ClientResolveService
{
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

    /**
     * @var string Discovery エンドポイントの Base URL。
     *             services.yaml で %env(default:ecauth_default_discovery_url:ECAUTH_CLIENT_RESOLVE_URL)% からバインドされる。
     */
    private $discoveryUrl;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        LoggerInterface $logger,
        string $discoveryUrl
    ) {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->logger = $logger;
        $this->discoveryUrl = rtrim($discoveryUrl, '/');
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

        $url = $this->discoveryUrl.self::CLIENT_RESOLVE_PATH
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
}
