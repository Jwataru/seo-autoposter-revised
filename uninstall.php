<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// fap_ オプションをすべて削除（APIキー・ライセンスキー・AIキー含む）
$prefix = $wpdb->esc_like('fap_') . '%';
$wpdb->query(
    $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix)
);

// fap_ トランジェントを削除
$t_prefix  = $wpdb->esc_like('_transient_fap_') . '%';
$tt_prefix = $wpdb->esc_like('_transient_timeout_fap_') . '%';
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $t_prefix,
        $tt_prefix
    )
);

// セール履歴テーブルを削除
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fap_sale_history");

// スケジュール済みフックを削除
wp_clear_scheduled_hook('fap_hourly_hook');

// ログファイルを削除
$log_file = WP_CONTENT_DIR . '/uploads/fap-autoposter.log';
if (file_exists($log_file)) {
    unlink($log_file);
}
