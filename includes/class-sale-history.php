<?php
defined('ABSPATH') || exit;

/**
 * セール履歴の記録（v1.12.0）
 *
 * セール開始 / 終了 / セール中の価格変更を「イベント」として専用テーブルに蓄積する。
 * 目的はまず記録を始めること（履歴は記録開始日からしか貯まらない）。
 * 表示機能（記事内の履歴セクション・セールカレンダー等）は後続バージョンで実装する。
 *
 * event の種類:
 *   start … セール開始（price=定価, sale_price=セール価格）
 *   end   … セール終了（price=定価, sale_price=0）
 *   price … セール中の価格変更（price=定価, sale_price=変更後価格）
 */
class FAP_Sale_History {

    const DB_VER = '1';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'fap_sale_history';
    }

    // テーブル作成（activation時と、未作成のままlogが呼ばれた場合の保険）
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            cid VARCHAR(64) NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            event VARCHAR(10) NOT NULL,
            price INT UNSIGNED NOT NULL DEFAULT 0,
            sale_price INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY cid (cid),
            KEY event (event)
        ) {$charset};");
        update_option(FAP_PREFIX . 'sale_history_db_ver', self::DB_VER);
    }

    public static function maybe_install(): void {
        if (get_option(FAP_PREFIX . 'sale_history_db_ver') !== self::DB_VER) {
            self::install();
        }
    }

    // イベントを1件記録する（失敗しても投稿処理を止めない）
    public static function log(string $cid, int $post_id, string $event, $price = 0, $sale_price = 0): void {
        if ($cid === '' || !in_array($event, ['start', 'end', 'price'], true)) return;
        self::maybe_install();
        global $wpdb;
        $ok = $wpdb->insert(self::table(), [
            'cid'        => $cid,
            'post_id'    => $post_id,
            'event'      => $event,
            'price'      => max(0, (int) $price),
            'sale_price' => max(0, (int) $sale_price),
            'created_at' => current_time('mysql'),
        ], ['%s', '%d', '%s', '%d', '%d', '%s']);
        if ($ok === false) {
            fap_log('[SaleHistory] 記録失敗 cid=' . $cid . ' event=' . $event);
        }
    }

    // 指定CIDの履歴（新しい順）。将来の表示機能の土台
    public static function history(string $cid, int $limit = 50): array {
        self::maybe_install();
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE cid = %s ORDER BY id DESC LIMIT %d',
            $cid, $limit
        ));
        return $rows ?: [];
    }

    // 指定CIDの統計（セール回数・平均割引率・直近セール開始日時）
    public static function stats(string $cid): array {
        $rows = array_reverse(self::history($cid, 200));
        $count = 0;
        $rates = [];
        $last_start = '';
        foreach ($rows as $r) {
            if ($r->event !== 'start') continue;
            $count++;
            $last_start = $r->created_at;
            if ((int) $r->price > 0 && (int) $r->sale_price > 0 && (int) $r->sale_price < (int) $r->price) {
                $rates[] = (1 - (int) $r->sale_price / (int) $r->price) * 100;
            }
        }
        return [
            'count'      => $count,
            'avg_off'    => $rates ? (int) round(array_sum($rates) / count($rates)) : 0,
            'last_start' => $last_start,
        ];
    }
}
