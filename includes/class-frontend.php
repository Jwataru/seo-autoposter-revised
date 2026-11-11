<?php
defined('ABSPATH') || exit;

/**
 * フロント表示機能
 * ・アフィリエイト注記（PR表記）
 * ・スマホ追従CTAバー
 * ・フロント用CSS/JSの読み込み（FAP生成記事のみ）
 */
class FAP_Frontend {

    public function __construct() {
        add_action('wp_enqueue_scripts',       [$this, 'enqueue']);
        add_filter('the_content',              [$this, 'filter_content'], 20);
        add_action('wp_footer',                [$this, 'render_sticky_cta']);
        add_filter('post_class',               [$this, 'add_sale_post_class'], 10, 3);
        add_action('wp_head',                  [$this, 'print_sale_badge_css'], 20);
        add_action('wp_ajax_fap_track_click',  [$this, 'ajax_track_click']);
        add_action('wp_ajax_nopriv_fap_track_click', [$this, 'ajax_track_click']);
        add_action('wp_ajax_fap_track_view',   [$this, 'ajax_track_view']);
        add_action('wp_ajax_nopriv_fap_track_view',  [$this, 'ajax_track_view']);
        add_shortcode('fap_sale_price',    [$this, 'sc_sale_price']);
        add_shortcode('fap_discount_rate', [$this, 'sc_discount_rate']);
        add_shortcode('fap_if_sale',       [$this, 'sc_if_sale']);
        add_shortcode('fap_if_not_sale',   [$this, 'sc_if_not_sale']);
        add_shortcode('fap_if_review',     [$this, 'sc_if_review']);
        add_shortcode('fap_if_no_review',  [$this, 'sc_if_no_review']);
        add_shortcode('fap_sale_history',  [$this, 'sc_sale_history']);
    }

    // -----------------------------------------------------------------------
    // トップページの記事カード（アイキャッチ上部）に「ただいまセール中」バッジを表示
    // ・セール中の記事に post_class で fap-on-sale を付ける
    //   （「セール中カテゴリ」がONならそのカテゴリ、OFFなら _fap_sale_price メタで判定）
    // ・バッジ自体は CSS（::before）で描画する。表示は body.home（トップ）限定
    // -----------------------------------------------------------------------
    public function add_sale_post_class(array $classes, $class = '', $post_id = 0): array {
        if (fap_opt('sale_badge_on', '0') !== '1' || !$post_id) return $classes;

        if (fap_opt('sale_category_on', '0') === '1') {
            $name = trim((string) fap_opt('sale_category_name', 'セール中')) ?: 'セール中';
            $term = get_term_by('name', $name, 'category');
            $on   = $term && has_category((int) $term->term_id, $post_id);
        } else {
            $on = (string) get_post_meta($post_id, '_fap_sale_price', true) !== '';
        }
        if ($on) $classes[] = 'fap-on-sale';
        return $classes;
    }

    public function print_sale_badge_css(): void {
        if (fap_opt('sale_badge_on', '0') !== '1' || !is_home() && !is_front_page()) return;

        $text = trim((string) fap_opt('sale_badge_text', 'ただいまセール中')) ?: 'ただいまセール中';
        $text = addcslashes(str_replace(["\r", "\n"], '', $text), "\"\\");
        $bg   = sanitize_hex_color((string) fap_opt('sale_badge_bg', '#e53935')) ?: '#e53935';
        $fg   = sanitize_hex_color((string) fap_opt('sale_badge_color', '#ffffff')) ?: '#ffffff';

        echo "<style id=\"fap-sale-badge-css\">\n"
           . "body.home .fap-on-sale .entry-card-thumb,body.home .fap-on-sale .card-thumb,body.home .fap-on-sale .post-thumbnail{position:relative;}\n"
           . "body.home .fap-on-sale .entry-card-thumb::before,body.home .fap-on-sale .card-thumb::before,body.home .fap-on-sale .post-thumbnail::before{"
           . "content:\"{$text}\";position:absolute;bottom:8px;left:8px;transform-origin:left bottom;z-index:5;"
           . "padding:5px 14px;border:2px solid #fff;border-radius:999px;background:{$bg};color:{$fg};"
           . "font-size:13px;font-weight:800;line-height:1.4;white-space:nowrap;letter-spacing:.05em;"
           . "box-shadow:0 3px 8px rgba(0,0,0,.35);pointer-events:none;animation:fap-sale-pop 1.6s ease-in-out infinite;}\n"
           . "@keyframes fap-sale-pop{0%,100%{transform:scale(1);}50%{transform:scale(1.08);}}\n"
           . "@media (max-width:480px){body.home .fap-on-sale .entry-card-thumb::before,body.home .fap-on-sale .card-thumb::before,body.home .fap-on-sale .post-thumbnail::before{font-size:11px;padding:3px 10px;bottom:6px;left:6px;}}\n"
           . "@media (prefers-reduced-motion:reduce){body.home .fap-on-sale .entry-card-thumb::before,body.home .fap-on-sale .card-thumb::before,body.home .fap-on-sale .post-thumbnail::before{animation:none;}}\n"
           . "</style>\n";
    }

    // -----------------------------------------------------------------------
    // セール表示ショートコード
    // 本文テンプレートの [sale-price] / [discount-rate] はこれらに展開される。
    // セール状態は投稿時とセール確認（run_sale_check）が更新する
    // _fap_sale_price メタを参照するため、表示は常に最新のセール状態になる。
    // -----------------------------------------------------------------------
    private function current_sale_price(): string {
        $id = get_the_ID();
        return $id ? fap_clean_price((string) get_post_meta($id, '_fap_sale_price', true)) : '';
    }

    public function sc_sale_price(): string {
        return esc_html($this->current_sale_price());
    }

    public function sc_discount_rate(): string {
        $sale = $this->current_sale_price();
        if ($sale === '') return '';
        $list = (string) get_post_meta(get_the_ID(), '_fap_price', true);
        return esc_html(fap_discount_rate($list, $sale));
    }

    public function sc_if_sale($atts = [], $content = ''): string {
        return $this->current_sale_price() !== '' ? do_shortcode((string) $content) : '';
    }

    public function sc_if_not_sale($atts = [], $content = ''): string {
        return $this->current_sale_price() === '' ? do_shortcode((string) $content) : '';
    }

    // レビュー有無の条件表示（レビュー0件の新作で★表示が崩れるのを防ぐ）
    // _fap_review_cnt はセール検知の本文再生成時に更新されるため、
    // 発売直後に0件でも後日レビューが付けば再生成時に表示が切り替わる
    private function current_review_cnt(): int {
        $id = get_the_ID();
        return $id ? (int) get_post_meta($id, '_fap_review_cnt', true) : 0;
    }

    public function sc_if_review($atts = [], $content = ''): string {
        return $this->current_review_cnt() > 0 ? do_shortcode((string) $content) : '';
    }

    public function sc_if_no_review($atts = [], $content = ''): string {
        return $this->current_review_cnt() <= 0 ? do_shortcode((string) $content) : '';
    }

    // -----------------------------------------------------------------------
    // セール履歴サマリー（[fap_sale_history] / 本文タグ [sale-history]）
    // FAP_Sale_History::stats() から「過去N回・平均XX%OFF・前回セール月」を表示。
    // 履歴が1件も無ければ何も出さない（記録開始前の記事で空ブロックを出さないため）。
    // -----------------------------------------------------------------------
    public function sc_sale_history($atts = []): string {
        $id = get_the_ID();
        if (!$id) return '';
        $cid = (string) get_post_meta($id, '_fap_cid', true);
        if ($cid === '') return '';

        $stats = FAP_Sale_History::stats($cid);
        $count = (int) ($stats['count'] ?? 0);
        if ($count < 1) return ''; // 履歴が無ければ非表示

        $avg  = (int) ($stats['avg_off'] ?? 0);
        $last = (string) ($stats['last_start'] ?? '');

        $parts = ['この作品は過去 <b>' . $count . '回</b> セール実施'];
        if ($avg > 0) {
            $parts[] = '平均 <b>' . $avg . '% OFF</b>';
        }
        if ($last !== '') {
            $parts[] = '前回セール <b>' . esc_html(mysql2date('Y年n月', $last)) . '</b>';
        }

        return '<div class="fap-sale-history">'
             . '<span class="fap-sale-history-ttl">★ セール履歴</span>'
             . '<span class="fap-sale-history-body">' . implode('　/　', $parts) . '</span>'
             . '</div>';
    }

    // この投稿がFAP生成記事か（単一表示・メインループのみ）
    private function is_fap_single(): bool {
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) return false;
        $id = get_the_ID();
        return $id && get_post_meta($id, '_fap_cid', true) !== '';
    }

    // FAP記事ページ全般の判定（フッターCTA・アセット用、ループ外でも判定）
    private function is_fap_page(): bool {
        if (!is_singular('post')) return false;
        $id = get_queried_object_id();
        return $id && get_post_meta($id, '_fap_cid', true) !== '';
    }

    // -----------------------------------------------------------------------
    // アセット
    // -----------------------------------------------------------------------
    public function enqueue(): void {
        if (!$this->is_fap_page()) return;

        wp_enqueue_style('fap-front', FAP_URL . 'assets/front.css', [], FAP_VERSION);

        // CTR計測またはスティッキーCTAが有効なら front.js を読み込む
        $need_js = fap_opt('feat_sticky', '0') === '1'
                || (FAP_License::is('premium') && fap_opt('ctr_track_on', '0') === '1');
        if ($need_js) {
            wp_enqueue_script('fap-front', FAP_URL . 'assets/front.js', [], FAP_VERSION, true);
            $post_id = get_queried_object_id();
            wp_localize_script('fap-front', 'fapData', [
                'ajaxUrl'  => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('fap_track'),
                'postId'   => $post_id,
                'trackOn'  => FAP_License::is('premium') && fap_opt('ctr_track_on', '0') === '1' ? '1' : '0',
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // 本文フィルター（アフィリエイト注記）
    // -----------------------------------------------------------------------
    public function filter_content(string $content): string {
        if (!$this->is_fap_single()) return $content;

        $prefix = '';

        // アフィリエイト注記（PR表記）
        if (fap_opt('feat_notice', '0') === '1') {
            $text = trim((string) fap_opt('feat_notice_text', ''));
            if ($text !== '') {
                $prefix .= '<div class="fap-notice">' . esc_html($text) . '</div>';
            }
        }

        return $prefix . $content;
    }

    // -----------------------------------------------------------------------
    // スマホ追従CTAバー
    // -----------------------------------------------------------------------
    public function render_sticky_cta(): void {
        if (!FAP_License::is('premium')) return;
        if (fap_opt('feat_sticky', '0') !== '1') return;
        if (!$this->is_fap_page()) return;

        $id      = get_queried_object_id();
        $aff_url = (string) get_post_meta($id, '_fap_affiliate_url', true);
        if (!$aff_url) return;

        $text = trim((string) fap_opt('feat_sticky_text', '')) ?: 'FANZAで見る';

        // デザイン設定（背景・文字色・角丸）
        $bg     = fap_opt('feat_sticky_bg',    '#e0306e') ?: '#e0306e';
        $bg2    = fap_opt('feat_sticky_bg2',   '') ;            // 任意のグラデ2色目
        $color  = fap_opt('feat_sticky_color', '#ffffff') ?: '#ffffff';
        $border = fap_opt('feat_sticky_border', '');          // 枠線色（空欄で枠なし）
        $radii  = ['none' => '0', 'small' => '6px', 'medium' => '12px', 'large' => '20px', 'pill' => '999px'];
        $radius = $radii[fap_opt('feat_sticky_radius', 'medium')] ?? '12px';

        $bg_css = $bg2
            ? 'background:linear-gradient(120deg,' . esc_attr($bg) . ',' . esc_attr($bg2) . ');'
            : 'background:' . esc_attr($bg) . ';';
        $border_css = $border ? 'border:2px solid ' . esc_attr($border) . ';' : '';
        $style  = $bg_css . 'color:' . esc_attr($color) . ';' . $border_css . 'border-radius:' . $radius . ';';

        echo '<div class="fap-sticky-cta" id="fap-sticky-cta">'
           . '<a style="' . $style . '" href="' . esc_url($aff_url) . '" target="_blank" rel="nofollow sponsored noopener noreferrer">'
           . esc_html($text) . ' <span aria-hidden="true">›</span></a>'
           . '</div>';
    }

    // -----------------------------------------------------------------------
    // AJAX: クリックカウント（ノンログインユーザーも対象）
    // -----------------------------------------------------------------------
    public function ajax_track_click(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'fap_track')) {
            wp_send_json_error('invalid nonce');
        }
        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('no post_id');
        if (!get_post_meta($post_id, '_fap_cid', true)) wp_send_json_error('invalid post');

        $ip       = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        $rate_key = 'fap_click_' . md5($ip . '_' . $post_id);
        if (get_transient($rate_key)) {
            wp_send_json_success();
        }
        set_transient($rate_key, 1, HOUR_IN_SECONDS);

        $count = (int) get_post_meta($post_id, '_fap_click_count', true);
        update_post_meta($post_id, '_fap_click_count', $count + 1);
        wp_send_json_success();
    }

    // -----------------------------------------------------------------------
    // AJAX: ページビューカウント（ノンログインユーザーも対象）
    // -----------------------------------------------------------------------
    public function ajax_track_view(): void {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'fap_track')) {
            wp_send_json_error('invalid nonce');
        }
        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('no post_id');
        if (!get_post_meta($post_id, '_fap_cid', true)) wp_send_json_error('invalid post');

        $ip       = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        $rate_key = 'fap_view_' . md5($ip . '_' . $post_id);
        if (get_transient($rate_key)) {
            wp_send_json_success();
        }
        set_transient($rate_key, 1, HOUR_IN_SECONDS);

        $count = (int) get_post_meta($post_id, '_fap_view_count', true);
        update_post_meta($post_id, '_fap_view_count', $count + 1);
        wp_send_json_success();
    }
}
