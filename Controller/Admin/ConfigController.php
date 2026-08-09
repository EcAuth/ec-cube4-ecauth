<?php

namespace Plugin\EcAuthLogin43\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\EcAuthLogin43\Form\Type\Admin\ConfigType;
use Plugin\EcAuthLogin43\Repository\ConfigRepository;
use Plugin\EcAuthLogin43\Service\BaseUrlValidator;
use Plugin\EcAuthLogin43\Service\ClientResolveService;
use Plugin\EcAuthLogin43\Service\PasskeyAuthService;
use Plugin\EcAuthLogin43\Service\TenantChangePolicy;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfigController extends AbstractController
{
    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    /**
     * @var ClientResolveService
     */
    protected $clientResolveService;

    /**
     * @var BaseUrlValidator
     */
    protected $baseUrlValidator;

    /**
     * @var TenantChangePolicy
     */
    protected $tenantChangePolicy;

    /**
     * @var PasskeyAuthService
     */
    protected $passkeyAuthService;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * 申込フォーム (ec-auth.io) の URL。services.yaml の
     * ecauth_default_signup_url / 環境変数 ECAUTH_SIGNUP_URL で決まる。
     *
     * @var string
     */
    protected $signupUrl;

    /**
     * マイページ (ec-auth.io) の URL。services.yaml の
     * ecauth_default_mypage_url / 環境変数 ECAUTH_MYPAGE_URL で決まる。
     *
     * @var string
     */
    protected $mypageUrl;

    public function __construct(
        ConfigRepository $configRepository,
        ClientResolveService $clientResolveService,
        BaseUrlValidator $baseUrlValidator,
        TenantChangePolicy $tenantChangePolicy,
        PasskeyAuthService $passkeyAuthService,
        TranslatorInterface $translator,
        string $signupUrl,
        string $mypageUrl
    ) {
        $this->configRepository = $configRepository;
        $this->clientResolveService = $clientResolveService;
        $this->baseUrlValidator = $baseUrlValidator;
        $this->tenantChangePolicy = $tenantChangePolicy;
        $this->passkeyAuthService = $passkeyAuthService;
        $this->translator = $translator;
        $this->signupUrl = $signupUrl;
        $this->mypageUrl = $mypageUrl;
    }

    /**
     * EC-CUBE 管理画面のプラグイン一覧 (/admin/store/plugin) で歯車アイコンを
     * 表示させるため、Container::underscore(Plugin.code) + '_admin_config' という
     * ルート名規約 (ec_auth_login43_admin_config) でも引けるよう別名を追加する。
     * 既存箇所は ecauth_login43_admin_config を使い続けるため両方残す。
     *
     * @Route("/%eccube_admin_route%/ecauth_login43/config", name="ecauth_login43_admin_config")
     * @Route("/%eccube_admin_route%/ecauth_login43/config", name="ec_auth_login43_admin_config")
     * @Template("@EcAuthLogin43/admin/config.twig")
     */
    public function index(Request $request)
    {
        $Config = $this->configRepository->get();
        $hasClientSecret = $Config && $Config->getClientSecret() !== null && $Config->getClientSecret() !== '';

        // フォームは configRepository が返した「管理対象エンティティ」に直接バインドされる。
        // handleRequest() を通した時点で $Config の値は入力値で上書きされてしまうため、
        // 新旧比較に使う値はここで退避しておく必要がある（#52）。
        $previousClientId = $Config !== null ? (string) $Config->getClientId() : '';
        $savedBaseUrl = $Config !== null ? (string) $Config->getEcauthBaseUrl() : '';

        $form = $this->createForm(ConfigType::class, $Config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $Config = $form->getData();

            // 接続先テナントの差し替えかどうか。判定はサーバー側のこの比較が行う。
            // 設定画面の confirm() は UI 上の保険にすぎず、JS を経由しない送信でも整合する。
            $clientIdChanged = $this->tenantChangePolicy->hasClientIdChanged(
                $previousClientId,
                $Config->getClientId(),
            );

            $clientSecret = $form->get('client_secret')->getData();

            // 接続先が変わるのに Client Secret が空だと、前のテナントの secret が
            // 残ったまま新しい client_id と組み合わさる。トークン交換もパスキー登録も
            // 通らない設定が「保存成功」として残り、原因が分かりにくいので保存前に弾く。
            if ($clientIdChanged && ($clientSecret === null || $clientSecret === '')) {
                $form->get('client_secret')->addError(
                    new FormError($this->translator->trans('ecauth_login43.admin.config.tenant_changed.secret_required')),
                );

                return $this->createViewParameters($form, $hasClientSecret, $previousClientId);
            }

            $inputUrl = trim((string) $Config->getEcauthBaseUrl());
            $discardedBaseUrl = '';
            if ($this->tenantChangePolicy->shouldDiscardBaseUrlInput($clientIdChanged, $inputUrl, $savedBaseUrl)) {
                // 事前入力のまま＝前の接続先の URL。捨てて新しい client_id から解決し直す。
                // ただし解決に失敗したときのために、捨てた値は候補として持っておく。
                $discardedBaseUrl = $inputUrl;
                $inputUrl = '';
            }

            $reusedBaseUrl = null;
            if ($inputUrl === '') {
                $resolved = $this->clientResolveService->resolve((string) $Config->getClientId());
                if ($resolved['success']) {
                    $candidateUrl = $resolved['base_url'];
                    // client-resolve 応答も無条件には信頼しない。応答が汚染されると
                    // トークン交換先ごと攻撃者のホストに向く（EcAuthDocs #101）。
                    $errorField = 'client_id';
                } elseif ($discardedBaseUrl !== '') {
                    // 解決できないなら、採れる候補は捨てた入力しか無い。ここで弾くと
                    // 「同じ EcAuth を複数テナントで共有し、URL を手動指定している」
                    // staging / 開発環境で接続先を切り替えられなくなる。しかも
                    // client_resolve.failed は「高度な設定で URL を直接指定してください」と
                    // 案内するのに、その値を捨てた結果のエラーなので、指示どおり同じ値を
                    // 入れ直しても同じところに戻ってきてしまう（#59 レビュー指摘）。
                    // 黙って引き継ぐのではなく、下の警告で管理者に確認を促す。
                    $candidateUrl = $discardedBaseUrl;
                    $errorField = 'ecauth_base_url';
                    $reusedBaseUrl = $discardedBaseUrl;
                } else {
                    $form->get('client_id')->addError(
                        new FormError($this->translator->trans('ecauth_login43.admin.config.client_resolve.failed')),
                    );

                    return $this->createViewParameters($form, $hasClientSecret, $previousClientId);
                }
            } else {
                $candidateUrl = $inputUrl;
                $errorField = 'ecauth_base_url';
            }

            $normalizedUrl = $this->baseUrlValidator->normalize($candidateUrl);
            if ($normalizedUrl === null) {
                $form->get($errorField)->addError(
                    new FormError($this->translator->trans('ecauth_login43.admin.config.base_url.not_allowed')),
                );

                return $this->createViewParameters($form, $hasClientSecret, $previousClientId);
            }

            $Config->setEcauthBaseUrl($normalizedUrl);

            if ($clientSecret !== null && $clientSecret !== '') {
                $Config->setClientSecret($clientSecret);
            }

            // 旧テナントで発番した subject のクリアは、設定の保存と同じ flush に載せる。
            // 「subject だけ消えて client_id は元のまま」のような中途半端な状態を
            // 作らないため（EC-CUBE 本体の TransactionListener がリクエスト全体を
            // 1 トランザクションに包むので、commit も一括で行われる）。
            $cleared = $clientIdChanged ? $this->passkeyAuthService->clearAllEcauthSubjects() : 0;

            $this->entityManager->persist($Config);
            $this->entityManager->flush();

            $this->addSuccess('ecauth_login43.admin.config.save.success', 'admin');

            if ($clientIdChanged) {
                // 引き継いだ URL は保存されたもの（正規化後）を出す。入力の表記ゆれを
                // そのまま見せると、実際に保存された値と食い違って確認の役に立たない。
                $this->onTenantChanged(
                    $request->getSession(),
                    $cleared,
                    $reusedBaseUrl === null ? null : $normalizedUrl,
                );
            }

            return $this->redirectToRoute('ecauth_login43_admin_config');
        }

        return $this->createViewParameters($form, $hasClientSecret, $previousClientId);
    }

    /**
     * 接続先テナント（client_id）を差し替えた後の後始末。
     *
     * ecauth_subject のクリアは flush 済みの前提でここに来る。
     *
     * @param int $cleared クリアした ecauth_subject の件数
     * @param string|null $reusedBaseUrl client_id から解決できず、前の接続先の URL を
     *                                   そのまま引き継いだ場合はその URL。通常は null
     */
    protected function onTenantChanged(SessionInterface $session, int $cleared, ?string $reusedBaseUrl = null): void
    {
        // 旧テナントで取得した access_token / credential_id / 進行中の session_id は
        // もう通用しない。残すとパスキー管理画面が不可解なエラーで一覧取得に失敗する。
        // _security_admin は触らない（管理者を保存操作の途中でログアウトさせないため）。
        $session->remove('ecauth_access_token');
        $session->remove('ecauth_current_credential_id');
        $session->remove('ecauth_register_session_id');
        $session->remove('ecauth_passkey_session_id');
        $session->remove('ecauth_state');
        $session->remove('ecauth_code_verifier');

        // 管理画面のフラッシュは alert.twig が {{ message|trans }} で描画するだけで
        // パラメータを渡せないため、件数の差し込みはここで済ませてから渡す。
        $message = $cleared === 0
            ? $this->translator->trans('ecauth_login43.admin.config.tenant_changed.no_target')
            : $this->translator->trans('ecauth_login43.admin.config.tenant_changed.cleared', ['%count%' => $cleared]);

        $this->addWarning($message, 'admin');

        if ($reusedBaseUrl !== null) {
            $this->addWarning(
                $this->translator->trans(
                    'ecauth_login43.admin.config.tenant_changed.base_url_reused',
                    ['%url%' => $reusedBaseUrl],
                ),
                'admin',
            );
        }
    }

    /**
     * @param string $savedClientId 保存済みの client_id（テンプレートの確認ダイアログ判定に使う）
     *
     * @return array<string, mixed>
     */
    protected function createViewParameters(FormInterface $form, bool $hasClientSecret, string $savedClientId): array
    {
        return [
            'form' => $form->createView(),
            'has_client_secret' => $hasClientSecret,
            'signup_url' => $this->signupUrl,
            'mypage_url' => $this->mypageUrl,
            'saved_client_id' => $savedClientId,
        ];
    }
}
