<?php
/**
 * Plugin Name:       FANZA SEO Autoposter
 * Plugin URI:        https://autoposter.jp/
 * Description:       FANZAアフィリエイト記事を自動投稿し、構造化データ・OGタグ・画像サイトマップなど高度なSEO最適化を実現するプラグイン
 * Version:           1.14.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Autoposter
 * Author URI:        https://autoposter.jp/
 * License:           GPL v2 or later
 * Text Domain:       fanza-seo-autoposter
 */

defined('ABSPATH') || exit;

define('FAP_VERSION',  '1.14.0');
define('FAP_FILE',     __FILE__);
define('FAP_DIR',      plugin_dir_path(__FILE__));
define('FAP_URL',      plugin_dir_url(__FILE__));
define('FAP_PREFIX',   'fap_');
define('FAP_SLUG',     'fanza-seo-autoposter');
define('FAP_NONCE',    'fap_nonce_action');
define('FAP_SLOTS',    8);
define('FAP_LOG_FILE', WP_CONTENT_DIR . '/fap-logs/fap-autoposter.log');

// ---------------------------------------------------------------------------
// クラスファイルの読み込み（直接 require_once でオートローダー依存を排除）
// ---------------------------------------------------------------------------
require_once FAP_DIR . 'includes/class-license.php';
require_once FAP_DIR . 'includes/class-api.php';
require_once FAP_DIR . 'includes/class-sale-history.php';
require_once FAP_DIR . 'includes/class-ai.php';
require_once FAP_DIR . 'includes/class-poster.php';
require_once FAP_DIR . 'includes/class-seo.php';
require_once FAP_DIR . 'includes/class-frontend.php';
require_once FAP_DIR . 'includes/class-scheduler.php';
require_once FAP_DIR . 'includes/class-diff.php';
require_once FAP_DIR . 'includes/class-admin.php';
require_once FAP_DIR . 'includes/class-updater.php';

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------
register_activation_hook(FAP_FILE, 'fap_activate');
register_deactivation_hook(FAP_FILE, 'fap_deactivate');

function fap_protect_log_dir(): void {
    $log_dir  = WP_CONTENT_DIR . '/fap-logs';
    $htaccess = $log_dir . '/.htaccess';
    $marker   = '# BEGIN FAP Autoposter Log Protection';

    if (!is_dir($log_dir)) {
        wp_mkdir_p($log_dir);
    }

    if (file_exists($htaccess)) {
        $contents = file_get_contents($htaccess);
        if ($contents !== false && strpos($contents, $marker) !== false) return;
    }

    $block = $marker . "\nDeny from all\n# END FAP Autoposter Log Protection\n";
    file_put_contents($htaccess, $block, LOCK_EX);
}

function fap_activate(): void {
    fap_protect_log_dir();
    FAP_Sale_History::install();
    $defaults = [
        FAP_PREFIX . 'version'          => FAP_VERSION,
        FAP_PREFIX . 'auto_on'          => '0',
        FAP_PREFIX . 'exe_minute'       => 0,
        FAP_PREFIX . 'date_today'       => '0',
        FAP_PREFIX . 'date_3day'        => '0',
        FAP_PREFIX . 'date_range'       => '0',
        FAP_PREFIX . 's_date'           => '',
        FAP_PREFIX . 'e_date'           => '',
        FAP_PREFIX . 'seo_jsonld'       => '1',
        FAP_PREFIX . 'seo_og'           => '1',
        FAP_PREFIX . 'seo_twitter'      => '1',
        FAP_PREFIX . 'seo_twitter_card' => 'summary_large_image',
        FAP_PREFIX . 'seo_sitemap'      => '1',
        FAP_PREFIX . 'seo_breadcrumb'   => '0',
        FAP_PREFIX . 'floor_list'       => [],
        // 表示・機能（フロント）
        FAP_PREFIX . 'feat_sticky'        => '0',
        FAP_PREFIX . 'feat_sticky_text'   => 'この作品をFANZAで見る',
        FAP_PREFIX . 'feat_sticky_bg'     => '#e0306e',
        FAP_PREFIX . 'feat_sticky_bg2'    => '#c026d3',
        FAP_PREFIX . 'feat_sticky_color'  => '#ffffff',
        FAP_PREFIX . 'feat_sticky_border' => '',
        FAP_PREFIX . 'feat_sticky_radius' => 'medium',
        FAP_PREFIX . 'feat_notice'      => '0',
        FAP_PREFIX . 'feat_notice_text' => '※本ページはアフィリエイト広告（PR）を含みます。掲載作品は18歳未満の閲覧が禁止されたアダルトコンテンツです。',
        FAP_PREFIX . 'feat_dedup'       => '1',
        // ライセンス
        FAP_PREFIX . 'license_plan'        => 'free',
        FAP_PREFIX . 'license_key'         => '',
        FAP_PREFIX . 'license_expires'     => '',
        FAP_PREFIX . 'license_domain'      => '',
        FAP_PREFIX . 'license_verified_at' => '',
    ];
    foreach ($defaults as $key => $val) {
        if (get_option($key) === false) {
            update_option($key, $val);
        }
    }
    FAP_Scheduler::register();
    flush_rewrite_rules();
}

function fap_deactivate(): void {
    wp_clear_scheduled_hook('fap_hourly_hook');
    flush_rewrite_rules();
}

// ---------------------------------------------------------------------------
// Global helpers
// ---------------------------------------------------------------------------
function fap_log(string $message): void {
    static $dir_protected = false;
    if (!$dir_protected) {
        fap_protect_log_dir();
        $dir_protected = true;
    }
    $line = '[' . current_time('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents(FAP_LOG_FILE, $line, FILE_APPEND | LOCK_EX);

    // Trim log to last 600 lines (only when file exceeds ~60KB to avoid reading on every write)
    if (file_exists(FAP_LOG_FILE) && filesize(FAP_LOG_FILE) > 61440) {
        $raw = file(FAP_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw && count($raw) > 600) {
            file_put_contents(
                FAP_LOG_FILE,
                implode(PHP_EOL, array_slice($raw, -600)) . PHP_EOL,
                LOCK_EX
            );
        }
    }
}

function fap_opt(string $key, $default = '') {
    return get_option(FAP_PREFIX . $key, $default);
}

/**
 * wp-config.php の定数を優先し、なければ wp_options から取得する。
 * 対応定数: FAP_FANZA_API_ID / FAP_FANZA_AFF_ID / FAP_AI_API_KEY
 */
function fap_secret(string $const_name, string $opt_key, string $default = ''): string {
    if (defined($const_name)) return (string) constant($const_name);
    return (string) get_option(FAP_PREFIX . $opt_key, $default);
}

function fap_slot_opt(int $slot, string $key, $default = '') {
    return get_option(FAP_PREFIX . 's' . $slot . '_' . $key, $default);
}

// 定価とセール価格から割引率ラベル（例: 30%OFF）を返す。算出できない場合は空文字
// 価格表記の範囲記号を取り除く（FANZA APIは、価格が複数ある作品で「100~」のように返すことがある）。例: "100~" → "100"
function fap_clean_price($price): string {
    return trim(str_replace(['~', '〜', '～', '∼'], '', (string) $price));
}

function fap_discount_rate($list_price, $sale_price): string {
    $list = (int) preg_replace('/[^0-9]/', '', (string) $list_price);
    $sale = (int) preg_replace('/[^0-9]/', '', (string) $sale_price);
    if ($list <= 0 || $sale <= 0 || $sale >= $list) return '';
    return round((1 - $sale / $list) * 100) . '%OFF';
}

// ---------------------------------------------------------------------------
// PHP 7.4 ポリフィル（PHP 8.0 で追加された文字列関数）
// ---------------------------------------------------------------------------
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
function fap_bootstrap() {
    new FAP_SEO();
    new FAP_Frontend();
    new FAP_Scheduler();
    if (is_admin()) {
        FAP_Updater::init();
        // 1日1回ライセンスをバックグラウンド再確認
        add_action('admin_init', ['FAP_License', 'maybe_refresh']);
        // グローバル変数に保持してGCされないようにする
        global $fap_admin_instance;
        $fap_admin_instance = new FAP_Admin();
        new FAP_Diff(); // AIリライトの差分表示（記事編集画面・投稿一覧）
        // 価格の「~」（100~円）を、更新後に1回だけ修復する
        add_action('admin_init', function () {
            if (get_option('fap_repair_tilde_v1') === '1') return;
            $r = FAP_Poster::repair_price_tilde();
            update_option('fap_repair_tilde_v1', '1');
            fap_log('[Repair] 価格の「~」修復: メタ ' . $r['meta'] . ' 件 / タイトル ' . $r['titles'] . ' 件');
        });
        // ランダム文の文字化け（u306eu30bb…）を、更新後に1回だけ修復する
        add_action('admin_init', function () {
            if (get_option('fap_repair_unicode_v1') === '1') return;
            $r = FAP_Poster::repair_garbled_unicode();
            update_option('fap_repair_unicode_v1', '1');
            fap_log('[Repair] 文字化け修復: メタ ' . $r['meta'] . ' 件 / タイトル ' . $r['titles'] . ' 件');
        });
    }
}
add_action('plugins_loaded', 'fap_bootstrap', 10);
