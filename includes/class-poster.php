<?php
defined('ABSPATH') || exit;

/**
 * 投稿生成ロジック
 */
class FAP_Poster {

    private FAP_API $api;

    // 作品説明文のAIリライト用（assemble_data 内で参照）
    private bool    $ai_desc_enabled  = false; // 新規投稿の組み立て中だけ true
    private ?string $ai_desc_override = null;  // 再描画時：投稿時に保存したAI文（空文字=AIなし→原文を使う）
    private string  $ai_desc_result   = '';    // 今回AIが生成した説明文（投稿メタに保存する）
    private string  $ai_desc_src      = '';    // AIに渡した元の説明文（差分表示用に投稿メタへ保存する）

    public function __construct(FAP_API $api) {
        $this->api = $api;
    }

    // -----------------------------------------------------------------------
    // スロット実行（外部エントリーポイント）
    // -----------------------------------------------------------------------
    public function run_slot(int $slot, string $mode = 'auto'): array {
        if ($slot > FAP_License::max_slots()) {
            return $this->result(0, 0, "スロット {$slot} はご利用のプランでは使用できません。");
        }

        $api_id = fap_secret('FAP_FANZA_API_ID', 'api_id');
        $aff_id = fap_secret('FAP_FANZA_AFF_ID', 'aff_id');

        if (!$api_id || !$aff_id) {
            return $this->result(0, 0, 'APIキーが設定されていません。');
        }

        $floor_key = fap_slot_opt($slot, 'floor', '');
        $hits      = (int) fap_slot_opt($slot, 'hits', 0);

        if (!$floor_key || $hits < 1) {
            return $this->result(0, 0, 'スロット設定が不完全です（フロア・件数）。');
        }

        $fp = FAP_API::parse_floor_key($floor_key);
        if (!$fp['site'] || !$fp['service'] || !$fp['floor']) {
            return $this->result(0, 0, 'フロアキーが不正です。');
        }

        $params = [
            'api_id'       => $api_id,
            'aff_id'       => $aff_id,
            'site'         => $fp['site'],
            'service'      => $fp['service'],
            'floor'        => $fp['floor'],
            'sort'         => fap_slot_opt($slot, 'sort', 'rank'),
            'keyword'      => fap_slot_opt($slot, 'keyword', ''),
            'article_type' => fap_slot_opt($slot, 'article_type', ''),
            'article_id'   => fap_slot_opt($slot, 'article_id', ''),
            'sale_only'    => FAP_License::is('pro') && fap_slot_opt($slot, 'sale_only', '0') === '1',
        ];

        // 日付フィルター
        if ($mode === 'manual') {
            $from = fap_slot_opt($slot, 'from_date', '');
            $to   = fap_slot_opt($slot, 'to_date', '');
            if ($from) $params['gte_date'] = $from . 'T00:00:00';
            if ($to)   $params['lte_date'] = $to   . 'T23:59:59';
        } else {
            $this->apply_auto_date_filter($params);
        }

        $items = $this->api->search($params, $hits);
        if (empty($items)) {
            return $this->result(0, 0, '対象作品が見つかりませんでした。');
        }

        $posted  = 0;
        $skipped = 0;
        foreach ($items as $item) {
            if ($this->post_item($item, $slot, $fp['service'], $fp['floor'])) {
                $posted++;
            } else {
                $skipped++;
            }
        }

        return $this->result($posted, $skipped, '');
    }

    // -----------------------------------------------------------------------
    // 商品ID（content_id）を指定して投稿する。
    // スロットのフロア・テンプレート・タグ・カテゴリ等の設定で、IDごとに1件ずつ投稿する。
    // 返り値: ['posted' => 投稿件数, 'errors' => エラーメッセージの配列]
    // -----------------------------------------------------------------------
    public function run_cids(int $slot, array $cids): array {
        if ($slot > FAP_License::max_slots()) {
            return ['posted' => 0, 'errors' => ["スロット {$slot} はご利用のプランでは使用できません。"]];
        }

        $api_id = fap_secret('FAP_FANZA_API_ID', 'api_id');
        $aff_id = fap_secret('FAP_FANZA_AFF_ID', 'aff_id');
        if (!$api_id || !$aff_id) {
            return ['posted' => 0, 'errors' => ['APIキーが設定されていません。']];
        }

        $floor_key = fap_slot_opt($slot, 'floor', '');
        $fp        = FAP_API::parse_floor_key($floor_key);
        if (!$floor_key || !$fp['site'] || !$fp['service'] || !$fp['floor']) {
            return ['posted' => 0, 'errors' => ['スロットのフロアが設定されていません。']];
        }

        $posted = 0;
        $errors = [];
        foreach ($cids as $cid) {
            $item = $this->api->fetch_item_by_cid($cid, $api_id, $aff_id, $fp['site'], $fp['service'], $fp['floor']);
            if (!$item) {
                $errors[] = "{$cid}: 該当する商品が見つかりません（IDの誤り、このスロットのフロアと違う、またはAPI取得失敗）";
                continue;
            }

            $real_cid = (string) ($item->content_id ?? $cid);
            if (fap_opt('feat_dedup', '1') === '1' && $this->cid_exists($real_cid)) {
                $errors[] = "{$cid}: すでに投稿済みです";
                continue;
            }

            if ($this->post_item($item, $slot, $fp['service'], $fp['floor'])) {
                $posted++;
            } else {
                $errors[] = "{$cid}: 投稿されませんでした（ブラックリスト・除外条件に該当、または必要なデータが取得できませんでした）";
            }
        }

        fap_log("[CidPost][Slot {$slot}] posted:{$posted} errors:" . count($errors));
        return ['posted' => $posted, 'errors' => $errors];
    }

    private function apply_auto_date_filter(array &$params): void {
        $today    = fap_opt('date_today', '0') === '1';
        $threeday = fap_opt('date_3day',  '0') === '1';
        $range    = fap_opt('date_range', '0') === '1';

        if ($today || $threeday) {
            $end   = current_time('Y-m-d') . 'T23:59:59';
            $start = $threeday
                ? date('Y-m-d', strtotime('-3 days', current_time('timestamp'))) . 'T00:00:00'
                : current_time('Y-m-d') . 'T00:00:00';
            $params['gte_date'] = $start;
            $params['lte_date'] = $end;
        } elseif ($range) {
            $s = fap_opt('s_date', '');
            $e = fap_opt('e_date', '');
            if ($s) $params['gte_date'] = $s . 'T00:00:00';
            if ($e) $params['lte_date'] = $e . 'T23:59:59';
        }
    }

    private function result(int $posted, int $skipped, string $error): array {
        return compact('posted', 'skipped', 'error');
    }

    // -----------------------------------------------------------------------
    // 1作品分のテンプレート用データを組み立てて返す（投稿・プレビュー共用）
    // 取得できない場合は null
    // -----------------------------------------------------------------------
    private function assemble_data(object $item, int $slot, string $service, string $floor = ''): ?array {
        $cid      = (string) ($item->content_id ?? '');
        $title    = (string) ($item->title       ?? '');
        $aff_url  = (string) ($item->affiliateURL ?? '');
        $item_url = (string) ($item->URL          ?? '');
        $volume   = (string) ($item->volume       ?? '');
        $price    = '';
        $aff_id   = fap_secret('FAP_FANZA_AFF_ID', 'aff_id');

        if (!$cid || !$title) return null;

        // 価格
        if (isset($item->prices->list_price)) {
            $price = (string) $item->prices->list_price;
        } elseif (isset($item->prices->price)) {
            $price = (string) $item->prices->price;
        }

        // セール価格（定価 list_price と販売価格 price が両方ある = セール中のみ）
        $sale_price = $this->api->is_on_sale($item) ? (string) $item->prices->price : '';

        // 発売日
        $release_date = isset($item->date) ? substr((string)$item->date, 0, 10) : '';

        // メタ情報抽出
        $actress_arr   = $this->extract($item, 'actress');
        $actor_arr     = $this->extract($item, 'actor');
        $genre_arr     = $this->extract($item, 'genre');
        $maker_arr     = $this->extract($item, 'maker');
        $label_arr     = $this->extract($item, 'label');
        $director_arr  = $this->extract($item, 'director');
        $series_arr    = $this->extract($item, 'series');
        $author_arr    = $this->extract($item, 'author');
        $performer_arr = array_merge($actress_arr, $actor_arr);

        // 立ち読みURL（電子書籍のみ存在）
        $tachiyomi_url = isset($item->tachiyomi->affiliateURL) ? (string) $item->tachiyomi->affiliateURL : '';

        // 画像
        $img_large = (string) ($item->imageURL->large ?? '');
        // jm.jpg / js.jpg → jp.jpg への正規化
        if (str_contains($img_large, 'jm.jpg')) $img_large = str_replace('jm.jpg', 'jp.jpg', $img_large);
        if (str_contains($img_large, 'js.jpg')) $img_large = str_replace('js.jpg', 'jp.jpg', $img_large);
        $pkg_img        = $img_large ?: (string) ($item->imageURL->small ?? '');
        $smp_imgs       = $this->extract_sample_imgs($item);       // 大画像
        $smp_imgs_small = $this->extract_sample_imgs_small($item); // 小サムネ
        // パッケージ画像がサンプル配列に混入している場合は除外
        if ($pkg_img && in_array($pkg_img, $smp_imgs, true)) {
            $smp_imgs = array_values(array_filter($smp_imgs, fn($u) => $u !== $pkg_img));
        }

        // サンプル動画：フラグ + 実際のURLからCIDを抽出するために元URLも保持
        $has_movie = false;
        $movie_url = '';
        if (isset($item->sampleMovieURL)) {
            $has_movie = true;
            $movie_url = (string) ($item->sampleMovieURL->size_720_480
                         ?? $item->sampleMovieURL->size_644_414
                         ?? $item->sampleMovieURL->size_560_360
                         ?? $item->sampleMovieURL->size_476_306
                         ?? '');
        }

        // レビュー
        [$review_html, $review_html2, $review_avg, $review_cnt] = $this->build_review($item);

        // スクレイピング判定（テンプレートに応じて説明文・サンプル画像を取得）
        $title_tpl    = fap_slot_opt($slot, 'title_tpl',   '[title]');
        $excerpt_tpl  = fap_slot_opt($slot, 'excerpt_tpl', '');
        $genre_names  = array_column($genre_arr, 'name');
        $template     = $this->get_template($slot, $genre_names);

        $comment       = '';
        $comment_short = '';
        $scrape_imgs   = [];
        $tpl_all       = $template . $title_tpl . $excerpt_tpl;
        $wants_comment = str_contains($tpl_all, '[comment');
        $wants_imgs    = in_array($service, ['mono', 'ebook', 'doujin', 'monthly'], true)
                         && (str_contains($tpl_all, '[sample-cap]')   || str_contains($tpl_all, '[sample-photo]')
                          || str_contains($tpl_all, '[sample-flex]')  || str_contains($tpl_all, '[sample-nolink]'));

        if ($wants_comment || $wants_imgs) {
            $comment_text = '';

            // 1) 動画系（video.dmm.co.jp 移行済み）はGraphQL APIを優先（Proプラン以上）
            if ($wants_comment && $service === 'digital' && FAP_License::is('pro')) {
                $comment_text = $this->api->fetch_description_graphql($cid);
            }

            // 2) 必要ならHTMLを取得（旧式ページの説明文 or サンプル画像）
            if ($wants_imgs || ($wants_comment && !$comment_text)) {
                $html = $this->api->fetch_page($item_url);
                if ($wants_comment && !$comment_text) {
                    $comment_text = $this->extract_comment_text($html, $service, $floor);
                }
                if ($wants_imgs) {
                    $scrape_imgs = $this->scrape_sample_imgs($html);
                }
            }

            // 3) それでも空なら、API取得済み情報から代替紹介文を生成
            $is_fallback_comment = false;
            if ($wants_comment && !$comment_text) {
                $comment_text        = $this->build_fallback_comment($title, $actress_arr, $genre_arr, $maker_arr, $series_arr);
                $is_fallback_comment = true;
            }

            // 4) 作品説明文だけをAIでリライト（設定ON時・新規投稿時のみ。代替文は対象外）
            if ($comment_text && $wants_comment) {
                $comment_text = $this->maybe_rewrite_description($title, $comment_text, $is_fallback_comment);
            }

            if ($comment_text) {
                [$comment, $comment_short] = $this->build_comment_html($comment_text);
            }
        }

        // ランダムテキスト
        $randoms = $this->get_randoms($slot);

        return compact(
            'slot', 'cid', 'title', 'aff_url', 'aff_id', 'tachiyomi_url', 'item_url',
            'volume', 'price', 'sale_price', 'release_date',
            'actress_arr', 'actor_arr', 'performer_arr',
            'genre_arr', 'maker_arr', 'label_arr',
            'director_arr', 'series_arr', 'author_arr',
            'pkg_img', 'smp_imgs', 'smp_imgs_small', 'scrape_imgs',
            'has_movie', 'movie_url',
            'review_html', 'review_html2', 'review_avg', 'review_cnt',
            'comment', 'comment_short', 'randoms', 'service'
        );
    }

    // 作品説明文のAIリライト。
    //  ・新規投稿の組み立て中（$ai_desc_enabled）だけAIを呼ぶ。プレビュー・ランキングでは呼ばない
    //  ・セール検知の再描画では、投稿時に保存したAI文を使い回す（AIを再実行しない）
    //  ・失敗したら原文のまま（投稿は止めない）
    private function maybe_rewrite_description(string $title, string $text, bool $is_fallback): string {
        if ($this->ai_desc_override !== null) {
            return $this->ai_desc_override !== '' ? $this->ai_desc_override : $text;
        }
        if (!$this->ai_desc_enabled || $is_fallback) return $text;

        $r = FAP_AI::rewrite_description($title, $text);
        if (!$r['ok']) {
            fap_log('[AI][Desc] 説明文リライト失敗（原文を使用）: ' . $r['msg']);
            return $text;
        }
        $this->ai_desc_result = $r['text'];
        $this->ai_desc_src    = $text;
        return $r['text'];
    }

    // -----------------------------------------------------------------------
    // テンプレートのプレビュー用HTMLを返す（管理画面・AJAX）
    // 実際の投稿は作成せず、1件取得してテンプレートを描画した結果を返す
    // -----------------------------------------------------------------------
    public function preview_slot(int $slot): array {
        $api_id = fap_secret('FAP_FANZA_API_ID', 'api_id');
        $aff_id = fap_secret('FAP_FANZA_AFF_ID', 'aff_id');
        if (!$api_id || !$aff_id) {
            return ['ok' => false, 'msg' => 'APIキーが設定されていません。'];
        }

        $floor_key = fap_slot_opt($slot, 'floor', '');
        if (!$floor_key) {
            return ['ok' => false, 'msg' => 'スロットのフロアが未設定です。'];
        }
        $fp = FAP_API::parse_floor_key($floor_key);
        if (!$fp['site'] || !$fp['service'] || !$fp['floor']) {
            return ['ok' => false, 'msg' => 'フロアキーが不正です。'];
        }

        $params = [
            'api_id'  => $api_id,
            'aff_id'  => $aff_id,
            'site'    => $fp['site'],
            'service' => $fp['service'],
            'floor'   => $fp['floor'],
            'sort'    => fap_slot_opt($slot, 'sort', 'rank'),
            'keyword' => fap_slot_opt($slot, 'keyword', ''),
        ];
        $items = $this->api->search($params, 1);
        if (empty($items)) {
            return ['ok' => false, 'msg' => 'プレビュー対象の作品が取得できませんでした。'];
        }

        $d = $this->assemble_data($items[0], $slot, $fp['service'], $fp['floor']);
        if ($d === null) {
            return ['ok' => false, 'msg' => '作品データの組み立てに失敗しました。'];
        }

        $title_tpl   = $this->pick_title_tpl($slot);
        $excerpt_tpl = fap_slot_opt($slot, 'excerpt_tpl', '');
        $template    = $this->get_template($slot, array_column($d['genre_arr'], 'name'));

        $post_title   = $this->render_title($title_tpl, $d);
        $post_excerpt = $excerpt_tpl ? trim($this->replace_text($excerpt_tpl, $d)) : '';
        $post_content = $this->replace_all($template ?: $this->default_content($d), $d);

        // [related-new] はプレビューでは静的な見本に置換
        $post_content = str_replace('[related-new]', '<p><em>（新着レビュー一覧：投稿後に自動挿入されます）</em></p>', $post_content);

        // セール系ショートコードはプレビューでは実行されないため、静的な値に置換して見せる
        $sale = $d['sale_price'] ?? '';
        $post_content = str_replace(
            ['[fap_sale_price]', '[fap_discount_rate]'],
            [esc_html($sale), esc_html(fap_discount_rate($d['price'], $sale))],
            $post_content
        );
        if ($sale !== '') {
            $post_content = str_replace(['[fap_if_sale]', '[/fap_if_sale]'], '', $post_content);
            $post_content = preg_replace('/\[fap_if_not_sale\].*?\[\/fap_if_not_sale\]/s', '', $post_content);
        } else {
            $post_content = str_replace(['[fap_if_not_sale]', '[/fap_if_not_sale]'], '', $post_content);
            $post_content = preg_replace('/\[fap_if_sale\].*?\[\/fap_if_sale\]/s', '', $post_content);
        }

        // レビュー有無の条件ブロックもプレビューでは静的に解決する
        if ((int) ($d['review_cnt'] ?? 0) > 0) {
            $post_content = str_replace(['[fap_if_review]', '[/fap_if_review]'], '', $post_content);
            $post_content = preg_replace('/\[fap_if_no_review\].*?\[\/fap_if_no_review\]/s', '', $post_content);
        } else {
            $post_content = str_replace(['[fap_if_no_review]', '[/fap_if_no_review]'], '', $post_content);
            $post_content = preg_replace('/\[fap_if_review\].*?\[\/fap_if_review\]/s', '', $post_content);
        }

        return [
            'ok'      => true,
            'title'   => $post_title,
            'excerpt' => $post_excerpt,
            'content' => $post_content,
            'cid'     => $d['cid'],
        ];
    }

    // -----------------------------------------------------------------------
    // 1件投稿処理
    // -----------------------------------------------------------------------
    private function post_item(object $item, int $slot, string $service, string $floor = ''): bool {
        $cid      = (string) ($item->content_id ?? '');
        $title    = (string) ($item->title       ?? '');
        $aff_url  = (string) ($item->affiliateURL ?? '');
        $item_url = (string) ($item->URL          ?? '');
        $volume   = (string) ($item->volume       ?? '');
        $price    = '';
        $aff_id   = fap_secret('FAP_FANZA_AFF_ID', 'aff_id');

        if (!$cid || !$title) return false;

        // ブラックリスト・除外条件チェック
        $blacklist = fap_slot_opt($slot, 'blacklist', '');
        if ($blacklist && $this->is_blacklisted($title, $blacklist)) return false;
        if (FAP_License::is('pro') && $this->is_excluded_pro($item, $slot)) return false;

        // 重複投稿スキップ（同一CIDの既存投稿があれば作成しない）
        if (fap_opt('feat_dedup', '1') === '1' && $this->cid_exists($cid)) {
            fap_log('[Poster] 重複スキップ cid=' . $cid);
            return false;
        }

        // 投稿に必要なデータを組み立て（プレビュー機能と共用）
        $this->ai_desc_enabled  = fap_opt('ai_desc_on', '0') === '1';
        $this->ai_desc_override = null;
        $this->ai_desc_result   = '';
        $this->ai_desc_src      = '';
        try {
            $d = $this->assemble_data($item, $slot, $service, $floor);
        } finally {
            $this->ai_desc_enabled = false; // 以降の再描画・プレビューでAIを呼ばない
        }
        if ($d === null) return false;
        $cid           = $d['cid'];
        $title         = $d['title'];
        $aff_url       = $d['aff_url'];
        $actress_arr   = $d['actress_arr'];
        $performer_arr = $d['performer_arr'];
        $maker_arr     = $d['maker_arr'];
        $label_arr     = $d['label_arr'];
        $director_arr  = $d['director_arr'];
        $series_arr    = $d['series_arr'];
        $author_arr    = $d['author_arr'];
        $genre_arr     = $d['genre_arr'];
        $volume        = $d['volume'];
        $release_date  = $d['release_date'];
        $price         = $d['price'];
        $pkg_img       = $d['pkg_img'];
        $smp_imgs      = $d['smp_imgs'];
        $review_avg    = $d['review_avg'];
        $review_cnt    = $d['review_cnt'];

        // テンプレート取得（描画用）
        $title_tpl   = $this->pick_title_tpl($slot);
        $excerpt_tpl = fap_slot_opt($slot, 'excerpt_tpl', '');
        $template    = $this->get_template($slot, array_column($d['genre_arr'], 'name'));

        // タイトル・抜粋・本文の生成
        $post_title   = $this->render_title($title_tpl, $d);
        $post_excerpt = $excerpt_tpl ? trim($this->replace_text($excerpt_tpl, $d)) : '';
        $post_content = $this->replace_all($template ?: $this->default_content($d), $d);

        // 投稿日時
        $post_date_gmt = $this->resolve_date($slot);

        // 公開ステータス
        $status = fap_slot_opt($slot, 'post_status', 'publish');

        // 投稿データ
        kses_remove_filters();
        try {
            $post_arr = [
                'post_title'   => wp_strip_all_tags($post_title),
                'post_content' => $post_content,
                'post_excerpt' => wp_strip_all_tags($post_excerpt),
                'post_status'  => $status,
                'post_name'    => sanitize_title($cid),
                'post_type'    => 'post',
            ];
            if ($post_date_gmt) {
                $post_arr['post_date_gmt'] = $post_date_gmt;
                $post_arr['post_date']     = get_date_from_gmt($post_date_gmt);
                if ($status === 'publish' && strtotime($post_date_gmt) > time()) {
                    $post_arr['post_status'] = 'future';
                }
            }
            $post_id = wp_insert_post($post_arr);
        } finally {
            kses_init_filters();
        }

        if (!$post_id || is_wp_error($post_id)) return false;

        // [related-new]：投稿後に最新記事リストを生成して差し込む（現post_idを除外）
        if (str_contains($post_content, '[related-new]')) {
            $new_related = $this->build_related_new_posts($post_id, 5);
            kses_remove_filters();
            try {
                $update_result = wp_update_post([
                    'ID'           => $post_id,
                    'post_content' => str_replace('[related-new]', $new_related, $post_content),
                ]);
            } finally {
                kses_init_filters();
            }
            if (!$update_result || is_wp_error($update_result)) {
                fap_log('[related-new] 差し込み失敗 post_id=' . $post_id);
            }
        }

        // カテゴリ・タグ
        $cat_ids = $this->resolve_categories($slot, $d);
        $tag_ids = $this->resolve_tags($slot, $d);
        // セール中の作品には「セール中」カテゴリを自動付与（PREMIUM・設定ON時）
        if (($d['sale_price'] ?? '') !== '' && $this->sale_category_id() > 0) {
            $cat_ids[] = $this->sale_category_id();
            $cat_ids   = array_unique($cat_ids);
        } elseif (($d['sale_price'] ?? '') === '' && $this->nonsale_category_id() > 0) {
            // セール中でない作品には「セール中以外」カテゴリを自動付与（設定ON時）
            $cat_ids[] = $this->nonsale_category_id();
            $cat_ids   = array_unique($cat_ids);
        }
        if ($cat_ids) wp_set_post_categories($post_id, $cat_ids);
        if ($tag_ids) {
            // hide_empty=false が必須: 新規作成直後のタグは投稿数0で、既定(true)だと取得から除外され紐づかない
            $tag_names = get_terms(['taxonomy' => 'post_tag', 'include' => $tag_ids, 'fields' => 'names', 'hide_empty' => false]);
            if (!is_wp_error($tag_names)) wp_set_post_tags($post_id, $tag_names);
        }

        // アイキャッチ画像
        $eye_setting  = fap_slot_opt($slot, 'eyecatch', 'package');
        $thumb_url    = $this->resolve_eyecatch($eye_setting, $pkg_img, $smp_imgs);
        $thumb_alt    = $this->thumb_alt($title, $actress_arr);
        if ($thumb_url) {
            $attach_id = $this->download_image($thumb_url, $post_id, $thumb_alt);
            if ($attach_id) set_post_thumbnail($post_id, $attach_id);
        }

        // ポストメタ（SEO用）
        $all_images = array_filter(array_merge([$pkg_img], $smp_imgs));
        $meta = [
            '_fap_cid'           => $cid,
            '_fap_title'         => $title,
            '_fap_title_tpl'     => $title_tpl, // セール検知時に同じテンプレートで描き直すため
            // 同上（[random1]〜[random3]の選択結果）。update_post_meta は値の「\」を取り除くため、wp_slash が必須
            '_fap_randoms'       => wp_slash(wp_json_encode($d['randoms'] ?? [], JSON_UNESCAPED_UNICODE)),
            '_fap_ai_desc'       => $this->ai_desc_result, // AIでリライトした作品説明文（再描画で使い回す。空=AI未使用）
            '_fap_ai_desc_src'   => $this->ai_desc_src,    // リライト前の作品説明文（差分表示用）
            '_fap_actress'       => implode(', ', array_column($actress_arr,   'name')),
            '_fap_performer'     => implode(', ', array_column($performer_arr, 'name')),
            '_fap_maker'         => implode(', ', array_column($maker_arr,     'name')),
            '_fap_label'         => implode(', ', array_column($label_arr,     'name')),
            '_fap_director'      => implode(', ', array_column($director_arr,  'name')),
            '_fap_series'        => implode(', ', array_column($series_arr,    'name')),
            '_fap_author'        => implode(', ', array_column($author_arr,    'name')),
            '_fap_genre'         => implode(', ', array_column($genre_arr,     'name')),
            '_fap_volume'        => $volume,
            '_fap_release_date'  => $release_date,
            '_fap_has_movie'     => !empty($d['has_movie']) ? '1' : '',
            '_fap_movie_url'     => $d['movie_url'] ?? '',
            '_fap_price'         => $price,
            '_fap_sale_price'    => $d['sale_price'] ?? '',
            '_fap_affiliate_url' => $aff_url,
            '_fap_package_image' => $pkg_img,
            '_fap_images'        => wp_json_encode(array_values($all_images)),
            '_fap_review_avg'    => $review_avg,
            '_fap_review_cnt'    => $review_cnt,
            '_fap_slot'          => $slot,
            '_fap_service'       => $service,
            '_fap_floor'         => fap_slot_opt($slot, 'floor', ''),
        ];
        // メタディスクリプション（PRO+）
        if (FAP_License::is('pro')) {
            $meta_desc_tpl = fap_slot_opt($slot, 'meta_desc_tpl', '');
            if ($meta_desc_tpl) {
                $meta['_fap_meta_desc'] = mb_strimwidth(trim($this->replace_text($meta_desc_tpl, $d)), 0, 160, '…');
            }
        }
        foreach ($meta as $key => $val) {
            update_post_meta($post_id, $key, $val);
        }

        // 投稿時点でセール中なら履歴の起点として記録（初回観測）
        if (($d['sale_price'] ?? '') !== '') {
            FAP_Sale_History::log($cid, $post_id, 'start', (int) ($d['price'] ?? 0), (int) $d['sale_price']);
        }

        // 新規投稿時のAIリライト（PREMIUM・設定ON時）
        $this->maybe_ai_rewrite_new($post_id);

        return true;
    }

    // -----------------------------------------------------------------------
    // 新規投稿時のAIリライト（PREMIUM）
    // 記事の作成完了後に実行する。失敗してもテンプレートで生成した記事はそのまま残る。
    // リライト前のタイトル・本文は _fap_pre_ai_title / _fap_pre_ai_content に保存する。
    // （_fap_original_* はセール終了時の復元用スナップショットなのでここでは触らない）
    // -----------------------------------------------------------------------
    private function maybe_ai_rewrite_new(int $post_id): void {
        if (fap_opt('ai_auto_new', '0') !== '1') return;
        if (!FAP_License::is('premium')) return;

        $post = get_post($post_id);
        if (!$post) {
            fap_log('[AI] 新規リライト：投稿が見つかりません post_id=' . $post_id);
            return;
        }

        $ai_result = FAP_AI::rewrite($post->post_title, $post->post_content);
        if (!$ai_result['ok']) {
            fap_log('[AI] 新規リライト失敗 post_id=' . $post_id . ' msg=' . $ai_result['msg']);
            return;
        }
        if (!$ai_result['title'] && !$ai_result['content']) {
            fap_log('[AI] 新規リライト：応答が空でした post_id=' . $post_id);
            return;
        }

        // リライト前の内容を復元用に保存
        update_post_meta($post_id, '_fap_pre_ai_title',   $post->post_title);
        update_post_meta($post_id, '_fap_pre_ai_content', $post->post_content);

        $update = ['ID' => $post_id];
        if ($ai_result['title'])   $update['post_title']   = wp_strip_all_tags($ai_result['title']);
        if ($ai_result['content']) $update['post_content'] = $ai_result['content'];

        kses_remove_filters();
        try {
            $result = wp_update_post($update, true);
        } finally {
            kses_init_filters();
        }

        if (!$result || is_wp_error($result)) {
            fap_log('[AI] 新規リライトの保存失敗 post_id=' . $post_id);
            return;
        }

        fap_log('[AI] 新規リライト成功 post_id=' . $post_id);
    }

    // -----------------------------------------------------------------------
    // テンプレート取得（ジャンル別テンプレート対応・PREMIUM）
    // -----------------------------------------------------------------------
    private function get_template(int $slot, array $genre_names = []): string {
        // ジャンル別テンプレートを優先チェック（PREMIUM）
        if (FAP_License::is('premium') && !empty($genre_names)) {
            $genre_tpls = (array) fap_slot_opt($slot, 'genre_tpls', []);
            foreach ($genre_tpls as $entry) {
                $g = trim((string) ($entry['genre'] ?? ''));
                $t = trim((string) ($entry['tpl']   ?? ''));
                if (!$g || !$t) continue;
                if (in_array($g, $genre_names, true)) {
                    $tpl = $this->get_template_by_name($t);
                    if ($tpl !== '') return $tpl;
                }
            }
        }
        $default_name = 'FAP テンプレート' . ($slot === 1 ? '' : $slot);
        return $this->get_template_by_name($default_name);
    }

    private function get_template_by_name(string $name): string {
        global $wpdb;
        return (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_content FROM {$wpdb->posts}
                 WHERE post_title = %s AND post_type = 'post' AND post_status = 'draft'
                 ORDER BY post_date DESC LIMIT 1",
                $name
            )
        );
    }

    // -----------------------------------------------------------------------
    // デフォルト本文（テンプレートなし時）
    // -----------------------------------------------------------------------
    private function default_content(array $d): string {
        return '[package]' . "\n\n" . '[detail-table]' . "\n\n" . '[aff-button]';
    }

    // -----------------------------------------------------------------------
    // テキスト変数置換（タイトル・抜粋用）
    // -----------------------------------------------------------------------
    private function replace_text(string $tpl, array $d): string {
        // タイトル・抜粋はここで描画するため、セール条件タグも静的に解決する
        $tpl = $this->resolve_sale_conditionals($tpl, (string) ($d['sale_price'] ?? ''));

        // タイトル・抜粋用：[comment] は HTML を除去してテキストにする
        $map = $this->text_map($d);
        $map['[comment]']       = wp_strip_all_tags($d['comment']);
        $map['[comment-short]'] = wp_strip_all_tags($d['comment_short']);
        return strtr($tpl, $map);
    }

    // [fap_if_sale]〜[/fap_if_sale] / [fap_if_not_sale]〜[/fap_if_not_sale] を静的に解決する
    // （ショートコードが効かないタイトル・抜粋用。描画した時点のセール状態で確定する）
    private function resolve_sale_conditionals(string $tpl, string $sale_price): string {
        if ($sale_price !== '') {
            $tpl = str_replace(['[fap_if_sale]', '[/fap_if_sale]'], '', $tpl);
            $tpl = preg_replace('/\[fap_if_not_sale\].*?\[\/fap_if_not_sale\]/s', '', $tpl);
        } else {
            $tpl = str_replace(['[fap_if_not_sale]', '[/fap_if_not_sale]'], '', $tpl);
            $tpl = preg_replace('/\[fap_if_sale\].*?\[\/fap_if_sale\]/s', '', $tpl);
        }
        return $tpl;
    }

    // -----------------------------------------------------------------------
    // タイトル専用タグ [sale_title_1]〜[sale_title_5]（PREMIUM）
    // セール中だけ、設定「セール中タイトル1〜5」の文言に置換される。セール中でなければ空。
    // 文言の中では [discount-rate] [sale-price] [price] などの変数が使える。
    // タイトル以外（本文・抜粋など）では展開しない。
    // -----------------------------------------------------------------------
    private function expand_sale_title_tags(string $tpl, array $d): string {
        if (strpos($tpl, '[sale_title_') === false) return $tpl;

        $on_sale = FAP_License::is('premium') && (string) ($d['sale_price'] ?? '') !== '';
        $map = [];
        foreach ([1, 2, 3, 4, 5] as $n) {
            $default = $n === 1 ? '【セール中】' : '';
            // 文言の中の変数（[discount-rate] など）は、ここで展開済みの状態にして返す
            $text = $on_sale ? trim((string) fap_opt('sale_title_' . $n, $default)) : '';
            $map['[sale_title_' . $n . ']'] = $text !== '' ? $this->replace_text($text, $d) : '';
        }
        return strtr($tpl, $map);
    }

    // タイトルテンプレートを描画する（投稿時・プレビュー・セール検知の再描画で共通）
    private function render_title(string $tpl, array $d): string {
        $title = $this->replace_text($this->expand_sale_title_tags($tpl, $d), $d);
        // [random1]〜[random3] の候補行に書かれた [sale_title_N] は、上の置換で初めて現れるので、もう一度展開する
        $title = $this->expand_sale_title_tags($title, $d);

        // 割引率が取れず空になった場合の「【】」を除去
        $title = preg_replace('/【\s*】/u', '', $title);
        return trim($title);
    }

    // 既存投稿のタイトルを、投稿時と同じタイトルテンプレートで「今の状態」に描画し直す。
    // 投稿時に使ったテンプレートは _fap_title_tpl に保存してある（無ければスロットの「投稿タイトル」）。
    private function render_title_for_post(int $post_id, object $item): string {
        $slot      = (int)    get_post_meta($post_id, '_fap_slot',    true);
        $service   = (string) get_post_meta($post_id, '_fap_service', true);
        $floor_key = (string) get_post_meta($post_id, '_fap_floor',   true);
        if ($slot < 1) return '';

        $fp = FAP_API::parse_floor_key($floor_key);
        $this->ai_desc_override = (string) get_post_meta($post_id, '_fap_ai_desc', true); // AIは再実行しない
        try {
            $d  = $this->assemble_data($item, $slot, $service ?: $fp['service'], $fp['floor']);
        } finally {
            $this->ai_desc_override = null;
        }
        if ($d === null) return '';

        // [random1]〜[random3] は投稿時に選ばれた行を使い回す（描き直しのたびにタイトルが変わらないように）
        $saved_randoms = json_decode((string) get_post_meta($post_id, '_fap_randoms', true), true);
        if (is_array($saved_randoms) && $saved_randoms) {
            foreach ($saved_randoms as $k => $v) {
                if (is_string($v)) $saved_randoms[$k] = self::restore_stripped_unicode($v);
            }
            $d['randoms'] = $saved_randoms;
        }

        $tpl = (string) get_post_meta($post_id, '_fap_title_tpl', true);
        if ($tpl === '') {
            $tpl = fap_slot_opt($slot, 'title_tpl', '[title]') ?: '[title]';
        }
        return wp_strip_all_tags($this->render_title($tpl, $d));
    }

    // 「\u306e\u30bb…」の「\」が取り除かれて「u306eu30bb…」になった文字列を、元の文字へ戻す
    public static function restore_stripped_unicode(string $s): string {
        return preg_replace_callback('/(?:u[0-9a-fA-F]{4})+/', static function (array $m): string {
            $bin = hex2bin(str_replace('u', '', $m[0]));
            if ($bin === false) return $m[0];
            $out = mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
            return $out !== '' ? $out : $m[0];
        }, $s);
    }

    // 化けて保存されてしまった「ランダム文のメタ」と「記事タイトル」を修復する（1回だけ実行）
    public static function repair_garbled_unicode(): array {
        global $wpdb;
        $fixed_meta   = 0;
        $fixed_titles = 0;

        $rows = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_fap_randoms' AND meta_value REGEXP 'u[0-9a-fA-F]{4}'");
        foreach ((array) $rows as $r) {
            $arr = json_decode((string) $r->meta_value, true);
            if (!is_array($arr)) continue;
            foreach ($arr as $k => $v) {
                if (is_string($v)) $arr[$k] = self::restore_stripped_unicode($v);
            }
            update_post_meta((int) $r->post_id, '_fap_randoms', wp_slash(wp_json_encode($arr, JSON_UNESCAPED_UNICODE)));
            $fixed_meta++;
        }

        // 連続する2文字以上が化けているタイトルだけを対象にする（誤検出を避けるため）
        $posts = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'post' AND post_title REGEXP 'u[0-9a-fA-F]{4}u[0-9a-fA-F]{4}'");
        foreach ((array) $posts as $row) {
            $new = self::restore_stripped_unicode((string) $row->post_title);
            if ($new !== '' && $new !== $row->post_title) {
                $wpdb->update($wpdb->posts, ['post_title' => $new], ['ID' => (int) $row->ID]);
                clean_post_cache((int) $row->ID);
                $fixed_titles++;
            }
        }

        return ['meta' => $fixed_meta, 'titles' => $fixed_titles];
    }

    // タイトルだけを描画し直して保存する（セール中の値下げ・セール終了後の掃除用）
    private function refresh_title(int $post_id, object $item): void {
        if (fap_opt('ai_auto_sale', '0') === '1') return; // AIがタイトルを管理している場合は触らない
        $new = $this->render_title_for_post($post_id, $item);
        if ($new === '' || $new === (string) get_post_field('post_title', $post_id, 'raw')) return;
        wp_update_post(['ID' => $post_id, 'post_title' => $new]);
    }

    private function text_map(array $d): array {
        // 本文テンプレートと共通のテキスト系タグ（[comment] は除く）
        return [
            '[title]'          => $d['title'],
            '[title-short]'    => mb_strimwidth(wp_strip_all_tags($d['title']), 0, 42, '…'),
            '[cid]'            => $d['cid'],
            '[actress]'        => implode('・', array_column($d['actress_arr'],  'name')),
            '[maker]'          => implode('・', array_column($d['maker_arr'],    'name')),
            '[label]'          => implode('・', array_column($d['label_arr'],    'name')),
            '[director]'       => implode('・', array_column($d['director_arr'], 'name')),
            '[series]'         => implode('・', array_column($d['series_arr'],   'name')),
            '[author]'         => implode('・', array_column($d['author_arr'],   'name')),
            '[genre]'          => implode(' ',  array_column($d['genre_arr'],    'name')),
            '[volume]'         => $d['volume'],
            '[date]'           => $d['release_date'],
            '[price]'          => $d['price'],
            '[sale-price]'     => $d['sale_price'] ?? '',
            '[discount-rate]'  => fap_discount_rate($d['price'], $d['sale_price'] ?? ''),
            '[review-count]'   => $d['review_cnt'],
            '[review-average]' => $d['review_avg'],
            // 生URL（href 属性などに直接埋め込む用。HTMLは含まない）
            '[aff-url]'        => esc_url($d['aff_url']),
            '[tachiyomi-url]'  => esc_url($d['tachiyomi_url'] ?? ''),
            '[item-url]'       => esc_url($d['item_url']),
            '[random1]'        => $d['randoms'][1] ?? '',
            '[random2]'        => $d['randoms'][2] ?? '',
            '[random3]'        => $d['randoms'][3] ?? '',
        ];
    }

    // -----------------------------------------------------------------------
    // 全変数置換（本文テンプレート用）
    // -----------------------------------------------------------------------
    public function replace_all(string $tpl, array $d): string {
        $slot = $d['slot'];

        $btn1_text   = fap_slot_opt($slot, 'btn1_text',  'この作品の価格を確認') ?: 'この作品の価格を確認';
        $btn1_bg     = fap_slot_opt($slot, 'btn1_bg',    '#ff0000') ?: '#ff0000';
        $btn1_color  = fap_slot_opt($slot, 'btn1_color', '#ffffff') ?: '#ffffff';
        $btn1_border = fap_slot_opt($slot, 'btn1_border', '');
        $btn1_size   = fap_slot_opt($slot, 'btn1_size',   'medium') ?: 'medium';
        $btn1_radius = fap_slot_opt($slot, 'btn1_radius', 'medium') ?: 'medium';
        $btn2_text   = fap_slot_opt($slot, 'btn2_text',  '続きの画像はこちら') ?: '続きの画像はこちら';
        $btn2_bg     = fap_slot_opt($slot, 'btn2_bg',    '#0000ff') ?: '#0000ff';
        $btn2_color  = fap_slot_opt($slot, 'btn2_color', '#ffffff') ?: '#ffffff';
        $btn2_border = fap_slot_opt($slot, 'btn2_border', '');
        $btn2_size   = fap_slot_opt($slot, 'btn2_size',   'medium') ?: 'medium';
        $btn2_radius = fap_slot_opt($slot, 'btn2_radius', 'medium') ?: 'medium';
        $maximage   = (int) fap_slot_opt($slot, 'maximage', 0);
        $movie_size = fap_slot_opt($slot, 'movie_size', '560') ?: '560';
        $poster_set = fap_slot_opt($slot, 'poster', 'package') ?: 'package';

        $maker_str  = implode('・', array_column($d['maker_arr'], 'name'));

        // アフィリエイトURLリンク（テキスト表示）
        // href はアフィリエイトURL、表示テキストは作品ページURL（al.dmm…を見せない）
        $display_url = $d['item_url'] ?: $d['aff_url'];
        $aff_link_html  = $d['aff_url']
            ? '<p><a href="' . esc_url($d['aff_url']) . '" target="_blank" rel="nofollow sponsored noopener noreferrer" style="word-break:break-all;">' . esc_html($display_url) . '</a></p>'
            : '';
        // 立ち読みURLリンク（電子書籍のみ存在）
        $tachiyomi_url = $d['tachiyomi_url'] ?? '';
        $tachiyomi_link_html = $tachiyomi_url
            ? '<p><a href="' . esc_url($tachiyomi_url) . '" target="_blank" rel="nofollow sponsored noopener noreferrer" style="word-break:break-all;">' . esc_html($tachiyomi_url) . '</a></p>'
            : '';

        // カード型リンク（パッケージ画像＋作品名）：[aff-card] / [tachiyomi-card]
        $aff_card_html       = $this->build_link_card($d['aff_url'], $d['title'], $d['pkg_img']);
        $tachiyomi_card_html = $this->build_link_card($tachiyomi_url, $d['title'], $d['pkg_img']);

        $first_genre = !empty($d['genre_arr']) ? $d['genre_arr'][0]['name'] : '';

        // サンプル画像 SEO テキスト（元プラグインと同仕様）
        $sample_intro = '<p>ここでは、公式で公開されている<strong>『' . esc_html($d['title']) . '』</strong>の無料サンプル画像を掲載しています。'
                      . ($maker_str ? '<strong>' . esc_html($maker_str) . '</strong>の絵柄・キャラクター・作品の雰囲気を購入前にチェックしてみてください。' : '作品の雰囲気を購入前にチェックしてみてください。')
                      . '</p>';
        $sample_outro = '<p>サンプル画像で<strong>『' . esc_html($d['title']) . '』</strong>の雰囲気が合いそうだと感じた方は、FANZA公式ページで続きやレビュー評価も確認してみてください。</p>';

        // テキスト系を先に置換
        // セール系は本文ではショートコードに展開し、表示時点のセール状態を反映させる
        // （セール終了後も記事を編集せずに価格表示が自動で消えるようにするため）
        $out = strtr($tpl, [
            '[sale-price]'    => '[fap_sale_price]',
            '[discount-rate]' => '[fap_discount_rate]',
            '[sale-history]'  => '[fap_sale_history]',
        ] + $this->text_map($d) + [
            '[comment]'        => $d['comment'],
            '[comment-short]'  => $d['comment_short'],
            '[review]'         => $d['review_html'],
            '[review2]'        => $d['review_html2'],
            '[aff-link]'       => $aff_link_html,
            '[tachiyomi-link]' => $tachiyomi_link_html,
            '[aff-card]'       => $aff_card_html,
            '[tachiyomi-card]' => $tachiyomi_card_html,
            '[aff-button]'     => $this->build_btn($d['aff_url'], $btn1_text, $btn1_bg, $btn1_color, $btn1_border, $btn1_size, $btn1_radius),
            '[aff-button2]'    => $this->build_btn($d['aff_url'], $btn2_text, $btn2_bg, $btn2_color, $btn2_border, $btn2_size, $btn2_radius),
            '[tachiyomi]'      => $tachiyomi_url
                                    ? $this->build_btn($tachiyomi_url, '立ち読みはこちら', $btn1_bg, $btn1_color, $btn1_border, $btn1_size, $btn1_radius)
                                    : '',
            '[package]'        => $this->build_pkg_img($d['pkg_img'], $d['title'], $d['aff_url'], $maker_str, $d['cid']),
            '[detail-list]'    => $this->build_detail($d, 'list'),
            '[detail-table]'   => $this->build_detail($d, 'table'),
            '[act-info]'       => $this->build_act_info($d['actress_arr'], 'list'),
            '[act-table]'      => $this->build_act_info($d['actress_arr'], 'table'),
            '[sample-intro]'   => $sample_intro,
            '[sample-outro]'   => $sample_outro,
            '[related-maker]'  => $this->build_related_archive_link('同じメーカーの作品', $maker_str, 'category'),
            '[related-genre]'  => $this->build_related_archive_link('同じジャンルの作品', $first_genre, 'post_tag'),
        ]);

        // 画像系
        $imgs       = $this->pick_images($d, $maximage);
        $small_imgs = $d['smp_imgs_small'] ?? [];
        if ($maximage > 0) $small_imgs = array_slice($small_imgs, 0, $maximage);

        $out = str_replace('[sample-cap]',         $this->build_imgs($imgs, $small_imgs, 'cap',    $d['title'], $d['aff_url'], $maker_str, $d['cid']), $out);
        $out = str_replace('[sample-photo]',       $this->build_imgs($imgs, $small_imgs, 'photo',  $d['title'], $d['aff_url'], $maker_str, $d['cid']), $out);
        $out = str_replace('[sample-flex]',        $this->build_imgs($imgs, $small_imgs, 'flex',   $d['title'], $d['aff_url'], $maker_str, $d['cid']), $out);
        $out = str_replace('[sample-nolink]',      $this->build_imgs($imgs, $small_imgs, 'nolink', $d['title'], $d['aff_url'], $maker_str, $d['cid']), $out);
        $out = str_replace('[sample-no-cap-link]', $this->build_imgs($imgs, $small_imgs, 'nolink', $d['title'], $d['aff_url'], $maker_str, $d['cid']), $out);

        // 動画
        $poster_url = ($poster_set === 'package') ? $d['pkg_img'] : ($d['smp_imgs'][0] ?? $d['pkg_img']);
        $out = str_replace('[sample-movie]',  $this->build_movie($d['cid'], $d['aff_id'], $d['has_movie'], $movie_size, 'small', $poster_url, ''), $out);
        $out = str_replace('[sample-movie2]', $this->build_movie($d['cid'], $d['aff_id'], $d['has_movie'], $movie_size, 'large', $poster_url, $d['movie_url'] ?? ''), $out);

        return $out;
    }

    // -----------------------------------------------------------------------
    // HTML ビルダー
    // -----------------------------------------------------------------------
    private function build_btn(string $url, string $text, string $bg, string $color, string $border = '', string $size = 'medium', string $radius = 'medium'): string {
        // サイズ・角丸プリセット
        $sizes = [
            'small'  => 'padding:10px 20px;font-size:14px;',
            'medium' => 'padding:16px 24px;font-size:16px;',
            'large'  => 'padding:20px 36px;font-size:18px;',
        ];
        $radii = [
            'none'   => '0',
            'small'  => '6px',
            'medium' => '10px',
            'large'  => '18px',
            'pill'   => '999px',
        ];
        $size_css   = $sizes[$size]   ?? $sizes['medium'];
        $radius_css = $radii[$radius] ?? $radii['medium'];
        $border_css = $border ? 'border:2px solid ' . esc_attr($border) . ';' : 'border:none;';

        // スマホでタップしやすいよう、中央寄せのブロックボタン（最大420px）に
        $st = 'display:block;max-width:420px;margin:0 auto;' . $size_css
            . 'background:' . esc_attr($bg) . ';color:' . esc_attr($color) . ';'
            . 'text-decoration:none;border-radius:' . $radius_css . ';font-weight:bold;line-height:1.4;'
            . 'text-align:center;box-shadow:0 4px 12px rgba(0,0,0,.12);box-sizing:border-box;' . $border_css;
        return sprintf('<p style="margin:18px 0;"><a class="fap-btn" href="%s" target="_blank" rel="nofollow noopener" style="%s">%s</a></p>',
            esc_url($url), $st, esc_html($text));
    }

    // パッケージ画像＋作品名のリンクカード（[aff-link] / [tachiyomi-link] 用）
    // 公開ページ用のためテーマに依存しないインラインスタイルで枠付きカードにする
    private function build_link_card(string $url, string $title, string $img): string {
        if (!$url) return '';
        $rel  = 'nofollow sponsored noopener noreferrer';
        $eurl = esc_url($url);

        // 枠（カード）本体（スマホでも崩れないよう box-sizing と可変幅画像を使用）
        $card_style = 'display:flex;align-items:center;gap:14px;max-width:520px;margin:20px auto;'
                    . 'padding:14px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;'
                    . 'box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px rgba(16,24,40,.07);'
                    . 'text-decoration:none;color:inherit;box-sizing:border-box;';

        // 左：パッケージ画像（画面幅に応じて 96〜130px に伸縮）
        $img_html = '';
        if ($img) {
            $img_html = '<img src="' . esc_url($img) . '" alt="' . esc_attr('『' . $title . '』') . '"'
                      . ' loading="lazy" decoding="async"'
                      . ' style="flex:0 0 auto;width:clamp(96px,28vw,130px);height:auto;border-radius:10px;display:block;" />';
        }

        // 右：作品名＋CTA
        $title_html = '<span style="display:block;font-weight:700;font-size:15px;line-height:1.5;'
                    . 'color:#1f2937;margin-bottom:10px;">' . esc_html($title) . '</span>';
        $cta_html   = '<span style="display:inline-block;padding:8px 18px;border-radius:999px;'
                    . 'background:linear-gradient(120deg,#4f46e5,#7c3aed);color:#fff;font-weight:700;'
                    . 'font-size:13px;line-height:1.4;">詳しく見る →</span>';
        $body_html  = '<span style="flex:1 1 auto;min-width:0;">' . $title_html . $cta_html . '</span>';

        // カード全体を1つのリンクに（class はフロントCSSでのホバー/折返し制御用）
        return '<a class="fap-link-card" href="' . $eurl . '" target="_blank" rel="' . $rel . '" style="' . $card_style . '">'
             . $img_html . $body_html . '</a>';
    }

    private function build_pkg_img(string $img, string $title, string $url, string $maker = '', string $cid = ''): string {
        if (!$img) return '';
        $alt = '『' . $title . '』のパッケージ画像';
        if ($maker) $alt .= '｜' . $maker;
        if ($cid)   $alt .= '【' . $cid . '】';
        // パッケージ画像はファーストビュー（LCP候補）になりやすいため eager + 高優先で読み込む
        $tag = '<img src="' . esc_url($img) . '" alt="' . esc_attr($alt) . '" loading="eager" fetchpriority="high" decoding="async" style="max-width:100%;height:auto;" />';
        $inner = $url
            ? '<a href="' . esc_url($url) . '" target="_blank" rel="nofollow sponsored noopener noreferrer">' . $tag . '</a>'
            : $tag;
        return '<div class="wp-block-image"><figure class="aligncenter size-large">' . $inner . '</figure></div>';
    }

    private function pick_images(array $d, int $max): array {
        $imgs = !empty($d['smp_imgs']) ? $d['smp_imgs'] : $d['scrape_imgs'];
        return $max > 0 ? array_slice($imgs, 0, $max) : $imgs;
    }

    // サンプル画像 alt テキスト（SEO最適化）
    private function sample_alt(int $n, string $title, string $maker, string $cid): string {
        $alt = '『' . $title . '』の無料サンプル画像' . $n;
        if ($maker) $alt .= '｜' . $maker;
        if ($cid)   $alt .= '【' . $cid . '】';
        return $alt;
    }

    private function build_imgs(array $imgs, array $small_imgs, string $style, string $title, string $url, string $maker = '', string $cid = ''): string {
        if (empty($imgs)) return '';
        $out = '';

        if ($style === 'flex') {
            // 横並び2列レイアウト（大画像を2カラム表示）
            $out = '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
            foreach ($imgs as $i => $src) {
                $n   = $i + 1;
                $alt = esc_attr($this->sample_alt($n, $title, $maker, $cid));
                $out .= '<div style="flex:0 0 calc(50% - 4px);box-sizing:border-box;">'
                      . '<img src="' . esc_url($src) . '" alt="' . $alt . '" loading="lazy" decoding="async" style="width:100%;height:auto;" />'
                      . '</div>';
            }
            return $out . '</div>';
        }

        foreach ($imgs as $i => $src) {
            $n      = $i + 1;
            $alt    = esc_attr($this->sample_alt($n, $title, $maker, $cid));
            $img_tag = '<img src="' . esc_url($src) . '" alt="' . $alt . '" loading="lazy" decoding="async" style="max-width:100%;height:auto;" />';

            switch ($style) {
                case 'cap':
                    // キャプション付き（アフィリエイトリンク付き）
                    $out .= '<div class="wp-block-image aligncenter"><figure class="aligncenter">'
                          . '<a href="' . esc_url($url) . '" target="_blank" rel="nofollow noreferrer noopener">' . $img_tag . '</a>'
                          . '<figcaption>' . esc_html($title) . ' サンプル画像' . $n . '</figcaption>'
                          . '</figure></div>' . "\n";
                    break;
                case 'photo':
                    // キャプションなし（アフィリエイトリンク付き）
                    $out .= '<div class="wp-block-image aligncenter"><figure class="aligncenter">'
                          . '<a href="' . esc_url($url) . '" target="_blank" rel="nofollow noreferrer noopener">' . $img_tag . '</a>'
                          . '</figure></div>' . "\n";
                    break;
                case 'nolink':
                default:
                    // リンクなし・キャプションなし
                    $out .= '<div class="wp-block-image aligncenter"><figure class="aligncenter">'
                          . $img_tag
                          . '</figure></div>' . "\n";
                    break;
            }
        }
        return $out;
    }

    // [sample-movie]  = iframeでDMM公式プレーヤーを埋め込む（確実に再生可能）
    // [sample-movie2] = 直接MP4 URLを探してvideoタグで埋め込む
    private function build_movie(string $cid, string $aff_id, bool $has_movie, string $size, string $type, string $poster, string $movie_url = ''): string {
        if (!$has_movie || !$cid) return '';

        if ($type === 'small') {
            // DMM公式litevideoプレーヤーをiframeで埋め込む
            $size_map = ['476' => '476_306', '560' => '560_360', '644' => '644_414', '720' => '720_480'];
            $dim = $size_map[$size] ?? '560_360';
            [$w, $h] = explode('_', $dim);
            return '<div style="text-align:center;margin:12px 0;">'
                 . '<iframe width="' . (int)$w . '" height="' . (int)$h . '"'
                 . ' src="https://www.dmm.co.jp/litevideo/-/part/=/affi_id=' . esc_attr($aff_id) . '/cid=' . esc_attr($cid) . '/size=' . esc_attr($dim) . '/"'
                 . ' scrolling="no" frameborder="0" allowfullscreen loading="lazy"></iframe>'
                 . '</div>';
        }

        // 大サイズ：CC3001から直接MP4を探してvideoタグで埋め込む
        $mp4 = $this->get_mp4_url($cid, $movie_url);
        if (!$mp4) return '';
        $poster_attr = $poster ? ' poster="' . esc_url($poster) . '"' : '';
        return '<div style="max-width:720px;margin:12px auto;">'
             . '<video controls playsinline' . $poster_attr . ' style="width:100%;height:auto;">'
             . '<source src="' . esc_url($mp4) . '" type="video/mp4" />'
             . 'お使いのブラウザは動画再生に対応していません。'
             . '</video>'
             . '</div>';
    }

    // FANZAの無料サンプルMP4 URLを推定してHTTP HEAD で存在確認
    // movie_url（litevideo URL）からCIDを抽出して試す。content_idと異なる場合があるため両方試す
    private function get_mp4_url(string $cid, string $movie_url = ''): string {
        // sampleMovieURL から cid= パラメータを抽出（content_idと異なる場合がある）
        $url_cid = $cid;
        if ($movie_url && preg_match('/cid=([^\/]+)/i', $movie_url, $m)) {
            $url_cid = $m[1];
        }

        $cids_to_try = array_values(array_unique(array_filter([$url_cid, $cid])));

        foreach ($cids_to_try as $try_cid) {
            $first = mb_substr($try_cid, 0, 1);
            $three = mb_substr($try_cid, 0, 3);
            $base  = 'https://cc3001.dmm.co.jp/litevideo/freepv/' . $first . '/' . $three . '/' . $try_cid . '/';
            $files = [
                $try_cid . 'mhb.mp4',
                $try_cid . 'dmb.mp4',
                $try_cid . 'dm.mp4',
                $try_cid . 'sm.mp4',
                $try_cid . '_mhb_w.mp4',
                $try_cid . '_dmb_w.mp4',
                $try_cid . '_dm_w.mp4',
                $try_cid . '_sm_w.mp4',
            ];
            foreach ($files as $file) {
                $url  = $base . $file;
                $resp = wp_remote_head($url, ['timeout' => 5, 'sslverify' => true]);
                if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                    return $url;
                }
            }
        }
        return '';
    }

    private function build_detail(array $d, string $type): string {
        $rows = [];
        if ($d['actress_arr'])  $rows[] = ['女優',      implode('・', array_column($d['actress_arr'],   'name'))];
        if ($d['actor_arr'])    $rows[] = ['出演者',    implode('・', array_column($d['actor_arr'],     'name'))];
        if ($d['director_arr']) $rows[] = ['監督',      implode('・', array_column($d['director_arr'],  'name'))];
        if ($d['series_arr'])   $rows[] = ['シリーズ',  implode('・', array_column($d['series_arr'],    'name'))];
        if ($d['maker_arr'])    $rows[] = ['メーカー',  implode('・', array_column($d['maker_arr'],     'name'))];
        if ($d['label_arr'])    $rows[] = ['レーベル',  implode('・', array_column($d['label_arr'],     'name'))];
        if ($d['author_arr'])   $rows[] = ['作者',      implode('・', array_column($d['author_arr'],    'name'))];
        if ($d['genre_arr'])    $rows[] = ['ジャンル',  implode(' ',  array_column($d['genre_arr'],     'name'))];
        if ($d['release_date']) $rows[] = ['発売日',    $d['release_date']];
        if ($d['volume'])       $rows[] = ['収録時間',  $d['volume']];
        if ($d['price'])        $rows[] = ['価格',      $d['price']];

        if (empty($rows)) return '';

        if ($type === 'list') {
            $out = '<ul>';
            foreach ($rows as [$label, $val]) {
                $out .= '<li><strong>' . esc_html($label) . '：</strong>' . esc_html($val) . '</li>';
            }
            return $out . '</ul>';
        }

        $out = '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">'
             . '<table style="border-collapse:collapse;width:100%;font-size:14px;">';
        foreach ($rows as [$label, $val]) {
            $out .= '<tr><th style="border:1px solid #ddd;padding:8px 12px;background:#f7f7f7;white-space:nowrap;text-align:left;width:30%;">'
                  . esc_html($label)
                  . '</th><td style="border:1px solid #ddd;padding:8px 12px;word-break:break-word;">'
                  . esc_html($val)
                  . '</td></tr>';
        }
        return $out . '</table></div>';
    }

    private function build_act_info(array $actress_arr, string $type): string {
        if (empty($actress_arr)) return '';
        // 基本的な女優情報のリスト（名前のみ）
        if ($type === 'table') {
            $out = '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">'
                 . '<table style="border-collapse:collapse;width:100%;font-size:14px;">';
            foreach ($actress_arr as $act) {
                $out .= '<tr><th style="border:1px solid #ddd;padding:8px;background:#f7f7f7;width:30%;text-align:left;">女優</th>'
                      . '<td style="border:1px solid #ddd;padding:8px;word-break:break-word;">' . esc_html($act['name']) . '</td></tr>';
            }
            return $out . '</table></div>';
        }
        $out = '<ul>';
        foreach ($actress_arr as $act) {
            $out .= '<li>' . esc_html($act['name']) . '</li>';
        }
        return $out . '</ul>';
    }

    // -----------------------------------------------------------------------
    // レビュー HTML
    // -----------------------------------------------------------------------
    private function build_review(object $item): array {
        if (!isset($item->review)) return ['', '', '', ''];
        $avg = (float) ($item->review->average ?? 0);
        $cnt = (int)   ($item->review->count   ?? 0);
        if (!$avg && !$cnt) return ['', '', '', ''];

        // バケット方式でDMMの実在するGIF URLを決定
        if      ($avg > 4.75) $key = '5';
        elseif  ($avg > 4.25) $key = '4_5';
        elseif  ($avg > 3.75) $key = '4';
        elseif  ($avg > 3.25) $key = '3_5';
        elseif  ($avg > 2.75) $key = '3';
        elseif  ($avg > 2.25) $key = '2_5';
        elseif  ($avg > 1.75) $key = '2';
        elseif  ($avg > 1.25) $key = '1_5';
        elseif  ($avg > 0.75) $key = '1';
        else                  $key = '0';

        $label  = $avg > 0 ? number_format($avg, 1) : '-';
        $imgurl = 'https://p.dmm.co.jp/p/ms/review/' . $key . '.gif';
        $html   = '<img src="' . esc_url($imgurl) . '" alt="' . esc_attr('レビュー評価 ' . $label) . '" loading="lazy" decoding="async" />'
                . '&nbsp;<span style="font-size:80%;">' . esc_html($label) . '（' . esc_html((string)$cnt) . '件）</span>';

        // [review] は常に表示、[review2] は評価がある場合のみ
        return [$html, ($avg > 0 ? $html : ''), (string) $avg, (string) $cnt];
    }

    // -----------------------------------------------------------------------
    // 関連記事ボックス
    // -----------------------------------------------------------------------

    // [related-maker] / [related-genre] 用：カテゴリ/タグのアーカイブリンクボックス
    private function build_related_archive_link(string $label, string $name, string $taxonomy): string {
        $name = trim(wp_strip_all_tags($name));
        if ($name === '') return '';

        $term = get_term_by('name', $name, $taxonomy);
        if ($term && !is_wp_error($term)) {
            $url = get_term_link($term, $taxonomy);
        } else {
            $base = ($taxonomy === 'post_tag') ? '/tag/' : '/category/';
            $url  = home_url($base . sanitize_title($name) . '/');
        }
        if (is_wp_error($url)) return '';

        return '<div class="fap-related-box" style="border:1px solid #e0e0e0;padding:12px 16px;margin:16px 0;border-radius:4px;background:#fafafa;">'
             . '<strong style="display:block;margin-bottom:6px;font-size:14px;">' . esc_html($label) . '</strong>'
             . '<p style="margin:0;"><a href="' . esc_url($url) . '">' . esc_html($name) . 'の関連記事を見る →</a></p>'
             . '</div>';
    }

    // [related-new] 用：最新の公開投稿リスト（投稿後に差し込む）
    private function build_related_new_posts(int $exclude_id, int $limit = 5): string {
        $q = new WP_Query([
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => $limit,
            'post__not_in'        => $exclude_id ? [$exclude_id] : [],
            'ignore_sticky_posts' => true,
            'orderby'             => 'date',
            'order'               => 'DESC',
        ]);
        if (!$q->have_posts()) return '';

        $html = '<div class="fap-related-box" style="border:1px solid #e0e0e0;padding:12px 16px;margin:16px 0;border-radius:4px;background:#fafafa;">'
              . '<strong style="display:block;margin-bottom:8px;font-size:14px;">新着のFANZA作品レビュー</strong>'
              . '<ul style="margin:0;padding-left:1.5em;">';
        while ($q->have_posts()) {
            $q->the_post();
            $html .= '<li><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></li>';
        }
        wp_reset_postdata();
        return $html . '</ul></div>';
    }

    // -----------------------------------------------------------------------
    // スクレイピング
    // -----------------------------------------------------------------------
    // サービス・フロア別に適切なセレクタで説明文の本文テキストを抽出（元プラグインと同仕様）
    // 動画系（video.dmm.co.jp）はJS描画でmeta descriptionが無いため、
    // 呼び出し側でGraphQL APIを先に試し、ここはフォールバック用。
    private function extract_comment_text(string $html, string $service, string $floor = ''): string {
        if (!$html) return '';

        $raw = '';

        if ($service === 'mono') {
            // 物理商品：<p class="mg-b20">
            if (str_contains($html, '<p class="mg-b20">')) {
                $tmp = explode('<p class="mg-b20">', $html);
                $raw = isset($tmp[1]) ? explode('</p>', $tmp[1])[0] : '';
            }
        } elseif ($floor === 'digital_pcgame') {
            // PCゲーム：<p class="text-overflow">
            if (str_contains($html, '<p class="text-overflow">')) {
                $tmp = explode('<p class="text-overflow">', $html);
                $raw = isset($tmp[1]) ? explode('</p>', $tmp[1])[0] : '';
            }
        } elseif ($service === 'ebook') {
            // 電子書籍：ページ内JSON-LDの "description":"..."
            if (str_contains($html, '"description":"')) {
                $tmp = explode('"description":"', $html);
                $raw = isset($tmp[1]) ? (strstr($tmp[1], '","', true) ?: '') : '';
            }
        } elseif ($service === 'doujin') {
            // 同人：<p class="summary__txt">
            if (str_contains($html, '<p class="summary__txt">')) {
                $tmp = explode('<p class="summary__txt">', $html);
                $raw = isset($tmp[1]) ? explode('</p>', $tmp[1])[0] : '';
            }
        } elseif ($service === 'monthly') {
            // 月額：<p class="tx-productComment">
            if (str_contains($html, '<p class="tx-productComment">')) {
                $tmp = explode('<p class="tx-productComment">', $html);
                $raw = isset($tmp[1]) ? explode('</p>', $tmp[1])[0] : '';
            }
        } else {
            // digital（動画・素人・その他）：<meta name="description" content="...">
            if (str_contains($html, '<meta name="description" content="')) {
                $tmp = explode('<meta name="description" content="', $html);
                $raw = isset($tmp[1]) ? explode('"', $tmp[1])[0] : '';
            }
        }

        // フォールバック：meta description（どのサービスでも試す）
        if (!$raw && str_contains($html, '<meta name="description" content="')) {
            $tmp = explode('<meta name="description" content="', $html);
            $raw = isset($tmp[1]) ? explode('"', $tmp[1])[0] : '';
        }

        // 後処理：タグ除去・エンティティ復元・FANZA固有文字列の除去
        $text = htmlspecialchars_decode(strip_tags($raw));
        $text = explode('------', $text)[0];
        $text = str_replace('【FANZA(ファンザ)】', '', $text);
        return trim($text);
    }

    // API取得済み情報から代替の紹介文を生成（説明文がどうしても取れない場合）
    private function build_fallback_comment(string $title, array $actress_arr, array $genre_arr, array $maker_arr, array $series_arr): string {
        $parts = [];
        if ($actress_arr) {
            $parts[] = implode('・', array_slice(array_column($actress_arr, 'name'), 0, 3)) . ' が出演';
        }
        if ($series_arr) {
            $parts[] = '「' . ($series_arr[0]['name'] ?? '') . '」シリーズ';
        }
        if ($genre_arr) {
            $parts[] = implode('・', array_slice(array_column($genre_arr, 'name'), 0, 4)) . ' などのジャンル';
        }
        if ($maker_arr) {
            $parts[] = ($maker_arr[0]['name'] ?? '') . ' の作品';
        }

        if (!$parts) return '';

        $body = implode('、', $parts) . 'です。';
        return $title ? ($title . '。' . $body) : $body;
    }

    // 本文テキストをGutenbergブロックコメント付きblockquoteに整形（全文・200字短縮）
    private function build_comment_html(string $text): array {
        // ソース（GraphQL/スクレイピング）に <br> 等のHTMLタグや改行が含まれると
        // esc_html でそのまま文字として表示されてしまうため、ここで正規化する
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s*[\r\n]+\s*/u', '', $text); // 改行を除去（句点で改行する方針）
        $text = preg_replace('/[ \t\x{3000}]{2,}/u', ' ', $text); // 連続空白の圧縮
        $text = trim($text);
        if (!$text) return ['', ''];

        $short_text = mb_strimwidth($text, 0, 200, '…');

        $full_html  = "<!-- wp:quote -->\n"
                    . '<blockquote class="wp-block-quote"><p>' . $this->format_comment_body($text) . '</p><cite>FANZA</cite></blockquote>'
                    . "\n<!-- /wp:quote -->";

        $short_html = "<!-- wp:quote -->\n"
                    . '<blockquote class="wp-block-quote"><p>' . $this->format_comment_body($short_text) . '</p><cite>FANZA</cite></blockquote>'
                    . "\n<!-- /wp:quote -->";

        return [$full_html, $short_html];
    }

    // 説明文を読みやすく整形：エスケープ後、句点「。」の直後でのみ改行を入れる
    // （！？では改行しない＝感嘆符が多い説明文での過剰改行を防ぐ）
    private function format_comment_body(string $text): string {
        $html = esc_html($text);
        // 「。」の直後（ただし末尾と閉じ括弧の前は除く）で改行
        $html = preg_replace('/。(?![」』）\)！？]|$)/u', '。<br>', $html);
        return $html;
    }

    private function scrape_sample_imgs(string $html): array {
        if (!$html) return [];
        $imgs = [];
        // サンプル画像ブロックを探す
        if (preg_match('/<ul[^>]+id="sample-image-block"[^>]*>([\s\S]*?)<\/ul>/i', $html, $m)) {
            preg_match_all('/src="(https:\/\/[^"]+\.jpg)"/i', $m[1], $found);
            $imgs = $found[1] ?? [];
        }
        // フォールバック：jp.jpg パターン
        if (empty($imgs) && preg_match_all('/https:\/\/[^\s"\']+jp\.jpg/i', $html, $found)) {
            $imgs = array_unique($found[0]);
        }
        return array_values(array_filter($imgs));
    }

    // -----------------------------------------------------------------------
    // 投稿日時の決定
    // -----------------------------------------------------------------------
    private function resolve_date(int $slot): string {
        $mode = fap_slot_opt($slot, 'post_date', 'today');
        switch ($mode) {
            case 'specify':
                $dt = fap_slot_opt($slot, 'specify_date', '');
                return $dt ? get_gmt_from_date($dt . ' 00:00:00') : '';

            case 'random':
                $start = fap_slot_opt($slot, 'rand_start', '');
                $end   = fap_slot_opt($slot, 'rand_end',   '');
                if (!$start || !$end) return '';
                $ts_s = strtotime($start . ' 00:00:00');
                $ts_e = strtotime($end   . ' 23:59:59');
                if ($ts_s > $ts_e) [$ts_s, $ts_e] = [$ts_e, $ts_s];
                return gmdate('Y-m-d H:i:s', rand($ts_s, $ts_e));

            default: // today
                if (FAP_License::is('pro') && fap_slot_opt($slot, 'random_time_on', '0') === '1') {
                    $max_min = max(1, (int) fap_slot_opt($slot, 'random_time_max', 60));
                    $offset  = rand(0, $max_min * 60);
                    return gmdate('Y-m-d H:i:s', time() + $offset);
                }
                return '';
        }
    }

    // -----------------------------------------------------------------------
    // カテゴリ・タグ解決
    // -----------------------------------------------------------------------
    private function resolve_categories(int $slot, array $d): array {
        $source = fap_slot_opt($slot, 'cate_source', []);
        $fixed  = fap_slot_opt($slot, 'cate_fixed',  '');
        $ex     = array_filter(array_map('trim', explode('/', fap_slot_opt($slot, 'exclude_cate', ''))));

        $ids = [];
        foreach (array_filter(array_map('trim', explode('/', $fixed))) as $n) {
            $id = $this->ensure_category($n);
            if ($id && !in_array($n, $ex, true)) $ids[] = $id;
        }
        foreach ($this->names_from_source($source, $d) as $n) {
            $id = $this->ensure_category($n);
            if ($id && !in_array($n, $ex, true)) $ids[] = $id;
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    private function resolve_tags(int $slot, array $d): array {
        $source = fap_slot_opt($slot, 'tag_source', []);
        $fixed  = fap_slot_opt($slot, 'tag_fixed',  '');
        $ex     = array_filter(array_map('trim', explode('/', fap_slot_opt($slot, 'exclude_tag', ''))));

        $ids = [];
        foreach (array_filter(array_map('trim', explode('/', $fixed))) as $n) {
            $id = $this->ensure_tag($n);
            if ($id && !in_array($n, $ex, true)) $ids[] = $id;
        }
        $names    = $this->names_from_source($source, $d);
        $src_list = is_array($source) ? $source : (($source && $source !== 'none') ? [$source] : []);
        if ($src_list && !$names) {
            // 情報源を選んでいるのに1件も取れなかった場合は、原因調査用に取得件数をログへ残す
            $counts = [];
            foreach (['genre_arr', 'actress_arr', 'director_arr', 'series_arr', 'maker_arr', 'label_arr', 'author_arr'] as $k) {
                $counts[] = $k . '=' . count($d[$k] ?? []);
            }
            fap_log('[Tag] 情報源（' . implode(',', $src_list) . '）から取得0件 slot=' . $slot
                . ' cid=' . ($d['cid'] ?? '') . ' / APIの取得件数: ' . implode(' ', $counts));
        }
        foreach ($names as $n) {
            $id = $this->ensure_tag($n);
            if ($id && !in_array($n, $ex, true)) $ids[] = $id;
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * 情報源（文字列または配列）から名前一覧を返す。
     * 旧形式（文字列 'genre' や 'none'）も透過的に処理する。
     *
     * @param string|array $source
     */
    private function names_from_source($source, array $d): array {
        $map = [
            'genre'    => 'genre_arr',
            'actress'  => 'actress_arr',
            'director' => 'director_arr',
            'series'   => 'series_arr',
            'maker'    => 'maker_arr',
            'label'    => 'label_arr',
            'author'   => 'author_arr',
        ];

        // 旧形式（文字列）を配列に正規化
        if (!is_array($source)) {
            $source = ($source && $source !== 'none') ? [$source] : [];
        }

        $names = [];
        foreach ($source as $src) {
            $key = $map[$src] ?? '';
            if ($key && !empty($d[$key])) {
                foreach (array_column($d[$key], 'name') as $n) {
                    $names[] = $n;
                }
            }
        }
        return array_values(array_unique($names));
    }

    private function ensure_category(string $name): int {
        $term = get_term_by('name', $name, 'category');
        if ($term) return (int) $term->term_id;
        $r = wp_insert_term($name, 'category');
        return is_wp_error($r) ? 0 : (int) $r['term_id'];
    }

    private function ensure_tag(string $name): int {
        $term = get_term_by('name', $name, 'post_tag');
        if ($term) return (int) $term->term_id;
        $r = wp_insert_term($name, 'post_tag');
        return is_wp_error($r) ? 0 : (int) $r['term_id'];
    }

    // -----------------------------------------------------------------------
    // アイキャッチ画像の決定
    // -----------------------------------------------------------------------
    private function resolve_eyecatch(string $setting, string $pkg, array $smps): string {
        switch ($setting) {
            case 'package': return $pkg;
            case 'none':    return '';
            case 'sample':  return $smps[0] ?? $pkg;
            case '99':      return !empty($smps) ? end($smps) : $pkg;
            default:
                $idx = ((int) $setting) - 1;
                return $smps[$idx] ?? $pkg;
        }
    }

    private function thumb_alt(string $title, array $actress_arr): string {
        $actress = implode('・', array_column($actress_arr, 'name'));
        return $actress ? $title . ' ' . $actress : $title;
    }

    // -----------------------------------------------------------------------
    // 画像ダウンロード
    // -----------------------------------------------------------------------
    public function download_image(string $url, int $post_id, string $alt = ''): int {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) return 0;

        $ext  = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg';
        $file = [
            'name'     => 'fap-' . md5($url) . '.' . $ext,
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload($file, $post_id, $alt);
        @unlink($tmp);
        if (is_wp_error($id)) return 0;

        if ($alt) update_post_meta($id, '_wp_attachment_image_alt', $alt);
        return (int) $id;
    }

    // -----------------------------------------------------------------------
    // ユーティリティ
    // -----------------------------------------------------------------------
    private function extract(object $item, string $key): array {
        if (!isset($item->iteminfo->$key)) return [];
        $out = [];
        foreach ((array) $item->iteminfo->$key as $entry) {
            $out[] = ['id' => (int)($entry->id ?? 0), 'name' => (string)($entry->name ?? '')];
        }
        return $out;
    }

    private function extract_sample_imgs(object $item): array {
        if (!isset($item->sampleImageURL)) return [];

        // 大画像リスト（sample_l）が直接ある場合はそれをそのまま使用
        if (isset($item->sampleImageURL->sample_l->image)) {
            return array_values(array_filter(
                array_map('strval', (array) $item->sampleImageURL->sample_l->image)
            ));
        }

        // 小画像（sample_s）URLから大画像URLへパターン変換
        if (!isset($item->sampleImageURL->sample_s->image)) return [];
        $imgs = [];
        foreach ((array) $item->sampleImageURL->sample_s->image as $raw) {
            $img = (string) $raw;
            if (str_contains($img, 'ps-')) {
                $img = str_replace('ps-', 'jp-', $img);
            } elseif (str_contains($img, 'js-')) {
                $img = str_replace('js-', 'jp-', $img);
            }
            $imgs[] = $img;
        }
        return array_values(array_filter($imgs));
    }

    // サムネイル（小画像）リストを返す（[sample-flex] 用）
    private function extract_sample_imgs_small(object $item): array {
        if (!isset($item->sampleImageURL->sample_s->image)) return [];
        return array_values(array_filter(
            array_map('strval', (array) $item->sampleImageURL->sample_s->image)
        ));
    }

    private function get_randoms(int $slot): array {
        $out = [];
        for ($i = 1; $i <= 3; $i++) {
            $pool = fap_slot_opt($slot, 'random' . $i, '');
            if ($pool) {
                $lines  = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $pool)));
                $out[$i] = $lines ? $lines[array_rand($lines)] : '';
            } else {
                $out[$i] = '';
            }
        }
        return $out;
    }

    private function is_blacklisted(string $title, string $blacklist): bool {
        $words = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $blacklist)));
        foreach ($words as $w) {
            if ($w && str_contains($title, $w)) return true;
        }
        return false;
    }

    // タイトルテンプレートをランダム選択（PRO+: tpl2/3も候補に入れる）
    private function pick_title_tpl(int $slot): string {
        $base = fap_slot_opt($slot, 'title_tpl', '[title]') ?: '[title]';
        if (!FAP_License::is('pro')) return $base;

        $candidates = array_values(array_filter([
            $base,
            fap_slot_opt($slot, 'title_tpl2', ''),
            fap_slot_opt($slot, 'title_tpl3', ''),
        ]));
        return $candidates[array_rand($candidates)];
    }

    // PRO拡張除外条件チェック（ジャンル/女優/メーカー/評価）
    private function is_excluded_pro(object $item, int $slot): bool {
        // ジャンル除外
        $bl_genre = fap_slot_opt($slot, 'blacklist_genre', '');
        if ($bl_genre) {
            $words  = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $bl_genre)));
            $genres = array_column((array) ($item->iteminfo->genre ?? []), 'name');
            foreach ($words as $w) {
                foreach ($genres as $g) {
                    if ($w && str_contains($g, $w)) return true;
                }
            }
        }
        // 女優除外
        $bl_actress = fap_slot_opt($slot, 'blacklist_actress', '');
        if ($bl_actress) {
            $words    = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $bl_actress)));
            $actresses = array_column((array) ($item->iteminfo->actress ?? []), 'name');
            foreach ($words as $w) {
                foreach ($actresses as $a) {
                    if ($w && str_contains($a, $w)) return true;
                }
            }
        }
        // メーカー除外
        $bl_maker = fap_slot_opt($slot, 'blacklist_maker', '');
        if ($bl_maker) {
            $words  = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $bl_maker)));
            $makers = array_column((array) ($item->iteminfo->maker ?? []), 'name');
            foreach ($words as $w) {
                foreach ($makers as $m) {
                    if ($w && str_contains($m, $w)) return true;
                }
            }
        }
        // 最低レビュー評価
        $min_avg = (float) fap_slot_opt($slot, 'min_review_avg', '');
        if ($min_avg > 0) {
            $avg = (float) ($item->review->average ?? 0);
            if ($avg > 0 && $avg < $min_avg) return true;
        }
        // 最低レビュー件数
        $min_cnt = (int) fap_slot_opt($slot, 'min_review_cnt', '');
        if ($min_cnt > 0) {
            $cnt = (int) ($item->review->count ?? 0);
            if ($cnt < $min_cnt) return true;
        }
        return false;
    }

    // 同一CIDの投稿が既に存在するか（下書き・予約含む）
    private function cid_exists(string $cid): bool {
        if (!$cid) return false;
        $q = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'meta_key'       => '_fap_cid',
            'meta_value'     => $cid,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        return !empty($q->posts);
    }

    // -----------------------------------------------------------------------
    // セール検知＆自動リライト（PREMIUM）
    // 既存FAP投稿のCIDをAPIで確認し、セール状態が変わったら投稿タイトルを更新する。
    // 1回の実行で50件処理し、オフセットを進めながら全記事を巡回する（1時間ごとに
    // スケジューラから呼ばれ、全件を1日1周する）。1周完了で done_date に記録。
    // -----------------------------------------------------------------------
    public function run_sale_check(): array {
        if (!FAP_License::is('premium')) {
            return ['ok' => false, 'msg' => 'Premium プランが必要です。'];
        }

        $api_id = fap_secret('FAP_FANZA_API_ID', 'api_id');
        $aff_id = fap_secret('FAP_FANZA_AFF_ID', 'aff_id');
        if (!$api_id || !$aff_id) {
            return ['ok' => false, 'msg' => 'API IDが未設定です。'];
        }

        $batch  = 50;
        $offset = (int) fap_opt('sale_check_offset', 0);

        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $batch,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_fap_cid',   'compare' => 'EXISTS'],
                ['key' => '_fap_floor', 'compare' => 'EXISTS'],
            ],
        ]);

        $processed  = 0;
        $sale_found = 0;
        $sale_ended = 0;
        $rerendered = 0;
        $rerender   = fap_opt('sale_rerender', '0') === '1';

        foreach ($posts as $post) {
            $cid       = (string) get_post_meta($post->ID, '_fap_cid',     true);
            $floor_key = (string) get_post_meta($post->ID, '_fap_floor',   true);
            $service   = (string) get_post_meta($post->ID, '_fap_service', true);
            if (!$cid || !$floor_key) continue;

            $fp = FAP_API::parse_floor_key($floor_key);
            if (!$fp['site'] || !$fp['service'] || !$fp['floor']) continue;

            $item        = $this->api->fetch_item_by_cid($cid, $api_id, $aff_id, $fp['site'], $service ?: $fp['service'], $fp['floor']);
            $was_on_sale = (string) get_post_meta($post->ID, '_fap_on_sale', true) === '1';
            $is_on_sale  = $item ? $this->api->is_on_sale($item) : false;
            $list_price  = ($item && isset($item->prices->list_price)) ? (int) $item->prices->list_price : 0;

            if ($is_on_sale && !$was_on_sale) {
                $sale_price = '';
                if ($item && isset($item->prices->price)) $sale_price = (string) $item->prices->price;
                $this->apply_sale_badge($post->ID, $sale_price, $item);
                FAP_Sale_History::log($cid, $post->ID, 'start', $list_price, (int) $sale_price);
                if ($rerender && $item && $this->rerender_content($post->ID, $item)) $rerendered++;
                $sale_found++;
            } elseif (!$is_on_sale && $was_on_sale) {
                $this->remove_sale_badge($post->ID, $item);
                FAP_Sale_History::log($cid, $post->ID, 'end', $list_price, 0);
                if ($rerender && $item && $this->rerender_content($post->ID, $item)) $rerendered++;
                $sale_ended++;
            } elseif ($is_on_sale && $was_on_sale) {
                // セール継続中の価格変更（段階値下げ等）を検知して表示価格と履歴を最新化
                $cur_price = (int) get_post_meta($post->ID, '_fap_sale_price', true);
                $new_price = ($item && isset($item->prices->price)) ? (int) $item->prices->price : 0;
                if ($new_price > 0 && $cur_price > 0 && $new_price !== $cur_price) {
                    update_post_meta($post->ID, '_fap_sale_price', (string) $new_price);
                    FAP_Sale_History::log($cid, $post->ID, 'price', $list_price, $new_price);
                    if ($item) $this->refresh_title($post->ID, $item); // タイトルの割引率・価格を最新化
                    fap_log('[SaleCheck] セール価格変更 post_id=' . $post->ID . ' ' . $cur_price . '→' . $new_price);
                }
            } elseif (!$is_on_sale && (string) get_post_meta($post->ID, '_fap_sale_price', true) !== '') {
                // 投稿時にセール中だったが、初回チェック前にセールが終了していた場合の掃除
                update_post_meta($post->ID, '_fap_sale_price', '');
                $this->sync_sale_categories($post->ID, false); // セールカテゴリを外し、セール中以外カテゴリへ
                if ($item) $this->refresh_title($post->ID, $item); // 投稿時に入ったセール文言を外す
                if ($rerender && $item && $this->rerender_content($post->ID, $item)) $rerendered++;
            } elseif ($item && !$is_on_sale && $this->nonsale_category_id() > 0 && !has_category($this->nonsale_category_id(), $post->ID)) {
                // セール中でない既存記事に、セール中以外カテゴリを付与（検知の巡回で順次反映）
                $this->sync_sale_categories($post->ID, false);
            }
            $processed++;
        }

        // オフセットを進める。取得件数がバッチ未満なら最終ページ＝1周完了
        $done = count($posts) < $batch;
        if ($done) {
            update_option(FAP_PREFIX . 'sale_check_offset', 0);
            update_option(FAP_PREFIX . 'sale_check_done_date', current_time('Y-m-d'));
        } else {
            update_option(FAP_PREFIX . 'sale_check_offset', $offset + count($posts));
        }

        update_option(FAP_PREFIX . 'sale_check_last_at', current_time('Y-m-d H:i:s'));
        fap_log(sprintf(
            '[SaleCheck] バッチ完了 offset=%d processed=%d sale_found=%d sale_ended=%d rerendered=%d%s',
            $offset, $processed, $sale_found, $sale_ended, $rerendered, $done ? ' （全記事1周完了）' : ''
        ));

        return ['ok' => true, 'processed' => $processed, 'sale_found' => $sale_found, 'sale_ended' => $sale_ended, 'rerendered' => $rerendered, 'done' => $done, 'offset' => $offset];
    }

    // -----------------------------------------------------------------------
    // 販売停止チェック（PREMIUM）
    // 既存FAP投稿のCIDをAPIで照会し、販売停止になった作品の記事を検出する。
    // AJAXからバッチ実行される想定（$offset から $limit 件ずつ処理）。
    // 削除はここでは行わず、検出リストを返すだけ（削除は管理画面で確認後に実行）。
    // -----------------------------------------------------------------------
    public function run_discontinued_check(int $offset = 0, int $limit = 10): array {
        if (!FAP_License::is('pro')) {
            return ['ok' => false, 'msg' => 'Pro プラン以上が必要です。'];
        }

        $api_id = fap_secret('FAP_FANZA_API_ID', 'api_id');
        $aff_id = fap_secret('FAP_FANZA_AFF_ID', 'aff_id');
        if (!$api_id || !$aff_id) {
            return ['ok' => false, 'msg' => 'API IDが未設定です。'];
        }

        $q = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_fap_cid',   'compare' => 'EXISTS'],
                ['key' => '_fap_floor', 'compare' => 'EXISTS'],
            ],
        ]);

        $total        = (int) $q->found_posts;
        $checked      = 0;
        $errors       = 0;
        $discontinued = [];

        foreach ($q->posts as $post) {
            $cid       = (string) get_post_meta($post->ID, '_fap_cid',     true);
            $floor_key = (string) get_post_meta($post->ID, '_fap_floor',   true);
            $service   = (string) get_post_meta($post->ID, '_fap_service', true);
            $checked++;
            if (!$cid || !$floor_key) continue;

            $fp = FAP_API::parse_floor_key($floor_key);
            if (!$fp['site'] || !$fp['service'] || !$fp['floor']) continue;

            $status = $this->api->check_availability($cid, $api_id, $aff_id, $fp['site'], $service ?: $fp['service'], $fp['floor']);

            if ($status === 'error') {
                $errors++;
                continue;
            }
            if ($status === 'discontinued') {
                $discontinued[] = [
                    'id'       => $post->ID,
                    'title'    => get_the_title($post->ID),
                    'cid'      => $cid,
                    'date'     => get_the_date('Y-m-d', $post->ID),
                    'edit_url' => get_edit_post_link($post->ID, 'raw'),
                    'view_url' => get_permalink($post->ID),
                ];
            }
        }

        $next_offset = $offset + $checked;
        $done        = $checked < $limit || $next_offset >= $total;

        if ($done) {
            update_option(FAP_PREFIX . 'discontinued_check_last_at', current_time('Y-m-d H:i:s'));
        }
        fap_log(sprintf('[DiscontinuedCheck] offset=%d checked=%d found=%d errors=%d total=%d', $offset, $checked, count($discontinued), $errors, $total));

        return [
            'ok'           => true,
            'total'        => $total,
            'checked'      => $checked,
            'errors'       => $errors,
            'next_offset'  => $next_offset,
            'done'         => $done,
            'discontinued' => $discontinued,
        ];
    }

    // セール開始時の処理。
    // タイトルは「投稿時と同じタイトルテンプレート」をセール状態で描き直して決める
    // （[sale_title_1]〜[sale_title_5] や [fap_if_sale] を使っていればここで反映される）。
    private function apply_sale_badge(int $post_id, string $sale_price, ?object $item = null): void {
        $new_title   = $item ? $this->render_title_for_post($post_id, $item) : '';
        $new_content = null;

        if (fap_opt('ai_auto_sale', '0') === '1') {
            $post = get_post($post_id);
            if ($post) {
                $original = get_post_meta($post_id, '_fap_original_title', true);
                if (!$original) {
                    $original = get_the_title($post_id);
                    update_post_meta($post_id, '_fap_original_title', $original);
                }
                // 元の本文をまだ保存していなければ保存しておく（セール終了時に復元するため）
                if (!get_post_meta($post_id, '_fap_original_content', true)) {
                    update_post_meta($post_id, '_fap_original_content', $post->post_content);
                }
                $ai_result = FAP_AI::rewrite($original, $post->post_content);
                if ($ai_result['ok']) {
                    if ($ai_result['title'])   $new_title   = $ai_result['title'];
                    if ($ai_result['content']) $new_content = $ai_result['content'];
                    fap_log('[AI] リライト成功 post_id=' . $post_id);
                } else {
                    fap_log('[AI] リライト失敗 post_id=' . $post_id . ' msg=' . $ai_result['msg']);
                }
            } else {
                fap_log('[AI] 投稿が見つかりません post_id=' . $post_id);
            }
        }

        $update = ['ID' => $post_id];
        if ($new_title !== '' && $new_title !== (string) get_post_field('post_title', $post_id, 'raw')) {
            $update['post_title'] = $new_title;
        }
        if ($new_content !== null) $update['post_content'] = $new_content;
        if (count($update) > 1) {
            $result = wp_update_post($update);
            if (!$result || is_wp_error($result)) {
                fap_log('[SaleCheck] タイトル更新失敗 post_id=' . $post_id);
                return;
            }
        }

        update_post_meta($post_id, '_fap_on_sale',    '1');
        update_post_meta($post_id, '_fap_sale_price', $sale_price);

        // 「セール中」カテゴリを付与し、「セール中以外」カテゴリを外す（各設定ON時）
        $this->sync_sale_categories($post_id, true);

        fap_log('[SaleCheck] セール検知 post_id=' . $post_id . ' price=' . $sale_price);
    }

    // セール終了時の処理。
    // AIリライト（セール時自動リライト）を使った場合は従来どおり元のタイトル・本文へ復元し、
    // それ以外はタイトルテンプレートをセール終了状態で描き直す。
    private function remove_sale_badge(int $post_id, ?object $item = null): void {
        $original_title   = (string) get_post_meta($post_id, '_fap_original_title',   true);
        $original_content = (string) get_post_meta($post_id, '_fap_original_content', true);

        $update = ['ID' => $post_id];
        if ($original_title && fap_opt('ai_auto_sale', '0') === '1') {
            $update['post_title'] = $original_title;
        } elseif ($item) {
            $rendered = $this->render_title_for_post($post_id, $item);
            if ($rendered !== '' && $rendered !== (string) get_post_field('post_title', $post_id, 'raw')) {
                $update['post_title'] = $rendered;
            }
        }
        if ($original_content) $update['post_content'] = $original_content;
        if (count($update) > 1) {
            $result = wp_update_post($update);
            if (!$result || is_wp_error($result)) {
                fap_log('[SaleCheck] セール終了 タイトル復元失敗 post_id=' . $post_id);
            }
        }

        update_post_meta($post_id, '_fap_on_sale',    '0');
        update_post_meta($post_id, '_fap_sale_price', '');

        // 「セール中」カテゴリを外し、「セール中以外」カテゴリを付ける（各設定ON時。カテゴリ名変更後の旧カテゴリは対象外）
        $this->sync_sale_categories($post_id, false);

        fap_log('[SaleCheck] セール終了 post_id=' . $post_id);
    }

    // -----------------------------------------------------------------------
    // 「セール中」カテゴリのID（設定OFFなら0）。カテゴリは無ければ作成する
    // -----------------------------------------------------------------------
    private function sale_category_id(): int {
        if (fap_opt('sale_category_on', '0') !== '1') return 0;
        $name = trim((string) fap_opt('sale_category_name', 'セール中')) ?: 'セール中';
        return $this->ensure_category($name);
    }

    // 「セール中以外」カテゴリのID（設定OFFなら0）。セール中カテゴリと同名の場合も0（取り違え防止）
    private function nonsale_category_id(): int {
        if (fap_opt('nonsale_category_on', '0') !== '1') return 0;
        $name = trim((string) fap_opt('nonsale_category_name', '通常価格')) ?: '通常価格';
        $sale_name = trim((string) fap_opt('sale_category_name', 'セール中')) ?: 'セール中';
        if ($name === $sale_name) return 0;
        return $this->ensure_category($name);
    }

    // セール状態に合わせてカテゴリを付け替える。
    // 追加を先に行ってから外すので、カテゴリが0件になって「未分類」扱いになることはない。
    private function sync_sale_categories(int $post_id, bool $on_sale): void {
        $sale_cat    = $this->sale_category_id();
        $nonsale_cat = $this->nonsale_category_id();

        if ($on_sale) {
            if ($sale_cat > 0)    wp_set_post_categories($post_id, [$sale_cat], true); // append
            if ($nonsale_cat > 0) wp_remove_object_terms($post_id, $nonsale_cat, 'category');
        } else {
            if ($nonsale_cat > 0) wp_set_post_categories($post_id, [$nonsale_cat], true); // append
            if ($sale_cat > 0)    wp_remove_object_terms($post_id, $sale_cat, 'category');
        }
    }

    // -----------------------------------------------------------------------
    // 本文の再生成（セール検知時・PREMIUM）
    // 最新のAPIデータで投稿時と同じテンプレートを描画し直し、本文を差し替える。
    // タイトルは apply_sale_badge / remove_sale_badge が管理するためここでは触らない。
    // -----------------------------------------------------------------------
    private function rerender_content(int $post_id, object $item): bool {
        $slot      = (int)    get_post_meta($post_id, '_fap_slot',    true);
        $service   = (string) get_post_meta($post_id, '_fap_service', true);
        $floor_key = (string) get_post_meta($post_id, '_fap_floor',   true);
        if ($slot < 1) {
            fap_log('[SaleCheck] 本文再生成スキップ（スロット情報なし） post_id=' . $post_id);
            return false;
        }

        $fp = FAP_API::parse_floor_key($floor_key);
        $this->ai_desc_override = (string) get_post_meta($post_id, '_fap_ai_desc', true); // AIは再実行しない
        try {
            $d  = $this->assemble_data($item, $slot, $service ?: $fp['service'], $fp['floor']);
        } finally {
            $this->ai_desc_override = null;
        }
        if ($d === null) {
            fap_log('[SaleCheck] 本文再生成失敗（データ組み立て不可） post_id=' . $post_id);
            return false;
        }

        $template = $this->get_template($slot, array_column($d['genre_arr'], 'name'));
        $content  = $this->replace_all($template ?: $this->default_content($d), $d);
        if (str_contains($content, '[related-new]')) {
            $content = str_replace('[related-new]', $this->build_related_new_posts($post_id, 5), $content);
        }

        kses_remove_filters();
        try {
            $result = wp_update_post(['ID' => $post_id, 'post_content' => $content], true);
        } finally {
            kses_init_filters();
        }
        if (!$result || is_wp_error($result)) {
            fap_log('[SaleCheck] 本文再生成の保存失敗 post_id=' . $post_id);
            return false;
        }

        // 再生成後の本文が最新なので、セール終了時の復元用スナップショットは破棄する
        // （古い本文に巻き戻されるのを防ぐ）
        delete_post_meta($post_id, '_fap_original_content');

        // 価格・レビュー系メタも最新化（[fap_if_review]等の条件表示が最新状態を参照するため）
        update_post_meta($post_id, '_fap_price',      $d['price'] ?? '');
        update_post_meta($post_id, '_fap_sale_price', $d['sale_price'] ?? '');
        update_post_meta($post_id, '_fap_review_avg', $d['review_avg'] ?? '');
        update_post_meta($post_id, '_fap_review_cnt', $d['review_cnt'] ?? '');

        fap_log('[SaleCheck] 本文再生成 post_id=' . $post_id);
        return true;
    }

    // -----------------------------------------------------------------------
    // ランキング記事生成（PREMIUM）
    // ランキングスロット1〜4に対応。スロット1は従来のオプションキー（ranking_*）を
    // そのまま使い、スロット2〜4は ranking2_* / ranking3_* / ranking4_* を使う。
    // -----------------------------------------------------------------------
    private int $ranking_slot = 1;

    // 現在のランキングスロットのオプションキー名を返す
    private function rk_key(string $key): string {
        return ($this->ranking_slot <= 1 ? 'ranking_' : 'ranking' . $this->ranking_slot . '_') . $key;
    }

    // 現在のランキングスロットの設定値を取得
    private function ropt(string $key, $default = '') {
        return fap_opt($this->rk_key($key), $default);
    }

    public function run_ranking(int $slot = 1): array {
        $this->ranking_slot = max(1, min(4, $slot));
        try {
            return $this->run_ranking_inner();
        } finally {
            $this->ranking_slot = 1;
        }
    }

    private function run_ranking_inner(): array {
        if (!FAP_License::is('premium')) {
            return ['ok' => false, 'msg' => 'Premiumプランが必要です。'];
        }

        $api_id = fap_secret('FAP_FANZA_API_ID', 'api_id');
        $aff_id = fap_secret('FAP_FANZA_AFF_ID', 'aff_id');
        if (!$api_id || !$aff_id) {
            return ['ok' => false, 'msg' => 'APIキーが設定されていません。'];
        }

        $floor_key = $this->ropt('floor', '');
        if (!$floor_key) {
            return ['ok' => false, 'msg' => 'ランキングのフロアが未設定です。'];
        }

        $fp = FAP_API::parse_floor_key($floor_key);
        if (!$fp['site'] || !$fp['service'] || !$fp['floor']) {
            return ['ok' => false, 'msg' => 'フロアキーが不正です。'];
        }

        $count = max(5, min(20, (int) $this->ropt('count', 10)));
        $sort  = $this->ropt('sort', 'rank') ?: 'rank';

        $params = [
            'api_id'     => $api_id,
            'aff_id'     => $aff_id,
            'site'       => $fp['site'],
            'service'    => $fp['service'],
            'floor'      => $fp['floor'],
            'sort'       => $sort,
            'skip_dedup' => true,
            // セール中の作品に限定（セール特化サイト向け。search側のsale_onlyフィルタを再利用）
            'sale_only'  => $this->ropt('sale_only', '0') === '1',
        ];

        $items = $this->api->search($params, $count);
        if (empty($items)) {
            $sale_only_on = $this->ropt('sale_only', '0') === '1';
            return ['ok' => false, 'msg' => $sale_only_on
                ? '商品を取得できませんでした（セール中限定がONのため、該当作品が無い可能性があります）。'
                : '商品を取得できませんでした。'];
        }

        $floor_name = $this->ropt('flr_name', '');
        $title_tpl  = $this->ropt('title_tpl', '[year]年[month]月 FANZAランキング TOP[count]');
        $post_title = strtr($title_tpl, [
            '[year]'  => date_i18n('Y'),
            '[month]' => date_i18n('n'),
            '[day]'   => date_i18n('j'),
            '[date]'  => date_i18n('Y年n月j日'),
            '[count]' => count($items),
            '[floor]' => $floor_name,
        ]);

        // 前回ランキングのCID一覧を取得（順位変動バッジ用。スロットごとに別キーで管理）
        $prev_opt  = $this->rk_key('prev_cids');
        $prev_cids = (array) get_option(FAP_PREFIX . $prev_opt, []);

        $content = $this->build_ranking_content($items, $fp['service'], $prev_cids);

        $status = $this->ropt('post_status', 'publish');

        kses_remove_filters();
        try {
            $post_id = wp_insert_post([
                'post_title'   => wp_strip_all_tags($post_title),
                'post_content' => $content,
                'post_status'  => $status,
                'post_type'    => 'post',
            ]);
        } finally {
            kses_init_filters();
        }

        if (!$post_id || is_wp_error($post_id)) {
            return ['ok' => false, 'msg' => '投稿の作成に失敗しました。'];
        }

        // カテゴリ
        $cate_fixed = $this->ropt('cate_fixed', '');
        if ($cate_fixed) {
            $cat_ids = [];
            foreach (array_filter(array_map('trim', explode('/', $cate_fixed))) as $n) {
                $id = $this->ensure_category($n);
                if ($id) $cat_ids[] = $id;
            }
            if ($cat_ids) wp_set_post_categories($post_id, $cat_ids);
        }

        // タグ
        $tag_fixed = $this->ropt('tag_fixed', '');
        if ($tag_fixed) {
            $tag_names = array_filter(array_map('trim', explode('/', $tag_fixed)));
            if ($tag_names) wp_set_post_tags($post_id, $tag_names);
        }

        update_post_meta($post_id, '_fap_ranking', '1');
        update_post_meta($post_id, '_fap_ranking_count', count($items));
        update_option(FAP_PREFIX . $this->rk_key('last_at'), current_time('Y-m-d H:i:s'));

        // アイキャッチ：1〜3位のコラージュ画像（GD利用可能時）または1位画像
        $attach_id = $this->build_collage_image($items, $post_id);
        if ($attach_id) set_post_thumbnail($post_id, $attach_id);

        // 今回のCID一覧を保存（次回の順位変動バッジ用）
        update_option(FAP_PREFIX . $prev_opt, array_map(fn($it) => (string) ($it->content_id ?? ''), $items));

        fap_log('[Ranking] 生成完了 post_id=' . $post_id . ' count=' . count($items));

        return ['ok' => true, 'post_id' => $post_id, 'count' => count($items), 'msg' => ''];
    }

    private function build_ranking_content(array $items, string $service, array $prev_cids = [], bool $skip_desc = false): string {
        $medals  = ['🥇', '🥈', '🥉'];
        $content = '';

        // 冒頭・締めテンプレート（下書きがあれば差し替え）
        $intro = $this->get_ranking_part('冒頭');
        $outro = $this->get_ranking_part('締め');
        $intro_html = $intro
            ? $this->replace_ranking_part($intro, count($items)) . "\n\n"
            : '<p>' . date_i18n('Y年n月') . 'のFANZAランキングをご紹介します。人気作品を厳選してまとめました。</p>' . "\n\n";
        $outro_html = $outro ? "\n" . $this->replace_ranking_part($outro, count($items)) : '';

        // [ranking-table]: 全作品のサマリー比較表（順位・作品・価格・評価。各行から該当カードへジャンプ）
        if (str_contains($intro_html, '[ranking-table]') || str_contains($outro_html, '[ranking-table]')) {
            $summary    = $this->build_ranking_summary_table($items);
            $intro_html = str_replace('[ranking-table]', $summary, $intro_html);
            $outro_html = str_replace('[ranking-table]', $summary, $outro_html);
        }

        // テンプレートあり → 各アイテムをテンプレートで描画して返す
        $ranking_tpl = $this->get_ranking_template();
        if ($ranking_tpl) {
            $fetch_desc = !$skip_desc && $this->ropt('fetch_desc', '0') === '1';
            $content .= $intro_html;
            foreach ($items as $i => $item) {
                $content .= $this->replace_ranking_item($ranking_tpl, $item, $i + 1, $service, $fetch_desc, $prev_cids, count($items));
                $content .= "\n";
            }
            return $content . $outro_html;
        }

        $content .= $intro_html;

        foreach ($items as $i => $item) {
            $rank     = $i + 1;
            $medal    = $medals[$i] ?? '';
            $title    = (string) ($item->title       ?? '');
            $aff_url  = (string) ($item->affiliateURL ?? '');
            $img      = (string) ($item->imageURL->large ?? $item->imageURL->small ?? '');
            if (str_contains($img, 'jm.jpg')) $img = str_replace('jm.jpg', 'jp.jpg', $img);
            if (str_contains($img, 'js.jpg')) $img = str_replace('js.jpg', 'jp.jpg', $img);

            $actress_arr = $this->extract($item, 'actress');
            $genre_arr   = $this->extract($item, 'genre');
            $maker_arr   = $this->extract($item, 'maker');

            $actress_str = implode('・', array_column($actress_arr, 'name'));
            $genre_str   = implode(' ', array_slice(array_column($genre_arr, 'name'), 0, 5));
            $maker_str   = implode('・', array_column($maker_arr, 'name'));

            $price = '';
            if (isset($item->prices->list_price))     $price = (string) $item->prices->list_price;
            elseif (isset($item->prices->price))      $price = (string) $item->prices->price;

            $avg = (float) ($item->review->average ?? 0);
            $cnt = (int)   ($item->review->count   ?? 0);

            $rank_label  = ($medal ? $medal . ' 第' . $rank . '位' : '第' . $rank . '位');
            $cid_fixed   = (string) ($item->content_id ?? '');
            $badge_fixed = $this->build_rank_change_badge($cid_fixed, $rank, $prev_cids);
            $content .= '<h2 style="border-left:4px solid #e0306e;padding-left:12px;margin-top:40px;">'
                      . esc_html($rank_label . '：' . $title) . $badge_fixed
                      . '</h2>' . "\n";

            $content .= '<div style="display:flex;gap:20px;flex-wrap:wrap;margin:16px 0;">' . "\n";

            if ($img) {
                $img_tag = '<img src="' . esc_url($img) . '" alt="' . esc_attr('第' . $rank . '位：' . $title) . '"'
                         . ' loading="lazy" decoding="async" style="max-width:180px;height:auto;border-radius:6px;" />';
                $content .= '<div style="flex:0 0 auto;">';
                $content .= $aff_url
                    ? '<a href="' . esc_url($aff_url) . '" target="_blank" rel="nofollow sponsored noopener noreferrer">' . $img_tag . '</a>'
                    : $img_tag;
                $content .= '</div>' . "\n";
            }

            $content .= '<div style="flex:1 1 200px;">' . "\n"
                      . '<table style="border-collapse:collapse;width:100%;font-size:14px;">' . "\n";
            if ($actress_str) {
                $content .= '<tr><th style="border:1px solid #ddd;padding:6px 10px;background:#f7f7f7;white-space:nowrap;width:30%;text-align:left;">女優</th>'
                          . '<td style="border:1px solid #ddd;padding:6px 10px;">' . esc_html($actress_str) . '</td></tr>' . "\n";
            }
            if ($maker_str) {
                $content .= '<tr><th style="border:1px solid #ddd;padding:6px 10px;background:#f7f7f7;white-space:nowrap;text-align:left;">メーカー</th>'
                          . '<td style="border:1px solid #ddd;padding:6px 10px;">' . esc_html($maker_str) . '</td></tr>' . "\n";
            }
            if ($genre_str) {
                $content .= '<tr><th style="border:1px solid #ddd;padding:6px 10px;background:#f7f7f7;white-space:nowrap;text-align:left;">ジャンル</th>'
                          . '<td style="border:1px solid #ddd;padding:6px 10px;">' . esc_html($genre_str) . '</td></tr>' . "\n";
            }
            if ($price) {
                $content .= '<tr><th style="border:1px solid #ddd;padding:6px 10px;background:#f7f7f7;white-space:nowrap;text-align:left;">価格</th>'
                          . '<td style="border:1px solid #ddd;padding:6px 10px;">' . esc_html($price) . '</td></tr>' . "\n";
            }
            if ($avg > 0 && $cnt > 0) {
                $content .= '<tr><th style="border:1px solid #ddd;padding:6px 10px;background:#f7f7f7;white-space:nowrap;text-align:left;">評価</th>'
                          . '<td style="border:1px solid #ddd;padding:6px 10px;">' . esc_html(number_format($avg, 1) . '（' . $cnt . '件）') . '</td></tr>' . "\n";
            }
            $content .= '</table>' . "\n" . '</div>' . "\n" . '</div>' . "\n";

            // 説明文（管理画面でONかつプレビューでないとき取得）
            $cid = (string) ($item->content_id ?? '');
            if (!$skip_desc && fap_opt('ranking_fetch_desc', '0') === '1' && $cid) {
                $desc = '';
                if ($service === 'digital') {
                    $desc = $this->api->fetch_description_graphql($cid);
                }
                if (!$desc) {
                    $item_url_str = (string) ($item->URL ?? '');
                    if ($item_url_str) {
                        $page_html = $this->api->fetch_page($item_url_str);
                        $desc = $this->extract_comment_text($page_html, $service);
                    }
                }
                if ($desc) {
                    $desc_text = mb_strimwidth(wp_strip_all_tags($desc), 0, 200, '…');
                    $content .= '<p style="margin:12px 0 16px;font-size:14px;color:#374151;line-height:1.75;">'
                              . esc_html($desc_text)
                              . '</p>' . "\n";
                }
            }

            if ($aff_url) {
                $content .= '<p style="margin:12px 0 32px;text-align:center;">'
                          . '<a href="' . esc_url($aff_url) . '" target="_blank" rel="nofollow sponsored noopener noreferrer"'
                          . ' style="display:inline-block;padding:14px 28px;background:#e0306e;color:#fff;'
                          . 'text-decoration:none;border-radius:10px;font-weight:bold;font-size:15px;">'
                          . esc_html('FANZAで第' . $rank . '位を確認する →')
                          . '</a></p>' . "\n";
            }
        }

        return $content . $outro_html;
    }

    // -----------------------------------------------------------------------
    // ランキングテンプレート取得（「FAP ランキングテンプレート」という下書き投稿）
    // -----------------------------------------------------------------------
    private function get_ranking_template(): string {
        global $wpdb;
        // スロット2〜4は番号付きテンプレートを優先し、無ければ共通テンプレートを使う
        $names = [];
        if ($this->ranking_slot > 1) {
            $names[] = 'FAP ランキングテンプレート' . $this->ranking_slot;
        }
        $names[] = 'FAP ランキングテンプレート';
        foreach ($names as $name) {
            $c = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_content FROM {$wpdb->posts}
                     WHERE post_title = %s AND post_type = 'post' AND post_status = 'draft'
                     ORDER BY post_date DESC LIMIT 1",
                    $name
                )
            );
            if ($c) return $c;
        }
        return '';
    }

    // -----------------------------------------------------------------------
    // ランキングの冒頭・締めテンプレート取得
    // 下書きタイトル「FAP ランキング冒頭テンプレート」「FAP ランキング締めテンプレート」。
    // セール品のみ取得がONのときは「〜テンプレート（セール）」を優先して探す。
    // 見つからなければ空文字（冒頭は従来の定型文にフォールバック）。
    // -----------------------------------------------------------------------
    private function get_ranking_part(string $part): string {
        global $wpdb;
        $names = [];
        // スロット2〜4は番号付きを最優先（例: FAP ランキング冒頭テンプレート2）
        if ($this->ranking_slot > 1) {
            $names[] = 'FAP ランキング' . $part . 'テンプレート' . $this->ranking_slot;
        }
        if ($this->ropt('sale_only', '0') === '1') {
            $names[] = 'FAP ランキング' . $part . 'テンプレート（セール）';
        }
        $names[] = 'FAP ランキング' . $part . 'テンプレート';
        foreach ($names as $name) {
            $c = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_content FROM {$wpdb->posts}
                     WHERE post_title = %s AND post_type = 'post' AND post_status = 'draft'
                     ORDER BY post_date DESC LIMIT 1",
                    $name
                )
            );
            if ($c) return $c;
        }
        return '';
    }

    // 冒頭・締めテンプレートの変数置換
    private function replace_ranking_part(string $tpl, int $count): string {
        return strtr($tpl, [
            '[year]'  => date_i18n('Y'),
            '[month]' => date_i18n('n'),
            '[day]'   => date_i18n('j'),
            '[date]'  => date_i18n('Y年n月j日'),
            '[count]' => (string) $count,
            '[floor]' => (string) $this->ropt('flr_name', ''),
        ]);
    }

    // -----------------------------------------------------------------------
    // ランキングのサマリー比較表を生成（[ranking-table] 用）
    // 各行の作品名は #fap-rank-N へのアンカーリンク（作品カードのH2に同IDを付ける想定）
    // -----------------------------------------------------------------------
    private function build_ranking_summary_table(array $items): string {
        $rows = '';
        foreach ($items as $i => $item) {
            $rank  = $i + 1;
            $title = mb_strimwidth(wp_strip_all_tags((string) ($item->title ?? '')), 0, 36, '…');

            // 価格セル（セール中は割引価格+OFF率を強調）
            $list = isset($item->prices->list_price) ? (string) $item->prices->list_price
                  : (isset($item->prices->price) ? (string) $item->prices->price : '');
            if ($this->api->is_on_sale($item)) {
                $sale  = (string) $item->prices->price;
                $off   = fap_discount_rate($list, $sale);
                $price = '<span class="fsl-rank-table-sale">' . esc_html($sale) . '円'
                       . ($off ? '<small>（' . esc_html($off) . '）</small>' : '') . '</span>';
            } else {
                $price = $list !== '' ? esc_html($list) . '円' : '－';
            }

            $avg    = (float) ($item->review->average ?? 0);
            $rating = $avg > 0 ? '★' . esc_html(number_format($avg, 1)) : '－';

            $rows .= '<tr>'
                   . '<td class="fsl-rank-table-no">' . $rank . '</td>'
                   . '<td class="fsl-rank-table-title"><a href="#fap-rank-' . $rank . '">' . esc_html($title) . '</a></td>'
                   . '<td>' . $price . '</td>'
                   . '<td>' . $rating . '</td>'
                   . '</tr>' . "\n";
        }

        return '<div class="fsl-rank-table-wrap"><table class="fsl-rank-table">'
             . '<thead><tr><th>順位</th><th>作品</th><th>価格</th><th>評価</th></tr></thead>'
             . '<tbody>' . "\n" . $rows . '</tbody></table></div>';
    }

    // -----------------------------------------------------------------------
    // 1アイテム分をテンプレートで描画（ランキングテンプレート用）
    // -----------------------------------------------------------------------
    // 順位変動を計算して ['type'=>'up'|'down'|'new'|'same', 'diff'=>int] を返す
    private function calc_rank_change(string $cid, int $current_rank, array $prev_cids): array {
        if (empty($prev_cids) || !$cid) return ['type' => 'same', 'diff' => 0];
        $prev_index = array_search($cid, $prev_cids, true);
        if ($prev_index === false) return ['type' => 'new', 'diff' => 0];
        $diff = ((int) $prev_index + 1) - $current_rank;
        if ($diff > 0) return ['type' => 'up',   'diff' => $diff];
        if ($diff < 0) return ['type' => 'down', 'diff' => abs($diff)];
        return ['type' => 'same', 'diff' => 0];
    }

    // 順位変動バッジHTMLを生成
    private function build_rank_change_badge(string $cid, int $current_rank, array $prev_cids): string {
        $change = $this->calc_rank_change($cid, $current_rank, $prev_cids);
        $style  = 'display:inline-block;font-size:11px;font-weight:bold;padding:1px 7px;border-radius:999px;margin-left:6px;vertical-align:middle;color:#fff;';
        switch ($change['type']) {
            case 'new':  return '<span style="' . $style . 'background:#f59e0b;">NEW</span>';
            case 'up':   return '<span style="' . $style . 'background:#16a34a;">▲' . $change['diff'] . '</span>';
            case 'down': return '<span style="' . $style . 'background:#dc2626;">▼' . $change['diff'] . '</span>';
            default:     return '';
        }
    }

    private function replace_ranking_item(string $tpl, object $item, int $rank, string $service, bool $fetch_desc, array $prev_cids = [], int $total = 0): string {
        $medals     = ['🥇', '🥈', '🥉'];
        $medal      = $medals[$rank - 1] ?? '';
        $rank_label = $medal ? $medal . ' 第' . $rank . '位' : '第' . $rank . '位';

        $title   = (string) ($item->title       ?? '');
        $aff_url = (string) ($item->affiliateURL ?? '');
        $cid     = (string) ($item->content_id  ?? '');

        // セール状態と条件分岐タグ（[fap_if_sale]〜[/fap_if_sale] / [fap_if_not_sale]〜）
        $on_sale    = $this->api->is_on_sale($item);
        $sale_price = ($on_sale && isset($item->prices->price)) ? (string) $item->prices->price : '';
        if ($on_sale) {
            $tpl = str_replace(['[fap_if_sale]', '[/fap_if_sale]'], '', $tpl);
            $tpl = preg_replace('/\[fap_if_not_sale\].*?\[\/fap_if_not_sale\]/s', '', $tpl);
        } else {
            $tpl = str_replace(['[fap_if_not_sale]', '[/fap_if_not_sale]'], '', $tpl);
            $tpl = preg_replace('/\[fap_if_sale\].*?\[\/fap_if_sale\]/s', '', $tpl);
        }

        $img = (string) ($item->imageURL->large ?? $item->imageURL->small ?? '');
        if (str_contains($img, 'jm.jpg')) $img = str_replace('jm.jpg', 'jp.jpg', $img);
        if (str_contains($img, 'js.jpg')) $img = str_replace('js.jpg', 'jp.jpg', $img);

        $actress_arr  = $this->extract($item, 'actress');
        $genre_arr    = $this->extract($item, 'genre');
        $maker_arr    = $this->extract($item, 'maker');
        $director_arr = $this->extract($item, 'director');
        $series_arr   = $this->extract($item, 'series');
        $label_arr    = $this->extract($item, 'label');
        $author_arr   = $this->extract($item, 'author');

        $price = '';
        if (isset($item->prices->list_price)) $price = (string) $item->prices->list_price;
        elseif (isset($item->prices->price))  $price = (string) $item->prices->price;

        $avg = (float) ($item->review->average ?? 0);
        $cnt = (int)   ($item->review->count   ?? 0);

        // [package] HTML
        $pkg_html = '';
        if ($img) {
            $img_tag  = '<img src="' . esc_url($img) . '" alt="' . esc_attr('第' . $rank . '位：' . $title) . '" loading="lazy" decoding="async" style="max-width:180px;height:auto;border-radius:6px;" />';
            $pkg_html = $aff_url
                ? '<a href="' . esc_url($aff_url) . '" target="_blank" rel="nofollow sponsored noopener noreferrer">' . $img_tag . '</a>'
                : $img_tag;
        }

        // [aff-button] HTML
        $btn_html = $aff_url
            ? '<p style="margin:12px 0;text-align:center;"><a href="' . esc_url($aff_url) . '" target="_blank" rel="nofollow sponsored noopener noreferrer" style="display:inline-block;padding:14px 28px;background:#e0306e;color:#fff;text-decoration:none;border-radius:10px;font-weight:bold;font-size:15px;">FANZAで確認する →</a></p>'
            : '';

        // [detail-table] HTML
        $detail_d = [
            'actress_arr'  => $actress_arr,
            'actor_arr'    => [],
            'director_arr' => $director_arr,
            'series_arr'   => $series_arr,
            'maker_arr'    => $maker_arr,
            'label_arr'    => $label_arr,
            'author_arr'   => $author_arr,
            'genre_arr'    => $genre_arr,
            'release_date' => isset($item->date) ? substr((string) $item->date, 0, 10) : '',
            'volume'       => (string) ($item->volume ?? ''),
            'price'        => $price,
        ];

        // [comment] 説明文
        $comment_html = '';
        if ($fetch_desc && $cid) {
            $desc = '';
            if ($service === 'digital') {
                $desc = $this->api->fetch_description_graphql($cid);
            }
            if (!$desc) {
                $item_url = (string) ($item->URL ?? '');
                if ($item_url) {
                    $desc = $this->extract_comment_text($this->api->fetch_page($item_url), $service);
                }
            }
            if ($desc) {
                $short = mb_strimwidth(wp_strip_all_tags($desc), 0, 200, '…');
                $comment_html = '<p style="font-size:14px;color:#374151;line-height:1.75;">' . esc_html($short) . '</p>';
            }
        }

        return strtr($tpl, [
            '[rank]'           => $rank,
            // 記事全体の情報（タイトル・冒頭・締めと同じ変数。各作品テンプレートでも使える）
            '[year]'           => date_i18n('Y'),
            '[month]'          => date_i18n('n'),
            '[day]'            => date_i18n('j'),
            '[date]'           => date_i18n('Y年n月j日'),
            '[count]'          => (string) $total,
            '[floor]'          => esc_html((string) $this->ropt('flr_name', '')),
            '[medal]'          => $medal,
            '[rank-label]'     => esc_html($rank_label),
            '[rank-change]'    => $this->build_rank_change_badge($cid, $rank, $prev_cids),
            '[title]'          => esc_html($title),
            '[aff-url]'        => esc_url($aff_url),
            '[package]'        => $pkg_html,
            '[actress]'        => esc_html(implode('・', array_column($actress_arr, 'name'))),
            '[maker]'          => esc_html(implode('・', array_column($maker_arr,   'name'))),
            '[genre]'          => esc_html(implode(' ',  array_column($genre_arr,   'name'))),
            '[price]'          => esc_html($price),
            '[sale-price]'     => esc_html($sale_price),
            '[discount-rate]'  => esc_html(fap_discount_rate($price, $sale_price)),
            '[review-average]' => $avg > 0 ? esc_html(number_format($avg, 1)) : '',
            '[review-count]'   => $cnt > 0 ? esc_html((string) $cnt) : '',
            '[aff-button]'     => $btn_html,
            '[detail-table]'   => $this->build_detail($detail_d, 'table'),
            '[comment]'        => $comment_html,
        ]);
    }

    // -----------------------------------------------------------------------
    // ランキング記事プレビュー（AJAX用・説明文取得スキップ）
    // -----------------------------------------------------------------------
    public function preview_ranking(array $items, string $service, int $slot = 1): string {
        $this->ranking_slot = max(1, min(4, $slot));
        try {
            return $this->build_ranking_content($items, $service, [], true);
        } finally {
            $this->ranking_slot = 1;
        }
    }

    // -----------------------------------------------------------------------
    // コラージュ画像生成（GD使用。1〜3位の画像を横並びに合成）
    // GD非対応サーバーでは1位画像をそのまま使用するフォールバック付き
    // -----------------------------------------------------------------------
    private function build_collage_image(array $items, int $post_id): int {
        $img_urls = [];
        for ($i = 0; $i < min(3, count($items)); $i++) {
            $url = (string) ($items[$i]->imageURL->large ?? $items[$i]->imageURL->small ?? '');
            if (str_contains($url, 'jm.jpg')) $url = str_replace('jm.jpg', 'jp.jpg', $url);
            if (str_contains($url, 'js.jpg')) $url = str_replace('js.jpg', 'jp.jpg', $url);
            if ($url) $img_urls[] = ['url' => $url, 'title' => (string) ($items[$i]->title ?? '')];
        }

        if (empty($img_urls)) return 0;

        // GD非対応またはURL1件 → 1位画像をそのまま使用
        if (!extension_loaded('gd') || count($img_urls) < 2) {
            return $this->download_image($img_urls[0]['url'], $post_id, '第1位：' . $img_urls[0]['title']);
        }

        // 各画像をダウンロードしてGDに変換
        $target_h = 300;
        $gap      = 6;
        $gd_list  = [];

        foreach ($img_urls as $info) {
            $tmp = download_url($info['url'], 20);
            if (is_wp_error($tmp)) continue;

            $ext = strtolower(pathinfo(parse_url($info['url'], PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg';
            if ($ext === 'png') {
                $src = @imagecreatefrompng($tmp);
            } elseif ($ext === 'webp') {
                $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : null;
            } else {
                $src = @imagecreatefromjpeg($tmp);
            }
            @unlink($tmp);
            if (!$src) continue;

            $orig_w = imagesx($src);
            $orig_h = imagesy($src);
            $ratio  = $orig_h > 0 ? $target_h / $orig_h : 1;
            $new_w  = max(1, (int) ($orig_w * $ratio));
            $scaled = imagecreatetruecolor($new_w, $target_h);
            imagecopyresampled($scaled, $src, 0, 0, 0, 0, $new_w, $target_h, $orig_w, $orig_h);
            imagedestroy($src);
            $gd_list[] = ['img' => $scaled, 'w' => $new_w];
        }

        // GD変換が1件以下ならフォールバック
        if (count($gd_list) < 2) {
            foreach ($gd_list as $g) imagedestroy($g['img']);
            return $this->download_image($img_urls[0]['url'], $post_id, '第1位：' . $img_urls[0]['title']);
        }

        // キャンバスに横並びで合成
        $total_w = array_sum(array_column($gd_list, 'w')) + $gap * (count($gd_list) - 1);
        $canvas  = imagecreatetruecolor($total_w, $target_h);
        $white   = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        $x = 0;
        foreach ($gd_list as $g) {
            imagecopy($canvas, $g['img'], $x, 0, 0, 0, $g['w'], $target_h);
            imagedestroy($g['img']);
            $x += $g['w'] + $gap;
        }

        // 一時ファイルに書き出してWordPressメディアへアップロード
        $tmp_path = wp_tempnam('fap_collage') . '.jpg';
        imagejpeg($canvas, $tmp_path, 90);
        imagedestroy($canvas);

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $id = media_handle_sideload(
            ['name' => 'fap-ranking-collage-' . time() . '.jpg', 'tmp_name' => $tmp_path],
            $post_id,
            'FANZAランキング コラージュ'
        );
        @unlink($tmp_path);

        return is_wp_error($id) ? 0 : (int) $id;
    }
}
