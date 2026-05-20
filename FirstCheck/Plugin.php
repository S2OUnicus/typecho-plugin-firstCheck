<?php
namespace TypechoPlugin\FirstCheck;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Text;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 初回アクセス時にパスワード確認画面を表示し、任意で Google reCAPTCHA v2 を併用するプラグインです。
 *
 * @package FirstCheck
 * @author キセキ (S2OUnicus)
 * @version 1.0.1
 * @link https://github.com/S2OUnicus/typecho-plugin-firstCheck
 */
class Plugin implements PluginInterface
{
    private const SESSION_PREFIX = 'firstcheck_';
    private const ACTION_FIELD = '_firstcheck_action';
    private const ACTION_VERIFY = 'verify';
    private const MAX_ATTEMPTS = 5;
    private const MIN_PASSWORD_LENGTH = 3;
    private const MAX_PASSWORD_LENGTH = 14;

    private static $alreadyChecked = false;

    public static function activate()
    {
        try {
            \Typecho\Plugin::factory('index.php')->begin = [__CLASS__, 'guard'];
            \Typecho\Plugin::factory('Widget\Archive')->beforeRender = [__CLASS__, 'guard'];
            self::ensureLockDir();
            return _t('FirstCheck を有効化しました。テーマフォルダーに firstCheck.php を配置してください。');
        } catch (\Throwable $e) {
            throw new \Typecho\Plugin\Exception('FirstCheck activation failed: ' . $e->getMessage());
        }
    }

    public static function deactivate()
    {
        return _t('FirstCheck を無効化しました。');
    }

    public static function config(Form $form)
    {
        $prompt = new Textarea(
            'promptText',
            null,
            'パスワードを入力してください',
            _t('提示情報の文字'),
            _t('パスワード入力ボックスの上に表示する説明文。HTMLは使用できません。')
        );
        $form->addInput($prompt);

        $passwords = new Textarea(
            'gatePasswords',
            null,
            '',
            _t('パスワード（複数可）'),
            _t('1行に1つ、またはカンマ区切りで入力してください。数字とアルファベットのみ、各パスワードは3桁以上14桁以下です。入力欄数は設定済みパスワードの最大桁数から自動判断します。')
        );
        $form->addInput($passwords);

        $caseSensitive = new Radio(
            'caseSensitive',
            ['1' => _t('区別する'), '0' => _t('区別しない')],
            '0',
            _t('大文字・小文字の区別'),
            _t('すべてのパスワードに適用します。「区別しない」の場合、ABC と abc は同じパスワードとして扱われます。')
        );
        $form->addInput($caseSensitive);

        $passwordDisplayMode = new Radio(
            'passwordDisplayMode',
            [
                'masked' => _t('完全隠す'),
                'delayed' => _t('遅延暗号化（入力後200msだけ表示）'),
                'visible' => _t('そのまま表示')
            ],
            'masked',
            _t('入力中のパスワード表示方式'),
            _t('完全隠すは入力直後から黒丸で表示します。遅延暗号化は入力した桁だけ200ms後に黒丸へ変わります。そのまま表示は入力文字を隠しません。')
        );
        $form->addInput($passwordDisplayMode);

        $checkIndex = new Radio(
            'checkIndex',
            ['1' => _t('チェックする'), '0' => _t('チェックしない')],
            '1',
            _t('ブログの index もパスワードチェックする'),
            _t('通常の記事リストトップページ（/ または /index.php）にも確認画面を出すかどうかを選択します。初期値はチェックするです。')
        );
        $form->addInput($checkIndex);

        $background = new Text(
            'backgroundUrl',
            null,
            '',
            _t('背景画像 URL'),
            _t('例: https://example.com/bg.jpg または /usr/uploads/bg.jpg。CSS background-size: cover で表示します。')
        );
        $form->addInput($background);

        $metaAuthor = new Text(
            'metaAuthor',
            null,
            '',
            _t('author metaタグ'),
            _t('firstCheck.php の <meta name="author"> に出力する文字列です。HTMLは使用できません。空欄も可能です。')
        );
        $form->addInput($metaAuthor);

        $metaThemeColor = new Text(
            'metaThemeColor',
            null,
            '',
            _t('theme-color metaタグ'),
            _t('firstCheck.php の <meta name="theme-color"> に出力する色です。空欄、または #111827 のような16進カラーを指定してください。')
        );
        $form->addInput($metaThemeColor);

        $darkModeMode = new Radio(
            'darkModeMode',
            [
                'browser' => _t('ブラウザで判断'),
                'time' => _t('指定時間帯だけ darkmode'),
                'always' => _t('ずっと darkmode'),
                'manual' => _t('自動にしない')
            ],
            'always',
            _t('darkmode の自動判定'),
            _t('どの方式でも、確認画面の左下に丸い切替ボタンを表示し、ユーザーが手動で切り替えられます。')
        );
        $form->addInput($darkModeMode);

        $darkStartHour = new Text(
            'darkStartHour',
            null,
            '18',
            _t('darkmode 開始時刻'),
            _t('0〜23 の整数。指定時間帯方式で使います。初期値は18時です。')
        );
        $form->addInput($darkStartHour);

        $darkEndHour = new Text(
            'darkEndHour',
            null,
            '7',
            _t('darkmode 終了時刻'),
            _t('0〜23 の整数。指定時間帯方式で使います。初期値は7時です。18時〜7時のような日跨ぎにも対応します。')
        );
        $form->addInput($darkEndHour);

        $useCaptcha = new Radio(
            'useCaptcha',
            ['1' => _t('使う'), '0' => _t('使わない')],
            '0',
            _t('Google reCAPTCHA v2'),
            _t('「使う」の場合、パスワード確認前にCaptcha検証が必須です。ロード失敗やサーバー検証失敗は確認失敗として扱います。')
        );
        $form->addInput($useCaptcha);

        $captchaSiteKey = new Text(
            'captchaSiteKey',
            null,
            '',
            _t('Google reCAPTCHA v2 Site Key'),
            _t('reCAPTCHA v2「私はロボットではありません」チェックボックスの Site Key。')
        );
        $form->addInput($captchaSiteKey);

        $captchaSecretKey = new Password(
            'captchaSecretKey',
            null,
            '',
            _t('Google reCAPTCHA v2 Secret Key'),
            _t('サーバー側検証に使用する Secret Key。')
        );
        $form->addInput($captchaSecretKey);

        $blockMinutes = new Text(
            'blockMinutes',
            null,
            '60',
            _t('ブロック時間（分）'),
            _t('5回以上ミスした場合に入力を停止する時間。1〜1440分。')
        );
        $form->addInput($blockMinutes);

        $sessionMinutes = new Text(
            'sessionMinutes',
            null,
            '30',
            _t('確認後の有効時間（分）'),
            _t('正しいパスワードを入力してから再確認不要にする時間。1〜1440分。')
        );
        $form->addInput($sessionMinutes);

        $excluded = new Textarea(
            'excludedPaths',
            null,
            "/admin\n/index.php/admin\n/action\n/index.php/action\n/usr/uploads\n/usr/themes\n/usr/plugins\n/favicon.ico\n/robots.txt\n/sitemap\n/feed",
            _t('排除ディレクトリ・パス'),
            _t('1行に1つ。サイトURLのベースパスを除いた / から始まるパスを指定。* ワイルドカードも使用できます。管理画面や静的ファイルは必ず除外してください。')
        );
        $form->addInput($excluded);
    }

    public static function personalConfig(Form $form)
    {
        // 個人設定はありません。
    }

    public static function guard($archive = null): void
    {
        if (self::$alreadyChecked) {
            return;
        }
        self::$alreadyChecked = true;

        try {
            self::ensureSession();
            $config = self::configValues();

            if (self::isExcludedRequest($config)) {
                return;
            }

            if (self::isVerified()) {
                return;
            }

            $state = self::readAttemptState();
            $now = time();

            if (!empty($state['blocked_until']) && (int) $state['blocked_until'] > $now) {
                self::renderCheckPage($config, '', '', (int) $state['blocked_until']);
            }

            $configErrors = self::validateRuntimeConfig($config);

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST[self::ACTION_FIELD] ?? '') === self::ACTION_VERIFY) {
                $message = self::handleVerification($config, $configErrors);
                // 認証成功時はリダイレクトし、ブロック時は確認画面を表示します。
                self::renderCheckPage($config, '', $message, 0, $configErrors);
            }

            self::renderCheckPage($config, '', '', 0, $configErrors);
        } catch (\Throwable $e) {
            self::renderEmergencyPage($e);
        }
    }

    private static function handleVerification(array $config, array $configErrors): string
    {
        if ($configErrors) {
            return '設定が未完了、または不正です。管理者に連絡してください。';
        }

        if (!self::verifyCsrfToken($_POST['fc_token'] ?? '')) {
            self::registerFailure($config);
            return 'セキュリティトークンが無効です。ページを更新してもう一度お試しください。';
        }

        if ($config['useCaptcha'] === '1') {
            $captchaResponse = (string) ($_POST['g-recaptcha-response'] ?? '');
            if (!self::verifyCaptcha($config['captchaSecretKey'], $captchaResponse, $captchaError)) {
                self::registerFailure($config);
                return $captchaError ?: 'Captcha 検証に失敗しました。';
            }
        }

        $postedPassword = (string) ($_POST['fc_password'] ?? '');
        if (!self::isValidPasswordString($postedPassword) || !self::passwordMatches($config, $postedPassword)) {
            $blockedUntil = self::registerFailure($config);
            if ($blockedUntil > time()) {
                self::renderCheckPage($config, '', 'ミスが5回以上になったため、一時的にブロックしました。', $blockedUntil);
            }
            return 'パスワードが正しくありません。';
        }

        self::markVerified($config['sessionMinutes']);
        self::clearAttemptState();
        self::redirectToReturnUrl();
    }

    private static function configValues(): array
    {
        $defaults = [
            'promptText' => 'パスワードを入力してください',
            'gatePassword' => '',
            'gatePasswords' => '',
            'passwordLength' => 6, // 旧設定です。描画フォールバック以外では使用しません。
            'caseSensitive' => '0',
            'showPassword' => '0', // 旧設定です。passwordDisplayMode への移行時だけ使用します。
            'passwordDisplayMode' => '',
            'checkIndex' => '1',
            'backgroundUrl' => '',
            'metaAuthor' => '',
            'metaThemeColor' => '',
            'darkModeMode' => 'always',
            'darkStartHour' => 18,
            'darkEndHour' => 7,
            'useCaptcha' => '0',
            'captchaSiteKey' => '',
            'captchaSecretKey' => '',
            'blockMinutes' => 60,
            'sessionMinutes' => 30,
            'excludedPaths' => "/admin\n/index.php/admin\n/action\n/index.php/action\n/usr/uploads\n/usr/themes\n/usr/plugins\n/favicon.ico\n/robots.txt\n/sitemap\n/feed",
        ];

        try {
            $pluginConfig = Options::alloc()->plugin('FirstCheck');
        } catch (\Throwable $e) {
            $pluginConfig = null;
        }

        foreach ($defaults as $key => $default) {
            if ($pluginConfig && isset($pluginConfig->{$key})) {
                $defaults[$key] = $pluginConfig->{$key};
            }
        }

        $defaults['promptText'] = trim(strip_tags((string) $defaults['promptText']));
        $defaults['gatePasswords'] = trim((string) ($defaults['gatePasswords'] ?: $defaults['gatePassword']));
        $defaults['caseSensitive'] = (string) $defaults['caseSensitive'] === '0' ? '0' : '1';
        $defaults['showPassword'] = (string) $defaults['showPassword'] === '1' ? '1' : '0';
        $displayMode = (string) ($defaults['passwordDisplayMode'] ?? '');
        if (!in_array($displayMode, ['masked', 'delayed', 'visible'], true)) {
            $displayMode = $defaults['showPassword'] === '1' ? 'visible' : 'masked';
        }
        $defaults['passwordDisplayMode'] = $displayMode;
        $defaults['checkIndex'] = (string) ($defaults['checkIndex'] ?? '1') === '0' ? '0' : '1';
        $defaults['backgroundUrl'] = trim((string) $defaults['backgroundUrl']);
        $defaults['metaAuthor'] = self::sanitizeMetaText($defaults['metaAuthor'] ?? '', 120);
        $defaults['metaThemeColor'] = self::sanitizeThemeColor($defaults['metaThemeColor'] ?? '');
        $defaults['darkModeMode'] = in_array((string) $defaults['darkModeMode'], ['browser', 'time', 'always', 'manual'], true) ? (string) $defaults['darkModeMode'] : 'always';
        $defaults['darkStartHour'] = self::clampInt($defaults['darkStartHour'], 0, 23, 18);
        $defaults['darkEndHour'] = self::clampInt($defaults['darkEndHour'], 0, 23, 7);
        $defaults['useCaptcha'] = (string) $defaults['useCaptcha'] === '0' ? '0' : '1';
        $defaults['captchaSiteKey'] = trim((string) $defaults['captchaSiteKey']);
        $defaults['captchaSecretKey'] = trim((string) $defaults['captchaSecretKey']);
        $defaults['blockMinutes'] = self::clampInt($defaults['blockMinutes'], 1, 1440, 60);
        $defaults['sessionMinutes'] = self::clampInt($defaults['sessionMinutes'], 1, 1440, 30);
        $defaults['excludedPaths'] = (string) $defaults['excludedPaths'];
        $defaults['passwordMaxLength'] = self::passwordMaxLength($defaults);
        $defaults['passwordMinLength'] = self::MIN_PASSWORD_LENGTH;

        return $defaults;
    }

    private static function sanitizeMetaText($value, int $maxLength): string
    {
        $text = trim(strip_tags((string) $value));
        $text = preg_replace('/[\r\n\t]+/u', ' ', $text) ?: '';
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?: '';
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength, 'UTF-8');
        }
        return substr($text, 0, $maxLength * 4);
    }

    private static function sanitizeThemeColor($value): string
    {
        $color = trim((string) $value);
        if ($color === '') {
            return '';
        }
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color) ? $color : '';
    }

    private static function validateRuntimeConfig(array $config): array
    {
        $errors = [];
        $passwords = self::configuredPasswords($config);

        if (!$passwords) {
            $errors[] = 'パスワードを1つ以上設定してください。数字とアルファベットのみ、3桁以上14桁以下です。';
        }

        foreach ($passwords as $password) {
            if (!self::isValidPasswordString($password)) {
                $errors[] = 'パスワードは数字とアルファベットのみ、3桁以上14桁以下にしてください。';
                break;
            }
        }

        if ($config['useCaptcha'] === '1' && ($config['captchaSiteKey'] === '' || $config['captchaSecretKey'] === '')) {
            $errors[] = 'Google reCAPTCHA v2 を使う場合は Site Key と Secret Key を設定してください。';
        }

        if ($config['backgroundUrl'] !== '' && !self::isSafeBackgroundUrl($config['backgroundUrl'])) {
            $errors[] = '背景画像URLは http(s) URL または / から始まる相対URLにしてください。';
        }

        return array_values(array_unique($errors));
    }

    private static function configuredPasswords(array $config): array
    {
        $raw = (string) ($config['gatePasswords'] ?? '');
        if ($raw === '' && isset($config['gatePassword'])) {
            $raw = (string) $config['gatePassword'];
        }

        $items = preg_split('/[\r\n,]+/', $raw) ?: [];
        $passwords = [];
        foreach ($items as $item) {
            $password = trim((string) $item);
            if ($password === '') {
                continue;
            }
            $key = ((string) ($config['caseSensitive'] ?? '1') === '0') ? strtolower($password) : $password;
            $passwords[$key] = $password;
        }
        return array_values($passwords);
    }

    private static function passwordMaxLength(array $config): int
    {
        $max = 0;
        foreach (self::configuredPasswords($config) as $password) {
            $length = strlen($password);
            if ($length >= self::MIN_PASSWORD_LENGTH && $length <= self::MAX_PASSWORD_LENGTH) {
                $max = max($max, $length);
            }
        }
        if ($max === 0) {
            return self::clampInt($config['passwordLength'] ?? 6, self::MIN_PASSWORD_LENGTH, self::MAX_PASSWORD_LENGTH, 6);
        }
        return self::clampInt($max, self::MIN_PASSWORD_LENGTH, self::MAX_PASSWORD_LENGTH, 6);
    }

    private static function isValidPasswordString(string $password): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{' . self::MIN_PASSWORD_LENGTH . ',' . self::MAX_PASSWORD_LENGTH . '}$/', $password);
    }

    private static function passwordMatches(array $config, string $postedPassword): bool
    {
        $posted = ((string) ($config['caseSensitive'] ?? '1') === '0') ? strtolower($postedPassword) : $postedPassword;
        foreach (self::configuredPasswords($config) as $password) {
            $expected = ((string) ($config['caseSensitive'] ?? '1') === '0') ? strtolower($password) : $password;
            if (hash_equals($expected, $posted)) {
                return true;
            }
        }
        return false;
    }

    private static function isSafeBackgroundUrl(string $url): bool
    {
        if ($url === '' || self::contains($url, "\n") || self::contains($url, "\r") || self::contains($url, "\\")) {
            return false;
        }
        if (self::startsWith($url, '/')) {
            return !self::startsWith($url, '//');
        }
        return (bool) preg_match('#^https?://[^\s"\']+$#i', $url);
    }

    private static function isVerified(): bool
    {
        $until = (int) ($_SESSION[self::SESSION_PREFIX . 'verified_until'] ?? 0);
        $bind = (string) ($_SESSION[self::SESSION_PREFIX . 'bind'] ?? '');
        return $until > time() && hash_equals(self::browserBinding(), $bind);
    }

    private static function markVerified(int $minutes): void
    {
        if (!headers_sent()) {
            @session_regenerate_id(true);
        }
        $_SESSION[self::SESSION_PREFIX . 'verified_until'] = time() + ($minutes * 60);
        $_SESSION[self::SESSION_PREFIX . 'bind'] = self::browserBinding();
    }

    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!headers_sent()) {
            $secure = self::isHttps();
            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path' => '/',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            } else {
                session_set_cookie_params(0, '/', '', $secure, true);
            }
            @session_start();
        }
    }

    private static function browserBinding(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $lang = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        return hash_hmac('sha256', $ua . '|' . $lang, self::localSecret());
    }

    private static function localSecret(): string
    {
        $siteUrl = '';
        try {
            $siteUrl = (string) Options::alloc()->siteUrl;
        } catch (\Throwable $e) {
            $siteUrl = __TYPECHO_ROOT_DIR__;
        }
        return hash('sha256', __TYPECHO_ROOT_DIR__ . '|' . $siteUrl . '|FirstCheck');
    }

    private static function csrfToken(): string
    {
        $key = self::SESSION_PREFIX . 'csrf';
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[$key];
    }

    private static function verifyCsrfToken(string $token): bool
    {
        $saved = (string) ($_SESSION[self::SESSION_PREFIX . 'csrf'] ?? '');
        return $saved !== '' && $token !== '' && hash_equals($saved, $token);
    }

    private static function verifyCaptcha(string $secret, string $response, ?string &$error = null): bool
    {
        $error = null;
        if ($secret === '' || $response === '') {
            $error = 'Captcha を完了してください。';
            return false;
        }

        $payload = http_build_query([
            'secret' => $secret,
            'response' => $response,
            'remoteip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ], '', '&');

        $body = false;
        if (function_exists('curl_init')) {
            $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 6,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                ]);
                $body = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                if ($status < 200 || $status >= 300) {
                    $body = false;
                }
            }
        }

        if ($body === false) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $payload,
                    'timeout' => 6,
                    'ignore_errors' => false,
                ],
            ]);
            $body = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
        }

        if ($body === false || $body === '') {
            $error = 'Captcha サーバーへの接続、または検証に失敗しました。';
            return false;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            $error = 'Captcha サーバーから不正な応答が返されました。';
            return false;
        }

        if (($json['success'] ?? false) !== true) {
            $errorCodes = isset($json['error-codes']) && is_array($json['error-codes']) ? implode(', ', $json['error-codes']) : '';
            $error = $errorCodes !== '' ? 'Captcha 検証に失敗しました: ' . $errorCodes : 'Captcha 検証に失敗しました。';
            return false;
        }

        if (!empty($json['hostname']) && !self::captchaHostnameMatches((string) $json['hostname'])) {
            $error = 'Captcha のホスト名検証に失敗しました。';
            return false;
        }

        return true;
    }

    private static function captchaHostnameMatches(string $captchaHost): bool
    {
        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $requestHost = preg_replace('/:\\d+$/', '', $requestHost) ?: '';
        $captchaHost = strtolower(trim($captchaHost));
        if ($requestHost === '' || $captchaHost === '') {
            return true;
        }
        if ($requestHost === $captchaHost) {
            return true;
        }
        $normalizedRequestHost = self::stripWwwPrefix($requestHost);
        $normalizedCaptchaHost = self::stripWwwPrefix($captchaHost);
        return $normalizedRequestHost === $normalizedCaptchaHost;
    }

    private static function stripWwwPrefix(string $host): string
    {
        return self::startsWith($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function readAttemptState(): array
    {
        $state = ['count' => 0, 'blocked_until' => 0, 'last' => 0];
        $file = self::lockFilePath();
        if ($file && is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $state = array_merge($state, $decoded);
            }
        } elseif (isset($_SESSION[self::SESSION_PREFIX . 'attempt_state']) && is_array($_SESSION[self::SESSION_PREFIX . 'attempt_state'])) {
            $state = array_merge($state, $_SESSION[self::SESSION_PREFIX . 'attempt_state']);
        }

        $now = time();
        if (!empty($state['blocked_until']) && (int) $state['blocked_until'] <= $now) {
            self::clearAttemptState();
            return ['count' => 0, 'blocked_until' => 0, 'last' => 0];
        }
        if (!empty($state['last']) && (int) $state['last'] < $now - 86400) {
            self::clearAttemptState();
            return ['count' => 0, 'blocked_until' => 0, 'last' => 0];
        }
        return $state;
    }

    private static function writeAttemptState(array $state): void
    {
        $state['count'] = max(0, (int) ($state['count'] ?? 0));
        $state['blocked_until'] = max(0, (int) ($state['blocked_until'] ?? 0));
        $state['last'] = time();
        $file = self::lockFilePath();
        if ($file) {
            $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($tmp, json_encode($state), LOCK_EX) !== false) {
                @chmod($tmp, 0600);
                @rename($tmp, $file);
                @chmod($file, 0600);
                return;
            }
        }
        $_SESSION[self::SESSION_PREFIX . 'attempt_state'] = $state;
    }

    private static function clearAttemptState(): void
    {
        $file = self::lockFilePath();
        if ($file && is_file($file)) {
            @unlink($file);
        }
        unset($_SESSION[self::SESSION_PREFIX . 'attempt_state']);
    }

    private static function registerFailure(array $config): int
    {
        $state = self::readAttemptState();
        $state['count'] = (int) ($state['count'] ?? 0) + 1;
        if ($state['count'] >= self::MAX_ATTEMPTS) {
            $state['blocked_until'] = time() + ($config['blockMinutes'] * 60);
        }
        self::writeAttemptState($state);
        return (int) ($state['blocked_until'] ?? 0);
    }

    private static function ensureLockDir(): bool
    {
        $dir = self::lockDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir)) {
            if (!is_file($dir . '/index.html')) {
                @file_put_contents($dir . '/index.html', '');
            }
            if (!is_file($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            }
        }
        return is_dir($dir) && is_writable($dir);
    }

    private static function lockDir(): string
    {
        return rtrim(__TYPECHO_ROOT_DIR__, '/\\') . '/usr/uploads/firstcheck-locks';
    }

    private static function lockFilePath(): ?string
    {
        if (!self::ensureLockDir()) {
            return null;
        }
        return self::lockDir() . '/' . self::clientKey() . '.json';
    }

    private static function clientKey(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return hash_hmac('sha256', $ip . '|' . $ua, self::localSecret());
    }

    private static function isExcludedRequest(array $config): bool
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'POST') {
            return true;
        }

        $path = self::relativePath();
        $lower = strtolower($path);

        if ($lower === '/admin' || self::startsWith($lower, '/admin/') || $lower === '/index.php/admin' || self::startsWith($lower, '/index.php/admin/')) {
            return true;
        }

        // Typecho の action ルートにはログイン・ログアウト・コメントが含まれるため、常に除外します。
        if ($lower === '/action' || self::startsWith($lower, '/action/') || $lower === '/index.php/action' || self::startsWith($lower, '/index.php/action/')) {
            return true;
        }

        if ((string) ($config['checkIndex'] ?? '1') === '0' && self::isBlogIndexPath($path)) {
            return true;
        }

        if (preg_match('/\.(?:apng|avif|bmp|gif|ico|jpe?g|png|svg|webp|css|js|mjs|map|woff2?|ttf|otf|eot|mp4|webm|mp3|wav|pdf|zip)$/i', $path)) {
            return true;
        }

        $lines = preg_split('/\R/', (string) $config['excludedPaths']);
        foreach ($lines as $line) {
            $rule = trim((string) $line);
            if ($rule === '') {
                continue;
            }
            if ($rule[0] !== '/') {
                $rule = '/' . $rule;
            }
            $ruleLower = strtolower($rule);
            if (self::contains($ruleLower, '*')) {
                if (self::wildcardPathMatches($ruleLower, $lower)) {
                    return true;
                }
                continue;
            }
            $ruleLower = rtrim($ruleLower, '/');
            if ($ruleLower === '') {
                continue;
            }
            if ($lower === $ruleLower || self::startsWith($lower, $ruleLower . '/')) {
                return true;
            }
        }
        return false;
    }

    private static function isBlogIndexPath(string $path): bool
    {
        $path = strtolower(parse_url($path, PHP_URL_PATH) ?: '/');
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return true;
        }
        return $path === '/index.php';
    }

    private static function wildcardPathMatches(string $pattern, string $path): bool
    {
        if (function_exists('fnmatch') && fnmatch($pattern, $path)) {
            return true;
        }
        $regex = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#';
        return (bool) preg_match($regex, $path);
    }

    private static function relativePath(): string
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $decoded = rawurldecode($path);
        if (is_string($decoded) && $decoded !== '') {
            $path = $decoded;
        }

        try {
            $sitePath = parse_url((string) Options::alloc()->siteUrl, PHP_URL_PATH) ?: '';
            $sitePath = '/' . trim($sitePath, '/');
            if ($sitePath !== '/' && self::startsWith($path, $sitePath . '/')) {
                $path = substr($path, strlen($sitePath));
            } elseif ($sitePath !== '/' && $path === $sitePath) {
                $path = '/';
            }
        } catch (\Throwable $e) {
            // 元のパスをそのまま使います。
        }

        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return $path;
    }

    private static function renderCheckPage(array $config, string $info = '', string $error = '', int $blockedUntil = 0, array $configErrors = []): void
    {
        if (!headers_sent()) {
            http_response_code($blockedUntil > time() ? 429 : 200);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Frame-Options: DENY');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: origin-when-cross-origin');
            header('Content-Type: text/html; charset=UTF-8');
        }

        $passwords = self::configuredPasswords($config);
        $fc = [
            'siteName' => self::siteName(),
            'promptText' => $config['promptText'],
            'passwordLength' => $config['passwordMaxLength'],
            'passwordMinLength' => self::MIN_PASSWORD_LENGTH,
            'passwordDisplayMode' => $config['passwordDisplayMode'],
            'checkIndex' => $config['checkIndex'],
            'showPassword' => $config['passwordDisplayMode'] === 'visible', // 旧テンプレートとの互換用です。
            'maskDelayMs' => 200,
            'caseSensitive' => $config['caseSensitive'] === '1',
            'backgroundUrl' => self::isSafeBackgroundUrl($config['backgroundUrl']) ? $config['backgroundUrl'] : '',
            'metaAuthor' => $config['metaAuthor'],
            'metaThemeColor' => $config['metaThemeColor'],
            'darkModeMode' => $config['darkModeMode'],
            'darkStartHour' => $config['darkStartHour'],
            'darkEndHour' => $config['darkEndHour'],
            'useCaptcha' => $config['useCaptcha'] === '1',
            'captchaSiteKey' => $config['captchaSiteKey'],
            'info' => $info,
            'error' => $error,
            'configErrors' => $configErrors,
            'blockedUntil' => $blockedUntil,
            'remainingSeconds' => max(0, $blockedUntil - time()),
            'csrfToken' => self::csrfToken(),
            'returnUrl' => self::currentRelativeUri(),
            'actionField' => self::ACTION_FIELD,
            'actionValue' => self::ACTION_VERIFY,
            'maxAttempts' => self::MAX_ATTEMPTS,
            'sessionMinutes' => $config['sessionMinutes'],
            'blockMinutes' => $config['blockMinutes'],
            'passwordCount' => count($passwords),
        ];

        $fcEsc = static function ($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $fcCssString = static function ($value): string {
            return str_replace(["\\", "'", "\n", "\r", '</'], ["\\\\", "\\'", '', '', '<\/'], (string) $value);
        };

        $template = self::themeFirstCheckPath();
        if ($template && is_file($template)) {
            include $template;
            exit;
        }

        $bundledTemplate = __DIR__ . '/firstCheck.php.example';
        if (is_file($bundledTemplate)) {
            include $bundledTemplate;
            exit;
        }

        self::renderFallback($fc, $fcEsc, $fcCssString);
        exit;
    }

    private static function renderFallback(array $fc, callable $fcEsc, callable $fcCssString): void
    {
        ?>
<!doctype html>
<html lang="ja" data-fc-dark-mode="<?= $fcEsc($fc['darkModeMode']) ?>" data-fc-dark-start="<?= (int) $fc['darkStartHour'] ?>" data-fc-dark-end="<?= (int) $fc['darkEndHour'] ?>">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-siteapp">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="referrer" content="origin-when-cross-origin">
    <meta name="robots" content="index,follow">
    <meta name="viewport" content="width=device-width,height=device-height,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <meta name="format-detection" content="telephone=no,email=no">
    <meta name="google" content="notranslate">
    <meta name="author" content="<?= $fcEsc($fc['metaAuthor'] ?? '') ?>">
    <meta name="theme-color" content="<?= $fcEsc($fc['metaThemeColor'] ?? '') ?>">
    <title><?= $fcEsc($fc['siteName']) ?> - 安全確認</title>
    <script>(function(){try{var d=document.documentElement,k='FirstCheckThemeOverride',s=localStorage.getItem(k),m=d.dataset.fcDarkMode||'browser',a=parseInt(d.dataset.fcDarkStart||'18',10),b=parseInt(d.dataset.fcDarkEnd||'7',10),h=(new Date()).getHours(),dark=false;if(s==='dark'||s==='light'){dark=s==='dark';}else if(m==='browser'){dark=!!(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);}else if(m==='time'){dark=a===b?false:(a<b?h>=a&&h<b:h>=a||h<b);}else if(m==='always'){dark=true;}d.dataset.theme=dark?'dark':'light';}catch(e){}})();</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/uikit/3.23.13/css/uikit.min.css" integrity="sha512-giAxX2Dm0fHfTxCGThgfHXfyqC+NAsPAMI39ZDfs70vsKGALMfsNEbxlq6rZxPWWjH685ehdfvTQJkAWEgxOPw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <?php if ($fc['useCaptcha']): ?><script src="https://www.google.com/recaptcha/api.js" async defer onerror="window.fcRecaptchaLoadFailed=true"></script><?php endif; ?>
    <style>:root { color-scheme: light dark; } * { box-sizing: border-box; } body { min-height: 100vh; margin: 0; font-family: "Zen Maru Gothic", system-ui, sans-serif; background: linear-gradient(135deg, rgba(245,247,250,.72), rgba(195,207,226,.72))<?= $fc['backgroundUrl'] ? ", url('" . $fcCssString($fc['backgroundUrl']) . "')" : '' ?> center/cover fixed no-repeat; display: grid; place-items: center; padding: 24px; color: #111827; transition: background .25s ease, color .25s ease; } .fc-card { width: min(92vw, 540px); padding: clamp(22px, 4vw, 38px); border-radius: 28px; background: rgba(255,255,255,.86); backdrop-filter: blur(18px); box-shadow: 0 24px 90px rgba(0,0,0,.2); transition: background .25s ease, color .25s ease; } .fc-title { text-align: center; font-weight: 700; letter-spacing: .04em; margin-bottom: 18px; } .fc-prompt { text-align: center; margin-bottom: 22px; color: #4b5563; line-height: 1.8; } .fc-digits { display: grid; grid-template-columns: repeat(<?= (int) $fc['passwordLength'] ?>, minmax(0, 1fr)); gap: 8px; margin: 14px 0 20px; } .fc-digit { width: 100%; aspect-ratio: 1; text-align: center; font-size: clamp(20px, 5vw, 30px); font-weight: 700; border-radius: 14px; } .fc-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 16px; } .fc-recaptcha { display: flex; justify-content: center; overflow: hidden; margin-top: 10px; } .fc-note { font-size: 13px; text-align: center; opacity: .78; margin-top: 16px; line-height: 1.7; } .fc-dark-toggle { position: fixed; left: 18px; bottom: 18px; width: 48px; height: 48px; border: 0; border-radius: 999px; cursor: pointer; box-shadow: 0 10px 30px rgba(0,0,0,.24); background: rgba(255,255,255,.86); color: #111827; backdrop-filter: blur(12px); font-size: 22px; display: grid; place-items: center; } html[data-theme="dark"] body { background: linear-gradient(135deg, rgba(12,18,28,.76), rgba(22,27,39,.76))<?= $fc['backgroundUrl'] ? ", url('" . $fcCssString($fc['backgroundUrl']) . "')" : '' ?> center/cover fixed no-repeat; color: #f9fafb; } html[data-theme="dark"] .fc-card { background: rgba(17,24,39,.86); color: #f9fafb; } html[data-theme="dark"] .fc-prompt { color: #d1d5db; } html[data-theme="dark"] .fc-digit { background: rgba(255,255,255,.08); color: #fff; border-color: rgba(255,255,255,.18); } html[data-theme="dark"] .fc-dark-toggle { background: rgba(17,24,39,.9); color: #f9fafb; } @media (max-width: 500px) { .fc-digits { gap: 5px; } .fc-card { padding: 20px; } .g-recaptcha { transform: scale(.88); transform-origin: center; } .fc-dark-toggle { left: 12px; bottom: 12px; width: 44px; height: 44px; } }</style>
</head>
<body>
<main class="fc-card uk-animation-slide-bottom-small">
    <h1 class="fc-title uk-h3"><?= $fcEsc($fc['siteName']) ?></h1>
    <?php if ($fc['configErrors']): ?>
        <div class="uk-alert-danger" uk-alert><ul><?php foreach ($fc['configErrors'] as $err): ?><li><?= $fcEsc($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($fc['error']): ?><div class="uk-alert-danger" uk-alert><?= $fcEsc($fc['error']) ?></div><?php endif; ?>
    <?php if ($fc['info']): ?><div class="uk-alert-primary" uk-alert><?= $fcEsc($fc['info']) ?></div><?php endif; ?>
    <?php if ($fc['remainingSeconds'] > 0): ?>
        <p class="fc-prompt">入力が一時停止されています。<strong id="fc-countdown"><?= (int) $fc['remainingSeconds'] ?></strong> 秒後に再試行できます。</p>
    <?php else: ?>
        <p class="fc-prompt"><?= $fcEsc($fc['promptText']) ?></p>
        <form method="post" action="<?= $fcEsc($fc['returnUrl']) ?>" id="fc-form" autocomplete="off" novalidate data-min-length="<?= (int) $fc['passwordMinLength'] ?>" data-max-length="<?= (int) $fc['passwordLength'] ?>" data-captcha-required="<?= $fc['useCaptcha'] ? '1' : '0' ?>">
            <input type="hidden" name="<?= $fcEsc($fc['actionField']) ?>" value="<?= $fcEsc($fc['actionValue']) ?>">
            <input type="hidden" name="fc_token" value="<?= $fcEsc($fc['csrfToken']) ?>">
            <input type="hidden" name="fc_return" value="<?= $fcEsc($fc['returnUrl']) ?>">
            <input type="hidden" name="fc_password" id="fc-password">
            <div class="fc-digits" aria-label="password digits">
                <?php for ($i = 0; $i < (int) $fc['passwordLength']; $i++): ?>
                    <input class="uk-input fc-digit" type="text" inputmode="text" maxlength="1" data-display-mode="<?= $fcEsc($fc['passwordDisplayMode'] ?? ($fc['showPassword'] ? 'visible' : 'masked')) ?>" pattern="[A-Za-z0-9]" autocapitalize="off" autocomplete="off" aria-label="<?= $i + 1 ?> 桁目">
                <?php endfor; ?>
            </div>
            <?php if ($fc['useCaptcha']): ?><div class="fc-recaptcha"><div class="g-recaptcha" data-sitekey="<?= $fcEsc($fc['captchaSiteKey']) ?>"></div></div><?php endif; ?>
            <div class="fc-actions">
                <button type="submit" class="uk-button uk-button-primary">確認</button>
                <button type="reset" class="uk-button uk-button-default" id="fc-reset">リセット</button>
            </div>
        </form>
        <p class="fc-note">確認後 <?= (int) $fc['sessionMinutes'] ?> 分間有効です。<?= (int) $fc['maxAttempts'] ?> 回以上ミスすると <?= (int) $fc['blockMinutes'] ?> 分間ブロックされます。パスワードは <?= (int) $fc['passwordMinLength'] ?>〜<?= (int) $fc['passwordLength'] ?> 桁で入力できます。<?php if (!empty($fc['passwordCount']) && (int) $fc['passwordCount'] > 1): ?>有効なパスワードは複数設定されています。<?php endif; ?><?= $fc['caseSensitive'] ? '大文字・小文字は区別します。' : '大文字・小文字は区別しません。' ?></p>
    <?php endif; ?>
</main>
<button type="button" class="fc-dark-toggle" id="fc-dark-toggle" aria-label="darkmode 切替" title="darkmode 切替">☾</button>
<script>(function(){ const root=document.documentElement, storageKey='FirstCheckThemeOverride', toggle=document.getElementById('fc-dark-toggle'); function autoDark(){const mode=root.dataset.fcDarkMode||'browser', start=parseInt(root.dataset.fcDarkStart||'18',10), end=parseInt(root.dataset.fcDarkEnd||'7',10), hour=(new Date()).getHours(); if(mode==='browser') return !!(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches); if(mode==='time') return start===end?false:(start<end?hour>=start&&hour<end:hour>=start||hour<end); if(mode==='always') return true; return false;} function currentTheme(){try{const saved=localStorage.getItem(storageKey); if(saved==='dark'||saved==='light') return saved;}catch(e){} return autoDark()?'dark':'light';} function applyTheme(theme){root.dataset.theme=theme; if(toggle){toggle.textContent=theme==='dark'?'☀':'☾'; toggle.setAttribute('aria-pressed', theme==='dark'?'true':'false');}} applyTheme(currentTheme()); if(toggle){toggle.addEventListener('click',()=>{const next=root.dataset.theme==='dark'?'light':'dark'; try{localStorage.setItem(storageKey,next);}catch(e){} applyTheme(next);}); toggle.addEventListener('dblclick',()=>{try{localStorage.removeItem(storageKey);}catch(e){} applyTheme(currentTheme());});} if(window.matchMedia){const mql=window.matchMedia('(prefers-color-scheme: dark)'); const refresh=()=>{try{if(!localStorage.getItem(storageKey)) applyTheme(currentTheme());}catch(e){applyTheme(currentTheme());}}; if(mql.addEventListener) mql.addEventListener('change', refresh); else if(mql.addListener) mql.addListener(refresh);} setInterval(()=>{try{if(!localStorage.getItem(storageKey)) applyTheme(currentTheme());}catch(e){}}, 60000); const form=document.getElementById('fc-form'), boxes=Array.from(document.querySelectorAll('.fc-digit')), hidden=document.getElementById('fc-password'), reset=document.getElementById('fc-reset'), allowed=/^[A-Za-z0-9]$/; function hasGap(values){const firstEmpty=values.findIndex(v=>!v); return firstEmpty>=0 && values.slice(firstEmpty+1).some(Boolean);} boxes.forEach((box,index)=>{box.addEventListener('input',()=>{const value=box.value.slice(-1); box.value=allowed.test(value)?value:''; if(box.value&&boxes[index+1]) boxes[index+1].focus();}); box.addEventListener('keydown',(event)=>{if(event.key==='Backspace'&&!box.value&&boxes[index-1]) boxes[index-1].focus(); if(event.key==='ArrowLeft'&&boxes[index-1]) boxes[index-1].focus(); if(event.key==='ArrowRight'&&boxes[index+1]) boxes[index+1].focus();}); box.addEventListener('paste',(event)=>{event.preventDefault(); const text=(event.clipboardData||window.clipboardData).getData('text').replace(/[^A-Za-z0-9]/g,'').slice(0,boxes.length-index); [...text].forEach((char,i)=>{if(boxes[index+i]) boxes[index+i].value=char;}); const next=boxes[Math.min(index+text.length,boxes.length-1)]; if(next) next.focus();});}); if(form) form.addEventListener('submit',(event)=>{const values=boxes.map(box=>box.value); const min=parseInt(form.dataset.minLength||'3',10), max=parseInt(form.dataset.maxLength||String(boxes.length),10); if(hasGap(values)){event.preventDefault(); alert('左から順番に、空欄を挟まず入力してください。'); return;} hidden.value=values.join(''); if(hidden.value.length<min||hidden.value.length>max){event.preventDefault(); alert('パスワードは'+min+'〜'+max+'桁で入力してください。'); return;} if(form.dataset.captchaRequired==='1'){if(window.fcRecaptchaLoadFailed||!window.grecaptcha){event.preventDefault(); alert('Captchaを読み込めませんでした。通信環境を確認してページを更新してください。'); return;} if(!window.grecaptcha.getResponse()){event.preventDefault(); alert('Captchaを完了してください。'); return;}} }); if(reset) reset.addEventListener('click',()=>{boxes.forEach(box=>box.value=''); if(window.grecaptcha&&form&&form.dataset.captchaRequired==='1'){try{window.grecaptcha.reset();}catch(e){}} boxes[0]?.focus();}); boxes[0]?.focus(); const countdown=document.getElementById('fc-countdown'); if(countdown){let left=parseInt(countdown.textContent,10); setInterval(()=>{left=Math.max(0,left-1); countdown.textContent=String(left); if(left<=0) location.reload();},1000);} })();</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/uikit/3.23.13/js/uikit.min.js" integrity="sha512-g9wkFlti+bZT3YNTbVcMumimOS+hJSfbBEnKKP+e307qqQ3Ye4Bx7p/xUJ8yNRMotwudcofKL60ck1BGxk1t6Q==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</body>
</html>
        <?php
    }

    private static function renderEmergencyPage(\Throwable $e): void
    {
        error_log('[FirstCheck] ' . $e->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        echo "FirstCheck error. Please contact the site administrator.";
        exit;
    }

    private static function themeFirstCheckPath(): ?string
    {
        try {
            $options = Options::alloc();
            $theme = basename((string) $options->theme);
            $themeDir = rtrim(__TYPECHO_ROOT_DIR__, '/\\') . (defined('__TYPECHO_THEME_DIR__') ? __TYPECHO_THEME_DIR__ : '/usr/themes') . '/' . $theme;
            return $themeDir . '/firstCheck.php';
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function siteName(): string
    {
        try {
            return (string) Options::alloc()->title;
        } catch (\Throwable $e) {
            return 'Site';
        }
    }

    private static function currentRelativeUri(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($uri === '' || $uri[0] !== '/') {
            return '/';
        }
        if (preg_match('/[\r\n]/', $uri)) {
            return '/';
        }
        return $uri;
    }

    private static function redirectToReturnUrl(): void
    {
        $url = (string) ($_POST['fc_return'] ?? self::currentRelativeUri());
        if ($url === '' || $url[0] !== '/' || preg_match('/[\r\n]/', $url) || self::startsWith($url, '//')) {
            $url = '/';
        }
        if (!headers_sent()) {
            header('Location: ' . $url, true, 303);
        }
        exit;
    }

    private static function clampInt($value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int) $value));
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
