<?php

namespace Plugin\EcAuthLogin43\Service;

use Plugin\EcAuthLogin43\Repository\ConfigRepository;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

class EcAuthApiClient
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var RequestFactoryInterface
     */
    private $requestFactory;

    /**
     * @var StreamFactoryInterface
     */
    private $streamFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ConfigRepository $configRepository,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        LoggerInterface $logger,
    ) {
        $this->configRepository = $configRepository;
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->logger = $logger;
    }

    /**
     * パスキー認証オプションを取得する。
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

        return $this->post('/v1/b2b/passkey/authenticate/options', $body);
    }

    /**
     * パスキー認証を検証する。
     *
     * @param array $response WebAuthn assertion response
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

        return $this->post('/v1/b2b/passkey/authenticate/verify', $body);
    }

    /**
     * パスキー登録オプションを取得する。
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

        return $this->postWithSecret('/v1/b2b/passkey/register/options', $body);
    }

    /**
     * パスキー登録を完了する。
     *
     * @param array $response WebAuthn attestation response
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

        return $this->postWithSecret('/v1/b2b/passkey/register/verify', $body);
    }

    /**
     * 登録済みパスキー一覧を取得する。
     */
    public function listPasskeys(string $accessToken): array
    {
        return $this->request('GET', '/v1/b2b/passkey/list', [], [
            'Authorization' => 'Bearer '.$accessToken,
        ]);
    }

    /**
     * パスキーを削除する。
     */
    public function deletePasskey(string $accessToken, string $credentialId): array
    {
        return $this->request('DELETE', '/v1/b2b/passkey/'.urlencode($credentialId), [], [
            'Authorization' => 'Bearer '.$accessToken,
        ]);
    }

    /**
     * 認可コードをトークンに交換する。
     */
    public function exchangeToken(string $code, string $redirectUri): array
    {
        return $this->postForm('/v1/token', [
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

        $request = $this->requestFactory
            ->createRequest('POST', $baseUrl.$path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream(http_build_query($params)));

        return $this->sendAndDecode($request, $path);
    }

    /**
     * EcAuth API にリクエストを送信する (JSON ボディ)。
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

        $request = $this->requestFactory
            ->createRequest($method, $baseUrl.$path)
            ->withHeader('Accept', 'application/json');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream((string) json_encode($body)));
        }

        return $this->sendAndDecode($request, $path);
    }

    /**
     * PSR-7 Request を送信し、ステータスと JSON デコード済みボディを返す共通処理。
     */
    private function sendAndDecode(RequestInterface $request, string $path): array
    {
        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $content = json_decode((string) $response->getBody(), true) ?? [];

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
        } catch (ClientExceptionInterface $e) {
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
