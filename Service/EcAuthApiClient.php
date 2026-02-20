<?php

namespace Plugin\EcAuthLogin43\Service;

use GuzzleHttp\Client;
use Plugin\EcAuthLogin43\Repository\ConfigRepository;
use Psr\Log\LoggerInterface;

class EcAuthApiClient
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ConfigRepository $configRepository,
        LoggerInterface $logger
    ) {
        $this->configRepository = $configRepository;
        $this->logger = $logger;
    }

    /**
     * パスキー認証オプションを取得する。
     *
     * @param string $rpId
     * @param string|null $b2bSubject
     *
     * @return array
     */
    public function authenticateOptions(string $rpId, ?string $b2bSubject = null): array
    {
        $body = [
            'client_id' => $this->getClientId(),
            'rp_id' => $rpId,
        ];
        if ($b2bSubject !== null) {
            $body['b2b_subject'] = $b2bSubject;
        }

        return $this->post('/b2b/passkey/authenticate/options', $body);
    }

    /**
     * パスキー認証を検証する。
     *
     * @param string $sessionId
     * @param string $redirectUri
     * @param string|null $state
     * @param array $response WebAuthn assertion response
     *
     * @return array
     */
    public function authenticateVerify(string $sessionId, string $redirectUri, ?string $state, array $response): array
    {
        $body = [
            'session_id' => $sessionId,
            'client_id' => $this->getClientId(),
            'redirect_uri' => $redirectUri,
            'response' => $response,
        ];
        if ($state !== null) {
            $body['state'] = $state;
        }

        return $this->post('/b2b/passkey/authenticate/verify', $body);
    }

    /**
     * パスキー登録オプションを取得する。
     *
     * @param string $rpId
     * @param string $b2bSubject
     * @param string $externalId
     * @param string|null $displayName
     * @param string|null $deviceName
     *
     * @return array
     */
    public function registerOptions(string $rpId, string $b2bSubject, string $externalId, ?string $displayName = null, ?string $deviceName = null): array
    {
        $body = [
            'client_id' => $this->getClientId(),
            'rp_id' => $rpId,
            'b2b_subject' => $b2bSubject,
            'external_id' => $externalId,
        ];
        if ($displayName !== null) {
            $body['display_name'] = $displayName;
        }
        if ($deviceName !== null) {
            $body['device_name'] = $deviceName;
        }

        return $this->postWithSecret('/b2b/passkey/register/options', $body);
    }

    /**
     * パスキー登録を完了する。
     *
     * @param string $sessionId
     * @param array $response WebAuthn attestation response
     * @param string|null $deviceName
     *
     * @return array
     */
    public function registerVerify(string $sessionId, array $response, ?string $deviceName = null): array
    {
        $body = [
            'session_id' => $sessionId,
            'client_id' => $this->getClientId(),
            'response' => $response,
        ];
        if ($deviceName !== null) {
            $body['device_name'] = $deviceName;
        }

        return $this->postWithSecret('/b2b/passkey/register/verify', $body);
    }

    /**
     * 登録済みパスキー一覧を取得する。
     *
     * @param string $accessToken
     *
     * @return array
     */
    public function listPasskeys(string $accessToken): array
    {
        return $this->request('GET', '/b2b/passkey/list', [], [
            'Authorization' => 'Bearer '.$accessToken,
        ]);
    }

    /**
     * パスキーを削除する。
     *
     * @param string $accessToken
     * @param string $credentialId
     *
     * @return array
     */
    public function deletePasskey(string $accessToken, string $credentialId): array
    {
        return $this->request('DELETE', '/b2b/passkey/'.urlencode($credentialId), [], [
            'Authorization' => 'Bearer '.$accessToken,
        ]);
    }

    /**
     * 認可コードをトークンに交換する。
     *
     * @param string $code
     * @param string $redirectUri
     *
     * @return array
     */
    public function exchangeToken(string $code, string $redirectUri): array
    {
        return $this->postForm('/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
        ]);
    }

    private function getClientId(): string
    {
        $Config = $this->configRepository->get();

        return $Config ? $Config->getClientId() ?? '' : '';
    }

    private function getClientSecret(): string
    {
        $Config = $this->configRepository->get();

        return $Config ? $Config->getClientSecret() ?? '' : '';
    }

    private function getBaseUrl(): string
    {
        $Config = $this->configRepository->get();

        return $Config ? rtrim($Config->getEcauthBaseUrl() ?? '', '/') : '';
    }

    /**
     * client_id のみで POST リクエストを送信する。
     */
    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * client_id + client_secret で POST リクエストを送信する。
     */
    private function postWithSecret(string $path, array $body): array
    {
        $body['client_secret'] = $this->getClientSecret();

        return $this->request('POST', $path, $body);
    }

    /**
     * application/x-www-form-urlencoded で POST リクエストを送信する。
     * OAuth2 /token エンドポイント用。
     */
    private function postForm(string $path, array $params): array
    {
        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            $this->logger->error('EcAuth Base URL is not configured');

            return [
                'status' => 500,
                'data' => ['error' => 'EcAuth Base URL is not configured'],
            ];
        }
        $url = $baseUrl.$path;
        $client = new Client();

        try {
            $response = $client->request('POST', $url, [
                'form_params' => $params,
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'http_errors' => false,
                'timeout' => 30,
            ]);
            $statusCode = $response->getStatusCode();
            $content = json_decode($response->getBody()->getContents(), true) ?? [];

            if ($statusCode >= 400) {
                $this->logger->error('EcAuth API error', [
                    'status' => $statusCode,
                    'path' => $path,
                    'response' => $this->redactSensitiveFields($content),
                ]);
            }

            return [
                'status' => $statusCode,
                'data' => $content,
            ];
        } catch (\Exception $e) {
            $this->logger->error('EcAuth API request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * EcAuth API にリクエストを送信する。
     */
    private function request(string $method, string $path, array $body = [], array $headers = []): array
    {
        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            $this->logger->error('EcAuth Base URL is not configured');

            return [
                'status' => 500,
                'data' => ['error' => 'EcAuth Base URL is not configured'],
            ];
        }
        $url = $baseUrl.$path;
        $client = new Client();

        $options = [
            'headers' => array_merge([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $headers),
            'http_errors' => false,
            'timeout' => 30,
        ];

        if (!empty($body)) {
            $options['json'] = $body;
        }

        try {
            $response = $client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $content = json_decode($response->getBody()->getContents(), true) ?? [];

            if ($statusCode >= 400) {
                $this->logger->error('EcAuth API error', [
                    'status' => $statusCode,
                    'path' => $path,
                    'response' => $this->redactSensitiveFields($content),
                ]);
            }

            return [
                'status' => $statusCode,
                'data' => $content,
            ];
        } catch (\Exception $e) {
            $this->logger->error('EcAuth API request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * ログ出力用にセンシティブなフィールドをマスクする。
     */
    private function redactSensitiveFields(array $content): array
    {
        foreach (['access_token', 'id_token', 'refresh_token', 'client_secret'] as $key) {
            if (isset($content[$key])) {
                $content[$key] = '[REDACTED]';
            }
        }

        return $content;
    }
}
