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
     * @var BaseUrlValidator
     */
    private $baseUrlValidator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ConfigRepository $configRepository,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        BaseUrlValidator $baseUrlValidator,
        LoggerInterface $logger
    ) {
        $this->configRepository = $configRepository;
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->baseUrlValidator = $baseUrlValidator;
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
     * @param string|null $codeChallenge PKCE (RFC 7636) の code_challenge。指定すると発行される
     *                                   認可コードに束縛され、トークン交換時に code_verifier が必須になる
     */
    public function authenticateVerify(string $sessionId, string $redirectUri, ?string $state, array $response, ?string $codeChallenge = null): array
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
        if ($codeChallenge !== null) {
            $body['code_challenge'] = $codeChallenge;
            $body['code_challenge_method'] = 'S256';
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
     *
     * @param string|null $codeVerifier PKCE (RFC 7636) の code_verifier。authenticate/verify で
     *                                  code_challenge を送った場合は必須
     */
    public function exchangeToken(string $code, string $redirectUri, ?string $codeVerifier = null): array
    {
        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
        ];
        if ($codeVerifier !== null) {
            $params['code_verifier'] = $codeVerifier;
        }

        return $this->postForm('/v1/token', $params);
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

    /**
     * 許可リストを通った Base URL のみを返す。許可されない場合は空文字を返し、
     * 呼び出し側でリクエストを中止させる。
     *
     * 設定画面での保存時にも検証しているが、DB を直接書き換えられた場合や
     * 許可リストを狭めた後に古い値が残っている場合に備え、実行時にも再検証する
     * （EcAuthDocs #101）。
     */
    private function getBaseUrl(): string
    {
        $Config = $this->configRepository->get();
        if ($Config === null) {
            return '';
        }

        // 未設定（プラグイン導入直後）と「設定されているが許可されていない」は
        // 運用上まったく別の事象なので、前者をここで警告にしない。
        // 未設定の場合は呼び出し側が "not configured" を記録する。
        $configured = trim((string) ($Config->getEcauthBaseUrl() ?? ''));
        if ($configured === '') {
            return '';
        }

        $baseUrl = $this->baseUrlValidator->normalize($configured);
        if ($baseUrl === null) {
            $this->logger->error('EcAuth Base URL is not allowed');

            return '';
        }

        return $baseUrl;
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
            // json_decode はスカラーも返しうる。?? はスカラーを拾わないため is_array で確実に配列化する。
            $decoded = json_decode((string) $response->getBody(), true);
            $content = is_array($decoded) ? $decoded : [];

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
