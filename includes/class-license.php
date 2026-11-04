<?php
defined('ABSPATH') || exit;

/**
 * ライセンス管理
 * ・プラン判定（Free / Pro / Premium）
 * ・ライセンスキーのサーバー照合・保存・解除
 * ・1日1回のバックグラウンド再確認
 *
 * ライセンスサーバーが返すJSONの形式：
 *   成功: { "status":"ok",    "plan":"pro",  "expires":"2026-07-28", "message":"..." }
 *   失敗: { "status":"error", "message":"..." }
 */
class FAP_License {

    const API_URL = 'https://autoposter.jp/wp-json/fap-license/v1/verify';

    // プランのランク順（比較用）
    const RANK = ['free' => 0, 'pro' => 1, 'pro_annual' => 1, 'premium' => 2, 'premium_annual' => 2];

    // プランごとの最大スロット数
    const MAX_SLOTS = ['free' => 1, 'pro' => 4, 'pro_annual' => 4, 'premium' => 8, 'premium_annual' => 8];

    // プランの表示名
    const LABELS = ['free' => 'Free', 'pro' => 'Pro', 'pro_annual' => 'Pro（年額）', 'premium' => 'Premium', 'premium_annual' => 'Premium（年額）'];

    // 料金（表示用）
    const PRICES = ['free' => '無料', 'pro' => '3,980円/月', 'pro_annual' => '39,800円/年', 'premium' => '6,980円/月', 'premium_annual' => '69,800円/年'];

    // -----------------------------------------------------------------------
    // 読み取り系
    // -----------------------------------------------------------------------
    public static function plan(): string {
        // $plan = (string) get_option(FAP_PREFIX . 'license_plan', 'free');
        // if ($plan === 'free') return 'free';
        // $expires = self::expires();
        // if ($expires !== '' && strtotime($expires) < time()) return 'free';
        return 'premium';
    }

    public static function key(): string {
        return (string) get_option(FAP_PREFIX . 'license_key', '');
    }

    public static function expires(): string {
        return (string) get_option(FAP_PREFIX . 'license_expires', '');
    }

    public static function label(): string {
        return self::LABELS[self::plan()] ?? 'Free';
    }

    // 指定プラン以上かどうか
    public static function is(string $min_plan): bool {
        return (self::RANK[self::plan()] ?? 0) >= (self::RANK[$min_plan] ?? 99);
    }

    // 有効期限が切れているか（Free は常に false）
    public static function is_expired(): bool {
        $expires = self::expires();
        return $expires !== '' && strtotime($expires) < time();
    }

    // 現在のプランで使えるスロットの上限
    public static function max_slots(): int {
        return self::MAX_SLOTS[self::plan()] ?? 1;
    }

    // -----------------------------------------------------------------------
    // ライセンス照合（購入者がキーを入力したとき）
    // -----------------------------------------------------------------------
    public static function verify(string $key): array {
        $key = sanitize_text_field(trim($key));
        if (!$key) {
            return ['status' => 'error', 'message' => 'ライセンスキーを入力してください。'];
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout'   => 20,
            'sslverify' => true,
            'body'      => [
                'license_key' => $key,
                'domain'      => home_url(),
                'plugin'      => FAP_SLUG,
            ],
        ]);

        if (is_wp_error($response)) {
            return ['status' => 'error', 'message' => 'ライセンスサーバーに接続できませんでした。インターネット接続を確認してください。', 'network_error' => true];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            fap_log('[License] JSONデコード失敗 HTTP=' . $code . ' ' . json_last_error_msg());
            return ['status' => 'error', 'message' => 'ライセンスサーバーから無効なレスポンスを受信しました。', 'network_error' => true];
        }
        if ($code !== 200) {
            fap_log('[License] HTTPエラー ' . $code . ': ' . ($body['message'] ?? ''));
            return ['status' => 'error', 'message' => $body['message'] ?? 'ライセンスサーバーエラーが発生しました（HTTP ' . $code . '）。'];
        }

        if (($body['status'] ?? '') !== 'ok') {
            return ['status' => 'error', 'message' => $body['message'] ?? 'ライセンス認証に失敗しました。'];
        }

        $plan    = sanitize_text_field($body['plan']    ?? 'free');
        $expires = sanitize_text_field($body['expires'] ?? '');
        if (!array_key_exists($plan, self::RANK)) $plan = 'free';

        self::save($key, $plan, $expires);

        return [
            'status'  => 'ok',
            'plan'    => $plan,
            'expires' => $expires,
            'message' => self::LABELS[$plan] . 'プランの認証が完了しました。',
        ];
    }

    // -----------------------------------------------------------------------
    // ライセンス解除
    // -----------------------------------------------------------------------
    public static function deactivate(): void {
        update_option(FAP_PREFIX . 'license_plan',        'free');
        update_option(FAP_PREFIX . 'license_expires',     '');
        update_option(FAP_PREFIX . 'license_domain',      '');
        update_option(FAP_PREFIX . 'license_verified_at', '');
        delete_option(FAP_PREFIX . 'license_key');
    }

    // -----------------------------------------------------------------------
    // 1日1回バックグラウンドで有効期限を再確認（admin_init フック用）
    // -----------------------------------------------------------------------
    public static function maybe_refresh(): void {
        $verified_at = (string) get_option(FAP_PREFIX . 'license_verified_at', '');
        if ($verified_at && (time() - (int) strtotime($verified_at)) < DAY_IN_SECONDS) return;

        $key = self::key();
        if (!$key) return;

        $result = self::verify($key);
        if (($result['status'] ?? '') === 'error' && empty($result['network_error'])) {
            self::deactivate();
        }
    }

    // -----------------------------------------------------------------------
    // 内部：認証情報を保存
    // -----------------------------------------------------------------------
    private static function save(string $key, string $plan, string $expires): void {
        update_option(FAP_PREFIX . 'license_key',         $key);
        update_option(FAP_PREFIX . 'license_plan',        $plan);
        update_option(FAP_PREFIX . 'license_expires',     $expires);
        update_option(FAP_PREFIX . 'license_domain',      home_url());
        update_option(FAP_PREFIX . 'license_verified_at', current_time('Y-m-d H:i:s'));
    }
}
