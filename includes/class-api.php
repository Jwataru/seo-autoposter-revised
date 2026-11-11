<?php
defined('ABSPATH') || exit;

/**
 * DMM Web API v3 クライアント
 */
class FAP_API {

    const API_BASE = 'https://api.dmm.com/affiliate/v3/';

    // 直近の search() の集計（調べた件数・各理由で除外した件数）。呼び出し側がログ・メッセージに使う
    public array $last_stats = ['scanned' => 0, 'dup' => 0, 'sale' => 0, 'filtered' => 0, 'pages' => 0];

    // -----------------------------------------------------------------------
    // 低レベル HTTP（wp_remote_get 使用でサーバー互換性を最大化）
    // -----------------------------------------------------------------------
    private function get(string $endpoint, array $params): ?object {
        $url = add_query_arg($params, self::API_BASE . $endpoint);

        $response = wp_remote_get($url, [
            'timeout'    => 30,
            'sslverify'  => true,
            'user-agent' => 'Mozilla/5.0 (compatible; FAP/1.0)',
        ]);

        if (is_wp_error($response)) {
            fap_log('[API][Error] HTTP取得失敗: ' . $response->get_error_message());
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            fap_log('[API][Error] HTTP ' . $code . ' - ' . substr($body, 0, 300));
            return null;
        }

        if (!$body) {
            fap_log('[API][Error] レスポンスが空でした。');
            return null;
        }

        $decoded = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE || !$decoded) {
            fap_log('[API][Error] JSON解析失敗: ' . json_last_error_msg() . ' body=' . substr($body, 0, 200));
            return null;
        }

        return $decoded;
    }

    // -----------------------------------------------------------------------
    // APIキー検証 + フロア一覧取得
    // -----------------------------------------------------------------------
    public function verify(string $api_id, string $aff_id): array {
        $resp = $this->get('FloorList', [
            'api_id'       => $api_id,
            'affiliate_id' => $aff_id,
            'output'       => 'json',
        ]);

        if (!$resp || !isset($resp->result->site)) {
            return ['status' => 'error', 'message' => 'APIキーが正しくないか、接続できませんでした。', 'floors' => []];
        }

        $floors = [];
        foreach ($resp->result->site as $site) {
            $site_name = (string) $site->name;
            // DMM.com（一般）サイトはスキップ
            if (str_contains($site_name, 'DMM.com') || str_contains($site_name, 'DMM.COM')) {
                continue;
            }
            foreach ($site->service as $service) {
                foreach ($service->floor as $floor) {
                    $floors[] = [
                        'site_name'    => $site_name,
                        'site_code'    => (string) $site->code,
                        'service_name' => (string) $service->name,
                        'service_code' => (string) $service->code,
                        'floor_name'   => (string) $floor->name,
                        'floor_code'   => (string) $floor->code,
                        'key'          => $site->code . '/' . $service->code . '/' . $floor->code,
                        'label'        => $floor->name . '（' . $service->name . '）',
                    ];
                }
            }
        }

        if (empty($floors)) {
            fap_log('[API][Verify] フロアが0件でした。サイト数=' . count((array)$resp->result->site));
            return ['status' => 'error', 'message' => 'フロア情報を取得できませんでした。APIキーを確認してください。', 'floors' => []];
        }

        return ['status' => 'ok', 'message' => 'APIキーを確認しました。', 'floors' => $floors];
    }

    // -----------------------------------------------------------------------
    // 商品検索（重複スキップ込み）
    // -----------------------------------------------------------------------
    public function search(array $params, int $hits): array {
        $api_id = $params['api_id'];
        $aff_id = $params['aff_id'];

        $base = [
            'api_id'       => $api_id,
            'affiliate_id' => $aff_id,
            'site'         => $params['site'],
            'service'      => $params['service'],
            'floor'        => $params['floor'],
            'sort'         => $params['sort'] ?? 'rank',
            'output'       => 'json',
        ];

        if (!empty($params['keyword'])) {
            $base['keyword'] = $params['keyword'];
        }
        if (!empty($params['article_type']) && !empty($params['article_id'])) {
            $base['article']    = $params['article_type'];
            $base['article_id'] = $params['article_id'];
        }
        if (!empty($params['gte_date'])) $base['gte_date'] = $params['gte_date'];
        if (!empty($params['lte_date'])) $base['lte_date'] = $params['lte_date'];

        $sale_only  = !empty($params['sale_only']);
        $skip_dedup = !empty($params['skip_dedup']);
        // 呼び出し側の除外判定（NGワード・除外条件など）。true を返した作品だけを採用する。
        // 取得のループの中で判定するので、除外された作品は件数（$hits）に数えず、次のページへ進む。
        $filter     = (isset($params['filter']) && is_callable($params['filter'])) ? $params['filter'] : null;
        $stats      = ['scanned' => 0, 'dup' => 0, 'sale' => 0, 'filtered' => 0, 'pages' => 0];

        fap_log('[API][Search] site=' . $base['site'] . ' service=' . $base['service'] . ' floor=' . $base['floor'] . ' hits=' . $hits . ($sale_only ? ' sale_only=1' : ''));

        $items      = [];
        $offset     = 1;
        $batch_size = 100;
        $max_loops  = max(1, (int) ($params['max_pages'] ?? 20)); // 調べるページ数の上限（1ページ=100件）
        $loop       = 0;

        while (count($items) < $hits && $loop < $max_loops) {
            $loop++;
            $query = $base + ['hits' => $batch_size, 'offset' => $offset];
            $resp  = $this->get('ItemList', $query);

            if (!$resp) {
                fap_log('[API][Search] レスポンスなし (loop=' . $loop . ')');
                break;
            }

            $items_raw = isset($resp->result->items) ? (array) $resp->result->items : [];
            if (empty($items_raw)) {
                $result_count = isset($resp->result->result_count) ? (int)$resp->result->result_count : 0;
                fap_log('[API][Search] アイテムなし result_count=' . $result_count . ' (loop=' . $loop . ')');
                break;
            }

            $batch = $items_raw;
            $stats['pages']++;

            foreach ($batch as $item) {
                if (count($items) >= $hits) break;
                $cid = (string) ($item->content_id ?? '');
                if (!$cid) continue;
                $stats['scanned']++;
                if (!$skip_dedup && $this->already_posted($cid)) { $stats['dup']++;  continue; }
                if ($sale_only && !$this->is_on_sale($item))     { $stats['sale']++; continue; }
                if ($filter && !$filter($item))                  { $stats['filtered']++; continue; }
                $items[] = $item;
            }

            if (count($batch) < $batch_size) break;
            $offset += $batch_size;
        }

        $this->last_stats = $stats;
        fap_log('[API][Search] 最終取得数=' . count($items)
            . ' / 調べた件数=' . $stats['scanned'] . '（' . $stats['pages'] . 'ページ）'
            . ' 投稿済み除外=' . $stats['dup'] . ' セール対象外=' . $stats['sale'] . ' NG・除外条件=' . $stats['filtered']);
        return $items;
    }

    // -----------------------------------------------------------------------
    // 重複チェック（post_name = cid で判定）
    // -----------------------------------------------------------------------
    private function already_posted(string $cid): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_name = %s
                   AND post_status IN ('publish','draft','future','pending')",
                sanitize_title($cid)
            )
        );
        return $count > 0;
    }

    // -----------------------------------------------------------------------
    // セール判定（両方の価格フィールドが存在 = セール中とみなす）
    // ⚠ FANZAのAPIレスポンス構造に依存するため、フロアによっては誤検知の可能性あり
    // -----------------------------------------------------------------------
    public function is_on_sale(object $item): bool {
        if (!isset($item->prices->price) || !isset($item->prices->list_price)) return false;
        $price = (int) $item->prices->price;
        $list  = (int) $item->prices->list_price;
        return $list > 0 && $price < $list;
    }

    // -----------------------------------------------------------------------
    // CIDで1件検索（セール検知用）
    // -----------------------------------------------------------------------
    public function fetch_item_by_cid(string $cid, string $api_id, string $aff_id, string $site, string $service, string $floor): ?object {
        $params = [
            'api_id'       => $api_id,
            'affiliate_id' => $aff_id,
            'site'         => $site,
            'service'      => $service,
            'floor'        => $floor,
            'cid'          => $cid,
            'hits'         => 1,
            'output'       => 'json',
        ];
        $resp  = $this->get('ItemList', $params);
        $items = isset($resp->result->items) ? (array) $resp->result->items : [];
        return !empty($items) ? $items[0] : null;
    }

    // -----------------------------------------------------------------------
    // CIDの販売状態を確認（販売停止チェック用）
    // 'available' = 販売中 / 'discontinued' = 販売停止 / 'error' = 判定不能（API失敗）
    // API失敗と「正常応答で0件」を区別し、誤検知による削除を防ぐ
    // -----------------------------------------------------------------------
    public function check_availability(string $cid, string $api_id, string $aff_id, string $site, string $service, string $floor): string {
        $params = [
            'api_id'       => $api_id,
            'affiliate_id' => $aff_id,
            'site'         => $site,
            'service'      => $service,
            'floor'        => $floor,
            'cid'          => $cid,
            'hits'         => 1,
            'output'       => 'json',
        ];
        $resp = $this->get('ItemList', $params);
        if (!$resp || !isset($resp->result)) return 'error';
        if (isset($resp->result->status) && (int) $resp->result->status !== 200) return 'error';

        $items = isset($resp->result->items) ? (array) $resp->result->items : [];
        return empty($items) ? 'discontinued' : 'available';
    }

    // -----------------------------------------------------------------------
    // 商品ページのHTMLを取得（説明文スクレイピング用）
    // -----------------------------------------------------------------------
    public function fetch_page(string $url): string {
        if (!$url) return '';

        $response = wp_remote_get($url, [
            'timeout'    => 30,
            'sslverify'  => true,
            'user-agent' => 'Mozilla/5.0 (compatible; FAP/1.0)',
            'headers'    => [
                'Referer' => 'https://www.dmm.co.jp',
                'Cookie'  => 'age_check_done=1',
            ],
        ]);

        if (is_wp_error($response)) return '';
        return (string) wp_remote_retrieve_body($response);
    }

    // -----------------------------------------------------------------------
    // 動画系（video.dmm.co.jp）の説明文を内部GraphQL APIから取得
    // 新サイトはJS描画でHTMLにmeta descriptionが無いため、APIを直接叩く。
    // 認証不要。取得できなければ空文字を返す（呼び出し側でフォールバック）。
    // -----------------------------------------------------------------------
    public function fetch_description_graphql(string $cid): string {
        if (!$cid) return '';

        $payload = wp_json_encode([
            'query'     => 'query($id:ID!){ ppvContent(id:$id){ description } }',
            'variables' => ['id' => $cid],
        ]);

        if ($payload === false) {
            return '';
        }

        $response = wp_remote_post('https://api.video.dmm.co.jp/graphql', [
            'timeout'    => 20,
            'sslverify'  => true,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            'headers'    => [
                'Content-Type' => 'application/json',
                'Origin'       => 'https://video.dmm.co.jp',
                'Referer'      => 'https://video.dmm.co.jp/',
            ],
            'body'       => $payload,
        ]);

        if (is_wp_error($response)) {
            fap_log('[API][GraphQL] 取得失敗: ' . $response->get_error_message());
            return '';
        }

        $body    = wp_remote_retrieve_body($response);
        $decoded = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE || !$decoded) return '';

        return (string) ($decoded->data->ppvContent->description ?? '');
    }

    // -----------------------------------------------------------------------
    // フロア一覧をオプションから取得（キャッシュ済み）
    // -----------------------------------------------------------------------
    public static function get_floor_list(): array {
        return (array) get_option(FAP_PREFIX . 'floor_list', []);
    }

    // -----------------------------------------------------------------------
    // フロアキーを site/service/floor に分解
    // -----------------------------------------------------------------------
    public static function parse_floor_key(string $key): array {
        $parts = explode('/', $key, 3);
        return [
            'site'    => $parts[0] ?? '',
            'service' => $parts[1] ?? '',
            'floor'   => $parts[2] ?? '',
        ];
    }
}
