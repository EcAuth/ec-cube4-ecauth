<?php

namespace Plugin\EcAuthLogin43\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class ClientResolveService
{
    private const DEFAULT_DISCOVERY_URL = 'https://api.ec-auth.io';

    private const CLIENT_RESOLVE_PATH = '/platform/v1/client-resolve';

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
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

        $url = $this->getDiscoveryUrl().self::CLIENT_RESOLVE_PATH;
        $client = new Client();

        try {
            $response = $client->request('GET', $url, [
                'query' => ['client_id' => $clientId],
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'http_errors' => false,
                'timeout' => 10,
            ]);
            $statusCode = $response->getStatusCode();
            $content = json_decode($response->getBody()->getContents(), true) ?? [];

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
        } catch (\Exception $e) {
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
