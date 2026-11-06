<?php
defined('ABSPATH') || exit;

/**
 * 管理画面 UI・フォーム処理・AJAX
 */
class FAP_Admin {

    // -----------------------------------------------------------------------
    // 初期化
    // -----------------------------------------------------------------------
    public function __construct() {
        add_action('admin_menu',            [$this, 'register_menus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_notices',         [$this, 'show_notices']);

        // フォームハンドラ
        add_action('admin_post_fap_save_api',       [$this, 'handle_save_api']);
        add_action('admin_post_fap_save_scheduler', [$this, 'handle_save_scheduler']);
        add_action('admin_post_fap_save_slot',      [$this, 'handle_save_slot']);
        add_action('admin_post_fap_save_seo',       [$this, 'handle_save_seo']);
        add_action('admin_post_fap_save_features',  [$this, 'handle_save_features']);
        add_action('admin_post_fap_quick_post',     [$this, 'handle_quick_post']);
        add_action('admin_post_fap_cid_post',       [$this, 'handle_cid_post']);
        add_action('admin_post_fap_clear_log',      [$this, 'handle_clear_log']);
        add_action('admin_post_fap_export',             [$this, 'handle_export']);
        add_action('admin_post_fap_import',             [$this, 'handle_import']);
        add_action('admin_post_fap_deactivate_license', [$this, 'handle_deactivate_license']);
        add_action('admin_post_fap_save_ranking',       [$this, 'handle_save_ranking']);
        add_action('admin_post_fap_ranking_post',       [$this, 'handle_ranking_post']);
        add_action('admin_post_fap_sale_check',         [$this, 'handle_sale_check']);
        add_action('admin_post_fap_save_ctr',           [$this, 'handle_save_ctr']);
        add_action('admin_post_fap_save_ai',            [$this, 'handle_save_ai']);

        // AJAX
        add_action('wp_ajax_fap_test_api',         [$this, 'ajax_test_api']);
        add_action('wp_ajax_fap_refresh_floors',   [$this, 'ajax_refresh_floors']);
        add_action('wp_ajax_fap_preview',          [$this, 'ajax_preview']);
        add_action('wp_ajax_fap_verify_license',   [$this, 'ajax_verify_license']);
        add_action('wp_ajax_fap_ranking_preview',      [$this, 'ajax_ranking_preview']);
        add_action('wp_ajax_fap_test_ai',              [$this, 'ajax_test_ai']);
        add_action('wp_ajax_fap_manual_ai_rewrite',    [$this, 'ajax_manual_ai_rewrite']);
        add_action('wp_ajax_fap_discontinued_check',   [$this, 'ajax_discontinued_check']);
        add_action('wp_ajax_fap_discontinued_trash',   [$this, 'ajax_discontinued_trash']);
        add_action('admin_post_fap_export_ctr',        [$this, 'handle_export_ctr']);
        add_filter('post_row_actions',                 [$this, 'add_ai_rewrite_row_action'], 10, 2);
        add_filter('default_title',   [$this, 'default_post_title_for_template']);
        add_filter('default_content', [$this, 'default_post_content_for_template']);
    }

    // -----------------------------------------------------------------------
    // メニュー登録
    // -----------------------------------------------------------------------
    public function register_menus() {
        add_menu_page(
            'FANZA SEO Autoposter',
            'FANZA Autoposter',
            'manage_options',
            FAP_SLUG,
            array($this, 'page_dashboard'),
            'dashicons-rss',
            100   // 他プラグインと同様、メニュー下部（設定より下）に配置
        );

        // ダッシュボード・API・スケジューラーサブメニュー
        add_submenu_page(FAP_SLUG, 'ダッシュボード', 'ダッシュボード', 'manage_options', FAP_SLUG,            array($this, 'page_dashboard'));
        add_submenu_page(FAP_SLUG, 'API設定',        'API設定',        'manage_options', FAP_SLUG . '-api',   array($this, 'page_api'));
        add_submenu_page(FAP_SLUG, '自動実行設定',   '自動実行設定',   'manage_options', FAP_SLUG . '-sched', array($this, 'page_scheduler'));

        // スロット：メニューには「スロット」1つだけ表示。残りはURLのみ登録（ページ内ナビで切替）
        add_submenu_page(FAP_SLUG, '投稿スロット', 'スロット', 'manage_options', FAP_SLUG . '-slot1', array($this, 'page_slot1'));
        add_submenu_page(null, '投稿スロット 2', '', 'manage_options', FAP_SLUG . '-slot2', array($this, 'page_slot2'));
        add_submenu_page(null, '投稿スロット 3', '', 'manage_options', FAP_SLUG . '-slot3', array($this, 'page_slot3'));
        add_submenu_page(null, '投稿スロット 4', '', 'manage_options', FAP_SLUG . '-slot4', array($this, 'page_slot4'));
        add_submenu_page(null, '投稿スロット 5', '', 'manage_options', FAP_SLUG . '-slot5', array($this, 'page_slot5'));
        add_submenu_page(null, '投稿スロット 6', '', 'manage_options', FAP_SLUG . '-slot6', array($this, 'page_slot6'));
        add_submenu_page(null, '投稿スロット 7', '', 'manage_options', FAP_SLUG . '-slot7', array($this, 'page_slot7'));
        add_submenu_page(null, '投稿スロット 8', '', 'manage_options', FAP_SLUG . '-slot8', array($this, 'page_slot8'));

        add_submenu_page(FAP_SLUG, 'SEO設定',        'SEO設定',        'manage_options', FAP_SLUG . '-seo',      array($this, 'page_seo'));
        add_submenu_page(FAP_SLUG, '表示・機能設定', '表示・機能',     'manage_options', FAP_SLUG . '-features',  array($this, 'page_features'));
        add_submenu_page(FAP_SLUG, '変数タグ一覧',   '変数タグ',       'manage_options', FAP_SLUG . '-vartags',  array($this, 'page_vartags'));
        add_submenu_page(FAP_SLUG, '設定の保存/復元', '設定の保存/復元','manage_options', FAP_SLUG . '-backup',   array($this, 'page_backup'));
        add_submenu_page(FAP_SLUG, 'ランキング記事',  'ランキング',     'manage_options', FAP_SLUG . '-ranking',  array($this, 'page_ranking'));
        add_submenu_page(FAP_SLUG, 'CTR分析',        'CTR分析',        'manage_options', FAP_SLUG . '-ctr',      array($this, 'page_ctr'));
        add_submenu_page(FAP_SLUG, 'AI設定',         'AI設定',         'manage_options', FAP_SLUG . '-ai',       array($this, 'page_ai'));
        add_submenu_page(FAP_SLUG, '投稿履歴',       '投稿履歴',       'manage_options', FAP_SLUG . '-history',  array($this, 'page_history'));
        add_submenu_page(FAP_SLUG, 'ライセンス',     'ライセンス',     'manage_options', FAP_SLUG . '-license',  array($this, 'page_license'));
        add_submenu_page(FAP_SLUG, '実行ログ',       '実行ログ',       'manage_options', FAP_SLUG . '-log',      array($this, 'page_log'));
    }

    // スロットページ個別メソッド（クロージャー回避）
    public function page_slot1() { $this->page_slot(1); }
    public function page_slot2() { $this->page_slot(2); }
    public function page_slot3() { $this->page_slot(3); }
    public function page_slot4() { $this->page_slot(4); }
    public function page_slot5() { $this->page_slot(5); }
    public function page_slot6() { $this->page_slot(6); }
    public function page_slot7() { $this->page_slot(7); }
    public function page_slot8() { $this->page_slot(8); }

    // -----------------------------------------------------------------------
    // アセット
    // -----------------------------------------------------------------------
    public function enqueue_assets(string $hook): void {
        if (!str_contains($hook, FAP_SLUG)) return;
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style('fap-admin', FAP_URL . 'assets/admin.css', [], FAP_VERSION);
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_script(
            'fap-admin',
            FAP_URL . 'assets/admin.js',
            ['jquery', 'wp-color-picker'],
            FAP_VERSION,
            true
        );
        wp_localize_script('fap-admin', 'fapData', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('fap_ajax_nonce'),
            'fap_action_nonce' => wp_create_nonce(FAP_NONCE),
        ]);
    }

    // -----------------------------------------------------------------------
    // 管理画面通知
    // -----------------------------------------------------------------------
    public function show_notices(): void {
        $screen = get_current_screen();
        if (!$screen || !str_contains($screen->id, FAP_SLUG)) return;

        if ($msg = get_transient('fap_notice_success')) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            delete_transient('fap_notice_success');
        }
        if ($msg = get_transient('fap_notice_error')) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            delete_transient('fap_notice_error');
        }
        if ($msg = get_transient('fap_notice_info')) {
            echo '<div class="notice notice-info is-dismissible"><p><strong>' . wp_kses_post($msg) . '</strong></p></div>';
            delete_transient('fap_notice_info');
        }
    }

    // -----------------------------------------------------------------------
    // ページ：ダッシュボード
    // -----------------------------------------------------------------------
    public function page_dashboard(): void {
        if (!current_user_can('manage_options')) wp_die('権限がありません');

        // 統計
        global $wpdb;
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status IN ('publish','draft')
                 AND post_name IN (SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s)",
                '_fap_cid'
            )
        );
        $today_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key='_fap_cid'
                 WHERE DATE(p.post_date) = %s AND p.post_status IN ('publish','draft')",
                current_time('Y-m-d')
            )
        );
        $auto_on    = fap_opt('auto_on', '0') === '1';
        $api_ok     = fap_opt('api_verified', '0') === '1';
        $is_pro     = FAP_License::is('pro');
        $hour_slots = (array) fap_opt('hour_slots', []);
        $next_run   = FAP_Scheduler::next_run_label();

        // スロット状態
        $slot_statuses   = [];
        $slot_ready      = false;
        $any_active_in_schedule = false;
        for ($n = 1; $n <= FAP_SLOTS; $n++) {
            $floor     = fap_slot_opt($n, 'flr_name', '');
            $hits      = (int) fap_slot_opt($n, 'hits', 0);
            $s_active  = fap_slot_opt($n, 'active', '0') === '1';
            $in_sched  = false;
            foreach ($hour_slots as $slots_in_hour) {
                if (in_array((string) $n, array_map('strval', (array) $slots_in_hour), true)) {
                    $in_sched = true;
                    break;
                }
            }
            $slot_statuses[$n] = [
                'active'    => $s_active,
                'in_sched'  => $in_sched,
                'floor'     => $floor,
                'hits'      => $hits,
            ];
            if (fap_slot_opt($n, 'floor', '') && $hits > 0) $slot_ready = true;
            if ($s_active && $in_sched) $any_active_in_schedule = true;
        }

        // セットアップウィザード用：各ステップの完了判定
        $tpl_exists = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type='post' AND post_status='draft' AND post_title LIKE 'FAP テンプレート%'"
        );
        // ステップ5：Proプランは自動実行ON、Freeは手動投稿
        if ($is_pro) {
            $step5 = ['自動実行をON',   $auto_on,                 admin_url('admin.php?page=' . FAP_SLUG . '-sched')];
        } else {
            $step5 = ['記事を手動で投稿', $slot_ready && $tpl_exists > 0, admin_url('admin.php?page=' . FAP_SLUG . '-slot1')];
        }
        $steps = [
            ['API設定を入力',     $api_ok,                              admin_url('admin.php?page=' . FAP_SLUG . '-api')],
            ['フロアを取得',       !empty(FAP_API::get_floor_list()),    admin_url('admin.php?page=' . FAP_SLUG . '-api')],
            ['スロットを設定',     $slot_ready,                          admin_url('admin.php?page=' . FAP_SLUG . '-slot1')],
            ['テンプレート作成',   $tpl_exists > 0,                      admin_url('edit.php?post_status=draft&post_type=post')],
            $step5,
        ];
        $done_count = count(array_filter($steps, fn($s) => $s[1]));
        $all_done   = $done_count === count($steps);

        $this->wrap_start('ダッシュボード 🏠');
        ?>
        <div class="fap-dashboard">

            <!-- セットアップガイド -->
            <div class="fap-card fap-guide<?= $all_done ? ' is-done' : '' ?>">
                <h2>🚀 セットアップガイド <span class="fap-guide__count"><?= (int) $done_count ?>/<?= count($steps) ?> 完了</span></h2>
                <?php if ($all_done): ?>
                    <p style="margin:0;color:#15803d;font-weight:600;">✓ セットアップ完了！自動投稿の準備が整っています。</p>
                <?php else: ?>
                    <p class="description" style="margin-top:0;">下記の手順を順番に進めると自動投稿を開始できます。<br>
                    ③「スロットを設定」はフロアと投稿件数を保存すると完了です。④「テンプレート作成」はタイトルが <code>FAP テンプレート</code>、ステータス「下書き」の投稿を作成すると完了です。</p>
                <?php endif; ?>
                <ol class="fap-guide__steps">
                    <?php foreach ($steps as $i => $st): ?>
                        <li class="<?= $st[1] ? 'is-done' : '' ?>">
                            <span class="fap-guide__mark"><?= $st[1] ? '✓' : ($i + 1) ?></span>
                            <a href="<?= esc_url($st[2]) ?>"><?= esc_html($st[0]) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>

            <!-- ステータスカード -->
            <div class="fap-cards fap-cards-4">
                <?php $this->stat_card('総投稿数', $total . ' 件', 'dashicons-admin-post', '#2271b1'); ?>
                <?php $this->stat_card('本日の投稿', $today_count . ' 件', 'dashicons-calendar-alt', '#00a32a'); ?>
                <?php $this->stat_card('自動実行', $auto_on ? 'ON' : 'OFF', 'dashicons-controls-play', $auto_on ? '#00a32a' : '#d63638'); ?>
                <?php $this->stat_card('API状態', $api_ok ? '検証済み' : '未確認', 'dashicons-admin-network', $api_ok ? '#00a32a' : '#d63638'); ?>
            </div>

            <!-- 次回実行 -->
            <div class="fap-card">
                <h3>⏰ 次回自動実行予定</h3>
                <p style="font-size:16px;font-weight:bold;margin:6px 0 12px;"><?= esc_html($next_run) ?></p>
                <?php if ($auto_on && !$any_active_in_schedule): ?>
                <p style="color:#d63638;margin:0 0 12px;">⚠ 自動実行はONですが、稼働中かつスケジュールに登録されているスロットがありません。<a href="<?= esc_url(admin_url('admin.php?page=' . FAP_SLUG . '-sched')) ?>">自動実行設定</a>でスロットを時間帯に割り当ててください。</p>
                <?php endif; ?>
                <p style="margin:0;"><a href="<?= esc_url(admin_url('admin.php?page=' . FAP_SLUG . '-sched')) ?>" class="button">自動実行設定を変更</a></p>
            </div>

            <!-- スロットカード一覧 -->
            <div class="fap-card">
                <h2>📋 投稿スロット</h2>
                <div class="fap-slot-grid">
                    <?php
                    $max_slots = FAP_License::max_slots();
                    foreach ($slot_statuses as $n => $s):
                        $locked = ($n > $max_slots);
                        $min_plan = $n <= 4 ? 'pro' : 'premium';
                        $plan_label = FAP_License::LABELS[$min_plan] ?? $min_plan;
                    ?>
                    <div class="fap-slot-card<?= $locked ? ' fap-slot-card-locked' : '' ?>">
                        <div class="fap-slot-card__top">
                            <span class="fap-slot-card__name"><?= esc_html($this->slot_label($n)) ?></span>
                            <?php if ($locked): ?>
                                <span class="fap-badge" style="background:#9333ea;color:#fff;">🔒 <?= esc_html($plan_label) ?></span>
                            <?php elseif ($s['active']): ?>
                                <span class="fap-badge fap-badge-on">稼働中</span>
                            <?php else: ?>
                                <span class="fap-badge fap-badge-off">停止</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$locked): ?>
                        <div class="fap-slot-card__meta">
                            フロア：<b><?= esc_html($s['floor'] ?: '未設定') ?></b><br>
                            投稿件数：<b><?= (int) $s['hits'] ?></b> 件
                            <?php if ($s['active'] && !$s['in_sched']): ?>
                            <br><span style="color:#d63638;font-size:11px;">⚠ スケジュール未登録</span>
                            <?php endif; ?>
                        </div>
                        <div class="fap-slot-card__foot">
                            <a href="<?= esc_url(admin_url('admin.php?page=' . FAP_SLUG . '-slot' . $n)) ?>" class="button button-small">設定を開く</a>
                        </div>
                        <?php else: ?>
                        <div class="fap-slot-card__meta" style="color:#9ca3af;">
                            <?= esc_html($plan_label) ?> プランで解放
                        </div>
                        <div class="fap-slot-card__foot">
                            <a href="<?= esc_url(admin_url('admin.php?page=' . FAP_SLUG . '-license')) ?>" class="button button-small">アップグレード</a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 直近実行ログ -->
            <div class="fap-card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <h3 style="margin:0;">📋 直近の実行ログ</h3>
                    <a href="<?= esc_url(admin_url('admin.php?page=' . FAP_SLUG . '-log')) ?>" class="button button-small">ログ全件を見る</a>
                </div>
                <?php $this->render_log_tail(8); ?>
            </div>
        </div>
        <?php
        $this->wrap_end();
    }

    // -----------------------------------------------------------------------
    // ページ：API設定
    // -----------------------------------------------------------------------
    public function page_api(): void {
        if (!current_user_can('manage_options')) wp_die();
        $this->wrap_start('API設定 🔑');
        ?>
        <div class="fap-card">
            <h2>🔑 DMM Web API 認証情報</h2>
            <p>
                <a href="https://affiliate.dmm.com/api/" target="_blank" rel="noopener">DMM アフィリエイト API申請ページ</a>で取得した情報を入力してください。
            </p>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
                <input type="hidden" name="action" value="fap_save_api">
                <table class="form-table">
                    <tr>
                        <th><label for="fap_api_id">API ID</label></th>
                        <td>
                            <?php if (defined('FAP_FANZA_API_ID')): ?>
                                <input type="text" class="regular-text" value="***** (定数設定済み)" disabled />
                                <p class="description" style="color:#2271b1;">サーバー管理者が設定ファイルで登録済みです。ここからは変更できません。</p>
                            <?php else: ?>
                                <input type="text" id="fap_api_id" name="fap_api_id"
                                       value="<?= esc_attr(fap_opt('api_id', '')) ?>"
                                       class="regular-text" placeholder="xxxxxxxxxxxxxxxx" />
                                <p class="description">DMM API IDを入力してください。</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="fap_aff_id">アフィリエイトID</label></th>
                        <td>
                            <?php if (defined('FAP_FANZA_AFF_ID')): ?>
                                <input type="text" class="regular-text" value="***** (定数設定済み)" disabled />
                                <p class="description" style="color:#2271b1;">サーバー管理者が設定ファイルで登録済みです。ここからは変更できません。</p>
                            <?php else: ?>
                                <input type="text" id="fap_aff_id" name="fap_aff_id"
                                       value="<?= esc_attr(fap_opt('aff_id', '')) ?>"
                                       class="regular-text" placeholder="yourname-990" />
                                <p class="description">末尾3桁は990〜999の数字にしてください。</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" name="verify" value="1" class="button button-primary">
                        保存して接続確認（フロア取得）
                    </button>
                    &nbsp;
                    <button type="button" id="fap-test-api" class="button">接続テスト（保存せずに確認）</button>
                    <span id="fap-test-result" style="margin-left:12px;"></span>
                </p>
            </form>
        </div>

        <?php
        $floors = FAP_API::get_floor_list();
        if (!empty($floors)):
        ?>
        <div class="fap-card">
            <h3>✅ 取得済みフロア一覧（<?= count($floors) ?> 件）</h3>
            <div style="max-height:300px;overflow-y:auto;">
            <table class="widefat striped" style="font-size:13px;">
                <thead><tr><th>サービス</th><th>フロア</th><th>キー</th></tr></thead>
                <tbody>
                <?php foreach ($floors as $f): ?>
                <tr>
                    <td><?= esc_html($f['service_name']) ?></td>
                    <td><?= esc_html($f['floor_name']) ?></td>
                    <td><code><?= esc_html($f['key']) ?></code></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif;

        $this->wrap_end();
    }

    // -----------------------------------------------------------------------
    // ページ：自動実行設定
    // -----------------------------------------------------------------------
    public function page_scheduler(): void {
        if (!current_user_can('manage_options')) wp_die();
        if (!FAP_License::is('pro')) {
            $this->wrap_start('自動実行設定 ⏰');
            $this->upgrade_wall('pro', '自動実行スケジューラー');
            $this->wrap_end();
            return;
        }

        $auto_on    = fap_opt('auto_on', '0') === '1';
        $hour_slots = (array) fap_opt('hour_slots', []);
        $today      = fap_opt('date_today', '0') === '1';
        $threeday   = fap_opt('date_3day',  '0') === '1';
        $range      = fap_opt('date_range', '0') === '1';
        $s_date     = fap_opt('s_date', '');
        $e_date     = fap_opt('e_date', '');

        // スロット情報（フロア名・件数）
        $slot_info = [];
        for ($n = 1; $n <= FAP_SLOTS; $n++) {
            $floor = fap_slot_opt($n, 'flr_name', '');
            $hits  = (int) fap_slot_opt($n, 'hits', 0);
            $slot_info[$n] = $floor ? $floor . ($hits ? ' ' . $hits . '件' : '') : '未設定';
        }

        $this->wrap_start('自動実行設定 ⏰');
        ?>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
            <input type="hidden" name="action" value="fap_save_scheduler">

            <div class="fap-card">
                <h2>⚡ 自動実行スイッチ</h2>
                <label class="fap-toggle-wrap">
                    <input type="checkbox" name="auto_on" value="1" <?php checked($auto_on) ?> />
                    <span class="fap-toggle-label">自動実行を有効にする</span>
                </label>
                <p class="description">ONにすると、下で設定した時間帯に自動で記事が投稿されます。サイトへのアクセスを契機に動作するため、設定時刻より数分〜数十分遅れることがあります。</p>
            </div>

            <div class="fap-card">
                <h2>⏱️ 実行分設定</h2>
                <p class="description">毎時何分に実行するか設定します。例：「30」を設定すると 10:30、14:30 などに実行されます。</p>
                <table class="form-table">
                    <tr>
                        <th style="width:140px;">実行分</th>
                        <td>
                            <select name="exe_minute" style="width:90px;">
                                <?php
                                $cur_min = (int) fap_opt('exe_minute', 0);
                                for ($m = 0; $m < 60; $m += 5):
                                    $label = str_pad($m, 2, '0', STR_PAD_LEFT) . ' 分';
                                ?>
                                <option value="<?= $m ?>" <?php selected($cur_min, $m) ?>><?= $label ?></option>
                                <?php endfor; ?>
                            </select>
                            <span class="description">（5分刻み、毎時この分に実行）</span>
                        </td>
                    </tr>
                </table>
                <p class="description">
                    ⚠ 誰かがサイトを訪問したタイミングで動作するため、数分の誤差が生じる場合があります。<br>
                    設定を変えたら必ず「保存」してください。
                </p>
            </div>

            <div class="fap-card">
                <h2>🕐 時間帯 × スロット設定</h2>
                <p class="description">
                    各時間帯で実行したいスロットにチェックを入れてください。
                    スロット設定ページの「スロット有効化」もONにしてください。
                </p>
                <div class="fap-sched-wrap">
                <table class="fap-sched-table">
                    <thead>
                        <tr>
                            <th class="fap-sched-hour-h">時間帯</th>
                            <?php for ($n = 1; $n <= FAP_SLOTS; $n++): ?>
                            <th>
                                <span class="fap-sched-slotname"><?= esc_html($this->slot_label($n)) ?></span>
                                <span class="fap-sched-slotinfo"><?= esc_html($slot_info[$n]) ?></span>
                            </th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($h = 0; $h < 24; $h++):
                            $hkey = 'h' . str_pad($h, 2, '0', STR_PAD_LEFT);
                            $active_slots = isset($hour_slots[$hkey]) ? (array) $hour_slots[$hkey] : [];
                            $row_has_any = !empty($active_slots);
                        ?>
                        <tr class="<?= $row_has_any ? 'is-active' : '' ?>">
                            <td class="fap-sched-hour"><?= (int) $h ?><span>時</span></td>
                            <?php for ($n = 1; $n <= FAP_SLOTS; $n++): ?>
                            <td>
                                <label class="fap-sched-check">
                                    <input type="checkbox"
                                           name="hour_slots[<?= esc_attr($hkey) ?>][]"
                                           value="<?= $n ?>"
                                           <?php checked(in_array((string)$n, $active_slots, true)) ?>>
                                    <span></span>
                                </label>
                            </td>
                            <?php endfor; ?>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
                </div>
                <p class="description" style="margin-top:10px;">
                    💡 青くハイライトされた行が実行対象の時間帯です。同じスロットを複数の時間帯に設定できます。
                </p>
            </div>

            <div class="fap-card">
                <h2>📅 自動実行時の発売日フィルター</h2>
                <p class="description">自動実行時にどの期間の商品を取得するか選択します（複数選択可）。</p>
                <table class="form-table">
                    <tr>
                        <th>当日発売</th>
                        <td>
                            <label><input type="checkbox" name="date_today" value="1" <?php checked($today) ?>> 当日（本日）発売の商品を取得する</label>
                        </td>
                    </tr>
                    <tr>
                        <th>直近3日発売</th>
                        <td>
                            <label><input type="checkbox" name="date_3day" value="1" <?php checked($threeday) ?>> 過去3日以内に発売された商品を取得する</label>
                        </td>
                    </tr>
                    <tr>
                        <th>期間指定</th>
                        <td>
                            <label><input type="checkbox" name="date_range" value="1" <?php checked($range) ?>> 下記の期間で取得する</label>
                            <div style="margin-top:8px;">
                                <input type="date" name="s_date" value="<?= esc_attr($s_date) ?>" />
                                〜
                                <input type="date" name="e_date" value="<?= esc_attr($e_date) ?>" />
                            </div>
                        </td>
                    </tr>
                </table>
                <p class="description">
                    ⚠️ すべて未チェックの場合：発売日フィルターなしで取得します（ソート順に従います）。
                </p>
            </div>

            <?php if (FAP_License::is('premium')): ?>
            <div class="fap-card" style="border-left:4px solid #9333ea;">
                <h2>🔔 セール検知＆自動リライト <span style="background:#9333ea;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;vertical-align:middle;">PREMIUM</span></h2>
                <p class="description">過去に投稿した記事の商品のセール状態が変わると、その記事のタイトルを「投稿タイトル／タイトルテンプレート2・3」から描き直します。セール中だけ文言を出したいときは、下の「セール中タイトル1〜5」を作り、タイトルテンプレートの中に <code>[sale_title_1]</code>〜<code>[sale_title_5]</code> として入れてください（例: <code>[sale_title_1][title]</code>）。セール文言は自動では付かないので、タイトルテンプレートに <code>[sale_title_N]</code> を書いた分だけ反映されます（例: <code>[sale_title_1][title][sale_title_2]</code>）。</p>
                <table class="form-table">
                    <tr>
                        <th>自動セール検知</th>
                        <td>
                            <label>
                                <input type="checkbox" name="sale_check_on" value="1" <?php checked(fap_opt('sale_check_on', '0'), '1') ?>>
                                1日1回、既存投稿のセール状態を自動確認する
                            </label>
                            <p class="description">自動実行スイッチがONのときのみ動作します。1時間ごとに50件ずつ処理し、全記事を1日1周確認します。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>セール中タイトル 1〜5</th>
                        <td>
                            <?php foreach ([1 => '【セール中】', 2 => '', 3 => '', 4 => '', 5 => ''] as $n => $def): ?>
                            <p style="margin:0 0 6px;">
                                <code>[sale_title_<?= $n ?>]</code>
                                <input type="text" name="sale_title_<?= $n ?>" value="<?php echo esc_attr(fap_opt('sale_title_' . $n, $def)) ?>" placeholder="<?= $n === 1 ? '【セール中】' : '例: 【今だけ[discount-rate]】' ?>" style="width:100%;max-width:480px;">
                            </p>
                            <?php endforeach; ?>
                            <p class="description">セール中だけ、タイトルテンプレート内の <code>[sale_title_1]</code>〜<code>[sale_title_5]</code> がこの文言に置き換わります（セール中でなければ空になります）。<strong>タイトル専用</strong>のタグで、本文・抜粋では使えません。文言の中では <code>[discount-rate]</code>（30%OFF）・<code>[sale-price]</code>・<code>[price]</code> も使えます。<br>
                            例: 「投稿タイトル」を <code>[sale_title_1][title]</code>、「タイトルテンプレート 2」を <code>[sale_title_2][title]</code> のようにすると、実際のタイトルはタイトルテンプレートだけで決まります。<br>
                            ※AIリライト（セール時自動リライト）をONにしている場合は、AIが生成したタイトルが優先されます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>トップのセール中バッジ</th>
                        <td>
                            <label>
                                <input type="checkbox" name="sale_badge_on" value="1" <?php checked(fap_opt('sale_badge_on', '0'), '1') ?>>
                                トップページの記事カードのアイキャッチ左下に、セール中バッジを表示する
                            </label>
                            <p style="margin:8px 0 4px;">
                                表示文言:
                                <input type="text" name="sale_badge_text" value="<?php echo esc_attr(fap_opt('sale_badge_text', 'ただいまセール中')) ?>" placeholder="ただいまセール中" style="width:200px;">
                                &nbsp;背景色: <input type="color" name="sale_badge_bg" value="<?php echo esc_attr(fap_opt('sale_badge_bg', '#e53935')) ?>">
                                &nbsp;文字色: <input type="color" name="sale_badge_color" value="<?php echo esc_attr(fap_opt('sale_badge_color', '#ffffff')) ?>">
                            </p>
                            <p class="description">「セール中カテゴリ」がONなら、そのカテゴリが付いた記事だけに表示します（OFFの場合は、セール価格が入っている記事）。トップページのみで、記事ページには出ません。テーマ Cocoon のカード一覧を想定しています。表示が出ない場合は、サイトのキャッシュを削除してください。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>本文の再生成</th>
                        <td>
                            <label>
                                <input type="checkbox" name="sale_rerender" value="1" <?php checked(fap_opt('sale_rerender', '0'), '1') ?>>
                                セール状態が変わった記事の本文をテンプレートから再生成する
                            </label>
                            <p class="description">最新の価格・割引率で本文を描画し直します。本文内の古いセール価格が残る問題を解消できます。テンプレートを変更した場合も次のセール変化時に新テンプレートが反映されます。AIリライトと併用した場合は再生成が優先されます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>セール中カテゴリ</th>
                        <td>
                            <label>
                                <input type="checkbox" name="sale_category_on" value="1" <?php checked(fap_opt('sale_category_on', '0'), '1') ?>>
                                セール中の記事にカテゴリを自動で付け外しする
                            </label>
                            <p style="margin:8px 0 4px;">
                                カテゴリ名:
                                <input type="text" name="sale_category_name" value="<?php echo esc_attr(fap_opt('sale_category_name', 'セール中')) ?>" placeholder="セール中" style="width:200px;">
                            </p>
                            <p class="description">セール開始でカテゴリを付け、終了で外します。「常に今セール中の作品だけが並ぶ一覧ページ」が自動で維持されるので、メニューや内部リンクの導線に使えます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>セール中以外カテゴリ</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nonsale_category_on" value="1" <?php checked(fap_opt('nonsale_category_on', '0'), '1') ?>>
                                セール中でない記事にカテゴリを自動で付け外しする
                            </label>
                            <p style="margin:8px 0 4px;">
                                カテゴリ名:
                                <input type="text" name="nonsale_category_name" value="<?php echo esc_attr(fap_opt('nonsale_category_name', '通常価格')) ?>" placeholder="通常価格" style="width:200px;">
                            </p>
                            <p class="description">セール終了で付け、セール開始で外します。「セール中カテゴリ」を外したときに記事のカテゴリが空になり「未分類」になるのを防げます。新規投稿のほか、セール検知の巡回で既存のセール中でない記事にも順次付きます。「セール中カテゴリ」と同じ名前は使えません。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>前回確認日時</th>
                        <td>
                            <?php
                            $last = fap_opt('sale_check_last_at', '');
                            echo $last ? esc_html($last) : '未実行';
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
            <?php else: ?>
            <div class="fap-card" style="border-left:4px solid #9333ea;">
                <h2>🔔 セール検知＆自動リライト <span style="background:#9333ea;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;vertical-align:middle;">PREMIUM</span></h2>
                <?php $this->section_lock('premium', 'セール検知＆自動リライト'); ?>
            </div>
            <?php endif; ?>

            <div class="fap-card">
                <h2>📧 エラー通知メール</h2>
                <table class="form-table">
                    <tr>
                        <th>エラー時にメール通知</th>
                        <td>
                            <label><input type="checkbox" name="notify_on_error" value="1" <?php checked(fap_opt('notify_on_error', '0'), '1') ?>>
                            スロット実行でエラーが発生したときにメールを送信する</label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="notify_email">送信先メールアドレス</label></th>
                        <td>
                            <input type="email" id="notify_email" name="notify_email" class="regular-text"
                                   value="<?= esc_attr(fap_opt('notify_email', '')) ?>"
                                   placeholder="空欄の場合はWordPressの管理者メールを使用">
                        </td>
                    </tr>
                </table>
            </div>

            <p><button type="submit" class="button button-primary button-large">設定を保存</button></p>
        </form>

        <?php if (FAP_License::is('premium')): ?>
        <div class="fap-card" style="margin-top:20px;">
            <h2>▶ セール検知を今すぐ実行</h2>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
                <input type="hidden" name="action" value="fap_sale_check">
                <button type="submit" class="button button-secondary"
                        onclick="return confirm('既存のFAP投稿を最大50件確認します。時間がかかる場合があります。続行しますか？')">今すぐセール確認（最大50件）</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="fap-card" style="margin-top:20px;border-left:4px solid #9333ea;">
            <h2>🗑 販売停止作品チェック <span style="background:#9333ea;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;vertical-align:middle;">PREMIUM</span></h2>
            <?php if (FAP_License::is('premium')): ?>
            <p class="description">
                FANZAで販売停止（配信終了）になった作品の記事を検索します。見つかった記事は下の一覧で確認してから、まとめてゴミ箱に移動できます。<br>
                記事1件ごとにAPIへ問い合わせるため、記事数が多いと時間がかかります。チェック中はこのページを閉じないでください。
                <?php
                $last = fap_opt('discontinued_check_last_at', '');
                if ($last) echo '<br>前回チェック完了：' . esc_html($last);
                ?>
            </p>
            <p>
                <button type="button" class="button button-secondary" id="fap-disc-check">販売停止チェックを開始</button>
                <span id="fap-disc-progress" style="margin-left:10px;"></span>
            </p>
            <div id="fap-disc-results" style="display:none;">
                <table class="widefat striped" style="margin-bottom:10px;">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="fap-disc-all" checked></th>
                            <th>記事タイトル</th>
                            <th style="width:140px;">CID</th>
                            <th style="width:100px;">投稿日</th>
                        </tr>
                    </thead>
                    <tbody id="fap-disc-list"></tbody>
                </table>
                <p>
                    <button type="button" class="button button-primary" id="fap-disc-trash">選択した記事をゴミ箱へ移動</button>
                    <span class="description" style="margin-left:10px;">ゴミ箱からは復元できます。完全削除はWordPressの投稿一覧（ゴミ箱）から行ってください。</span>
                </p>
            </div>
            <?php else: ?>
            <?php $this->section_lock('premium', '販売停止作品チェック'); ?>
            <?php endif; ?>
        </div>
        <?php
        $this->wrap_end();
    }

    // -----------------------------------------------------------------------
    // ページ：投稿スロット
    // -----------------------------------------------------------------------
    public function page_slot(int $slot): void {
        if (!current_user_can('manage_options')) wp_die();
        if ($slot > FAP_License::max_slots()) {
            $min = $slot <= 4 ? 'pro' : 'premium';
            $this->wrap_start("投稿スロット {$slot} ✏️");
            $this->upgrade_wall($min, "スロット {$slot}");
            $this->wrap_end();
            return;
        }

        $s      = $slot;
        $active = fap_slot_opt($s, 'active', '0') === '1';
        $floors = FAP_API::get_floor_list();

        // 現在の設定値
        $v = [
            'slot_name'    => fap_slot_opt($s, 'slot_name',    ''),
            'floor'        => fap_slot_opt($s, 'floor',        ''),
            'flr_name'     => fap_slot_opt($s, 'flr_name',     ''),
            'keyword'      => fap_slot_opt($s, 'keyword',      ''),
            'article_type' => fap_slot_opt($s, 'article_type', ''),
            'article_id'   => fap_slot_opt($s, 'article_id',   ''),
            'sort'         => fap_slot_opt($s, 'sort',         'rank'),
            'hits'         => fap_slot_opt($s, 'hits',         10),
            'from_date'    => fap_slot_opt($s, 'from_date',    ''),
            'to_date'      => fap_slot_opt($s, 'to_date',      ''),
            'post_status'  => fap_slot_opt($s, 'post_status',  'publish'),
            'post_date'    => fap_slot_opt($s, 'post_date',    'today'),
            'specify_date' => fap_slot_opt($s, 'specify_date', ''),
            'rand_start'   => fap_slot_opt($s, 'rand_start',   ''),
            'rand_end'     => fap_slot_opt($s, 'rand_end',     ''),
            'eyecatch'     => fap_slot_opt($s, 'eyecatch',     'package'),
            'poster'       => fap_slot_opt($s, 'poster',       'package'),
            'maximage'     => fap_slot_opt($s, 'maximage',     0),
            'movie_size'   => fap_slot_opt($s, 'movie_size',   '560'),
            'title_tpl'       => fap_slot_opt($s, 'title_tpl',       '[title]'),
            'title_tpl2'      => fap_slot_opt($s, 'title_tpl2',      ''),
            'title_tpl3'      => fap_slot_opt($s, 'title_tpl3',      ''),
            'excerpt_tpl'     => fap_slot_opt($s, 'excerpt_tpl',     ''),
            'meta_desc_tpl'   => fap_slot_opt($s, 'meta_desc_tpl',   ''),
            'cate_fixed'   => fap_slot_opt($s, 'cate_fixed',   ''),
            'tag_fixed'    => fap_slot_opt($s, 'tag_fixed',    ''),
            'exclude_cate' => fap_slot_opt($s, 'exclude_cate', ''),
            'exclude_tag'  => fap_slot_opt($s, 'exclude_tag',  ''),
            'btn1_text'    => fap_slot_opt($s, 'btn1_text',    'この作品の価格を確認'),
            'btn1_bg'      => fap_slot_opt($s, 'btn1_bg',      '#ff0000'),
            'btn1_color'   => fap_slot_opt($s, 'btn1_color',   '#ffffff'),
            'btn1_border'  => fap_slot_opt($s, 'btn1_border',  ''),
            'btn1_size'    => fap_slot_opt($s, 'btn1_size',    'medium'),
            'btn1_radius'  => fap_slot_opt($s, 'btn1_radius',  'medium'),
            'btn2_text'    => fap_slot_opt($s, 'btn2_text',    '続きの画像はこちら'),
            'btn2_bg'      => fap_slot_opt($s, 'btn2_bg',      '#0000ff'),
            'btn2_color'   => fap_slot_opt($s, 'btn2_color',   '#ffffff'),
            'btn2_border'  => fap_slot_opt($s, 'btn2_border',  ''),
            'btn2_size'    => fap_slot_opt($s, 'btn2_size',    'medium'),
            'btn2_radius'  => fap_slot_opt($s, 'btn2_radius',  'medium'),
            'random1'           => fap_slot_opt($s, 'random1',           ''),
            'random2'           => fap_slot_opt($s, 'random2',           ''),
            'random3'           => fap_slot_opt($s, 'random3',           ''),
            'blacklist'         => fap_slot_opt($s, 'blacklist',         ''),
            'blacklist_genre'   => fap_slot_opt($s, 'blacklist_genre',   ''),
            'blacklist_actress' => fap_slot_opt($s, 'blacklist_actress', ''),
            'blacklist_maker'   => fap_slot_opt($s, 'blacklist_maker',   ''),
            'min_review_avg'    => fap_slot_opt($s, 'min_review_avg',    ''),
            'min_review_cnt'    => fap_slot_opt($s, 'min_review_cnt',    ''),
            'sale_only'         => fap_slot_opt($s, 'sale_only',         '0'),
            'random_time_on'    => fap_slot_opt($s, 'random_time_on',    '0'),
            'random_time_max'   => fap_slot_opt($s, 'random_time_max',   60),
        ];

        // cate_source / tag_source を配列に正規化（旧形式の文字列にも対応）
        $cs_raw = fap_slot_opt($s, 'cate_source', []);
        $cate_sources = is_array($cs_raw) ? $cs_raw : (($cs_raw && $cs_raw !== 'none') ? [$cs_raw] : []);
        $ts_raw = fap_slot_opt($s, 'tag_source', []);
        $tag_sources  = is_array($ts_raw) ? $ts_raw : (($ts_raw && $ts_raw !== 'none') ? [$ts_raw] : []);

        // テンプレート投稿の確認
        $tpl_name  = 'FAP テンプレート' . ($slot === 1 ? '' : $slot);
        $tpl_results = get_posts(['title' => $tpl_name, 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => 1, 'no_found_rows' => true]);
        $tpl_post    = $tpl_results[0] ?? null;
        $tpl_label = $tpl_post ? '✅ 設定済み（ID: ' . $tpl_post->ID . '）' : '❌ 未設定';

        $this->wrap_start("投稿スロット {$slot} ✏️");

        // スロット切替ナビ + 有効化トグル + クイック実行
        ?>
        <nav class="fap-slot-switch">
            <span class="fap-slot-switch__label">スロット切替：</span>
            <?php for ($i = 1; $i <= FAP_SLOTS; $i++):
                $i_active = fap_slot_opt($i, 'active', '0') === '1'; ?>
                <a class="fap-slot-switch__item<?= $i === $slot ? ' is-current' : '' ?>"
                   href="<?= esc_url(admin_url('admin.php?page=' . FAP_SLUG . '-slot' . $i)) ?>">
                    <?= $i_active ? '●' : '○' ?> <?= esc_html($this->slot_label($i)) ?>
                </a>
            <?php endfor; ?>
        </nav>

        <div class="fap-slot-header">
            <span class="fap-slot-badge <?= $active ? 'fap-badge-on' : 'fap-badge-off' ?>">
                <?= esc_html($this->slot_label($slot)) ?> <?= $active ? '稼働中' : '停止中' ?>
            </span>
            <button type="submit" form="fap-slot-form" class="button button-primary" style="margin-left:8px;">
                💾 設定を保存
            </button>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline;">
                <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
                <input type="hidden" name="action" value="fap_quick_post">
                <input type="hidden" name="slot"   value="<?= $slot ?>">
                <button type="submit" class="button" style="margin-left:4px;"
                        onclick="return confirm('スロット<?= $slot ?>を今すぐ1回実行します。\n※保存済みの設定で動作します。設定変更後は先に「設定を保存」してください。\n続行しますか？')">
                    ▶ 今すぐ1回実行
                </button>
            </form>
            <button type="button" class="button fap-preview-btn" data-slot="<?= $slot ?>" style="margin-left:4px;">
                👁 プレビュー
            </button>
            <span class="description" style="margin-left:8px;font-size:11px;">※保存済みの設定でプレビューします</span>
        </div>

        <details style="margin:8px 0 12px;">
            <summary style="cursor:pointer;">🔎 商品IDを指定して投稿</summary>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-top:8px;">
                <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
                <input type="hidden" name="action" value="fap_cid_post">
                <input type="hidden" name="slot"   value="<?= $slot ?>">
                <textarea name="cids" rows="3" class="large-text" placeholder="d_789035&#10;d_123456"></textarea>
                <p class="description">商品ID（content_id）を、改行またはカンマ区切りで最大20件まで。FANZAの商品ページURL（…/cid=d_789035/）を貼ってもIDを取り出します。<br>このスロットの<strong>保存済みの設定</strong>（フロア・テンプレート・タグ・カテゴリなど）で投稿します。フロアが違うIDや、存在しないIDはエラーになります。投稿済みのIDは重複チェックでエラーになります。</p>
                <button type="submit" class="button">▶ 商品IDで投稿</button>
            </form>
        </details>

        <div class="fap-preview" id="fap-preview-<?= $slot ?>" style="display:none;">
            <div class="fap-card">
                <h2>👁 テンプレートプレビュー <span class="fap-preview-cid"></span></h2>
                <p class="description">設定を保存した状態で、実際に1件取得してテンプレートを描画した結果です（投稿は作成されません）。</p>
                <div class="fap-preview-title"></div>
                <div class="fap-preview-body"></div>
            </div>
        </div>

        <form id="fap-slot-form" method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
            <input type="hidden" name="action" value="fap_save_slot">
            <input type="hidden" name="slot"   value="<?= $slot ?>">

            <!-- 1. スロット有効化 -->
            <div class="fap-card">
                <h2>🔌 スロット有効化</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="slot_name">スロット名</label></th>
                        <td>
                            <input type="text" id="slot_name" name="slot_name" class="regular-text"
                                   value="<?= esc_attr($v['slot_name']) ?>" placeholder="スロット<?= $slot ?>">
                            <p class="description">管理画面の表示用の名前です（例：素人動画／新作AV など）。空欄なら「スロット<?= $slot ?>」と表示されます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>稼働設定</th>
                        <td>
                            <label>
                                <input type="checkbox" name="slot_active" value="1" <?php checked($active) ?>>
                                このスロットを自動実行で稼働させる
                            </label>
                            <p class="description">有効化後、<a href="<?= esc_url(admin_url('admin.php?page=' . FAP_SLUG . '-sched')) ?>">自動実行設定</a>の「時間帯 × スロット」表でこのスロットにもチェックを入れてください。両方の設定が必要です。</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 2. API検索設定 -->
            <div class="fap-card">
                <h2>🔍 API検索設定</h2>
                <table class="form-table">
                    <tr>
                        <th>フロア選択</th>
                        <td>
                            <?php if (empty($floors)): ?>
                                <p class="description" style="color:#d63638;">
                                    ⚠️ まず「API設定」ページでAPIキーを保存・検証してください。
                                </p>
                            <?php else: ?>
                                <select name="floor" id="fap-floor-select">
                                    <option value="">-- フロアを選択 --</option>
                                    <?php
                                    $groups = [];
                                    foreach ($floors as $f) {
                                        $groups[$f['service_name']][] = $f;
                                    }
                                    foreach ($groups as $svc => $items):
                                    ?>
                                    <optgroup label="<?= esc_attr($svc) ?>">
                                        <?php foreach ($items as $f): ?>
                                        <option value="<?= esc_attr($f['key']) ?>"
                                                data-label="<?= esc_attr($f['label']) ?>"
                                                <?php selected($v['floor'], $f['key']) ?>>
                                            <?= esc_html($f['floor_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="flr_name" id="fap-flr-name" value="<?= esc_attr($v['flr_name']) ?>">
                                <?php if ($v['flr_name']): ?>
                                <p class="description">現在: <strong><?= esc_html($v['flr_name']) ?></strong></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="fap_keyword">キーワード</label></th>
                        <td>
                            <input type="text" id="fap_keyword" name="keyword"
                                   value="<?= esc_attr($v['keyword']) ?>" class="regular-text">
                            <p class="description">空白の場合はフロア全体から取得します。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>記事絞り込み</th>
                        <td>
                            <select name="article_type">
                                <option value="">指定なし</option>
                                <?php foreach (['actress'=>'女優', 'author'=>'作者', 'genre'=>'ジャンル', 'series'=>'シリーズ', 'maker'=>'メーカー'] as $val => $lbl): ?>
                                <option value="<?= $val ?>" <?php selected($v['article_type'], $val) ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                            &nbsp;ID:
                            <input type="text" name="article_id" value="<?= esc_attr($v['article_id']) ?>" style="width:120px;" placeholder="例: 27222">
                            <p class="description">特定の女優・ジャンルだけに絞りたい場合に入力します。IDはFANZAの女優・ジャンルページのURLに含まれる番号です（作品のCIDとは異なります）。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>ソート順</label></th>
                        <td>
                            <select name="sort">
                                <?php foreach (['rank'=>'人気順', 'date'=>'新着順', 'review'=>'評価順', 'price'=>'価格が高い順'] as $val => $lbl): ?>
                                <option value="<?= $val ?>" <?php selected($v['sort'], $val) ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php if (FAP_License::is('pro')): ?>
                    <tr>
                        <th>セール品のみ取得<br><span style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;">Pro</span></th>
                        <td>
                            <label>
                                <input type="checkbox" name="sale_only" value="1" <?php checked($v['sale_only'], '1') ?>>
                                セール中の作品のみ取得する
                            </label>
                            <p class="description">⚠ FANZAのAPI情報をもとにセール中と判定します。フロアによっては正確に判定できないことがあります。実際の投稿内容でご確認ください。</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><label for="fap_hits">取得/投稿件数</label></th>
                        <td>
                            <input type="number" id="fap_hits" name="hits" value="<?= esc_attr($v['hits']) ?>"
                                   min="1" max="500" style="width:80px;">
                            <span class="description">&nbsp;件（重複は自動スキップ）</span>
                        </td>
                    </tr>
                    <tr>
                        <th>発売日絞り込み<br><small>（手動実行時のみ）</small></th>
                        <td>
                            <input type="date" name="from_date" value="<?= esc_attr($v['from_date']) ?>">
                            &nbsp;〜&nbsp;
                            <input type="date" name="to_date"   value="<?= esc_attr($v['to_date']) ?>">
                            <p class="description">自動実行時は「自動実行設定」の日付フィルターが使用されます。</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 3. 投稿設定 -->
            <div class="fap-card">
                <h2>📝 投稿設定</h2>
                <table class="form-table">
                    <tr>
                        <th>投稿ステータス</th>
                        <td>
                            <?php foreach (['publish'=>'公開（publish）', 'draft'=>'下書き（draft）'] as $val => $lbl): ?>
                            <label style="margin-right:16px;">
                                <input type="radio" name="post_status" value="<?= $val ?>" <?php checked($v['post_status'], $val) ?>>
                                <?= $lbl ?>
                            </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>投稿日時</th>
                        <td>
                            <label><input type="radio" name="post_date" value="today" <?php checked($v['post_date'], 'today') ?>> 投稿時刻をそのまま使用</label><br>
                            <label><input type="radio" name="post_date" value="specify" <?php checked($v['post_date'], 'specify') ?>>
                                指定日：<span data-post-date="specify" style="<?= $v['post_date'] === 'specify' ? '' : 'display:none;' ?>display:inline;"><input type="date" name="specify_date" value="<?= esc_attr($v['specify_date']) ?>" style="width:150px;"></span>
                            </label><br>
                            <label><input type="radio" name="post_date" value="random" <?php checked($v['post_date'], 'random') ?>>
                                ランダム：<span data-post-date="random" style="<?= $v['post_date'] === 'random' ? '' : 'display:none;' ?>display:inline;">
                                    <input type="date" name="rand_start" value="<?= esc_attr($v['rand_start']) ?>">
                                    〜
                                    <input type="date" name="rand_end"   value="<?= esc_attr($v['rand_end']) ?>">
                                </span>
                            </label>
                        </td>
                    </tr>
                    <?php if (FAP_License::is('pro')): ?>
                    <tr>
                        <th>投稿時間ランダム化<br><span style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;">Pro</span></th>
                        <td>
                            <label>
                                <input type="checkbox" name="random_time_on" value="1" <?php checked($v['random_time_on'], '1') ?>>
                                投稿時刻をランダムに分散させる（「投稿時刻をそのまま使用」のときのみ有効）
                            </label>
                            <div style="margin-top:8px;">
                                最大&nbsp;<input type="number" name="random_time_max"
                                    value="<?= esc_attr($v['random_time_max']) ?>"
                                    min="1" max="1440" style="width:80px;">&nbsp;分以内のランダム遅延を加算
                            </div>
                            <p class="description">例：60に設定すると実行時から0〜60分の間でランダムな時刻に投稿されます。未来の時刻になる場合は予約投稿として扱われます。</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><label for="fap_title_tpl">投稿タイトル</label></th>
                        <td>
                            <input type="text" id="fap_title_tpl" name="title_tpl"
                                   value="<?= esc_attr($v['title_tpl']) ?>" class="large-text">
                            <p class="description">変数タグが使えます。例: <code>[title] - [actress]レビュー</code>　<a href="<?= esc_url(admin_url('admin.php?page=' . FAP_SLUG . '-vartags')) ?>" target="_blank">変数タグ一覧を見る →</a></p>
                        </td>
                    </tr>
                    <?php if (FAP_License::is('pro')): ?>
                    <tr>
                        <th>タイトルテンプレート 2<br><span style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;">Pro</span></th>
                        <td>
                            <input type="text" name="title_tpl2"
                                   value="<?= esc_attr($v['title_tpl2']) ?>" class="large-text"
                                   placeholder="空欄なら使用しない">
                            <p class="description">入力すると投稿時にテンプレート1〜3からランダム選択されます。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>タイトルテンプレート 3<br><span style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;">Pro</span></th>
                        <td>
                            <input type="text" name="title_tpl3"
                                   value="<?= esc_attr($v['title_tpl3']) ?>" class="large-text"
                                   placeholder="空欄なら使用しない">
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><label for="fap_excerpt_tpl">投稿抜粋</label></th>
                        <td>
                            <input type="text" id="fap_excerpt_tpl" name="excerpt_tpl"
                                   value="<?= esc_attr($v['excerpt_tpl']) ?>" class="large-text">
                            <p class="description">空白の場合は自動生成されません。</p>
                        </td>
                    </tr>
                    <?php if (FAP_License::is('pro')): ?>
                    <tr>
                        <th>メタディスクリプション<br><span style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;">Pro</span></th>
                        <td>
                            <input type="text" name="meta_desc_tpl"
                                   value="<?= esc_attr($v['meta_desc_tpl']) ?>" class="large-text"
                                   placeholder="例: [title] ([actress]) のレビュー・サンプル・詳細">
                            <p class="description">
                                Googleの検索結果に表示される説明文です（160文字以内を推奨）。<br>
                                空欄の場合は他のSEOプラグインやテーマの設定が使われます。
                            </p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>本文テンプレート</th>
                        <td>
                            <p>状態: <strong><?= esc_html($tpl_label) ?></strong></p>
                            <a href="<?= esc_url(admin_url('post-new.php?post_type=post&fap_template=1&fap_slot=' . $slot)) ?>"
                               class="button" target="_blank">新しい下書き投稿を作成（テンプレート用）</a>
                            <?php if ($tpl_post): ?>
                            &nbsp;<a href="<?= esc_url(get_edit_post_link($tpl_post->ID)) ?>" class="button" target="_blank">テンプレートを編集</a>
                            <?php endif; ?>
                            <p class="description">
                                <strong>【作成手順】</strong> ①上のボタンをクリックして新しい投稿ページを開く → ②タイトルを <code><?= esc_html($tpl_name) ?></code> にする → ③本文に変数タグを使ってレイアウトを作る → ④「下書き保存」する。<br>
                                テンプレートが未設定の場合は、画像・詳細情報・購入ボタンのシンプルな構成で投稿されます。
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ジャンル別テンプレート（PREMIUM） -->
            <?php if (FAP_License::is('premium')): ?>
            <div class="fap-card" style="border-left:4px solid #9333ea;">
                <h2>🎭 ジャンル別テンプレート <span style="background:#9333ea;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;vertical-align:middle;">PREMIUM</span></h2>
                <p class="description">指定ジャンルが含まれる作品には別のテンプレートを適用します。上から順に判定し、最初に一致したものを使用します。</p>
                <?php
                $genre_tpls = (array) fap_slot_opt($slot, 'genre_tpls', []);
                if (empty($genre_tpls)) $genre_tpls = [['genre' => '', 'tpl' => '']];
                ?>
                <div id="fap-genre-tpls-wrap">
                <?php foreach ($genre_tpls as $idx => $entry): ?>
                <div class="fap-genre-tpl-row" style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
                    <input type="text" name="genre_tpls[<?= $idx ?>][genre]"
                           value="<?= esc_attr($entry['genre'] ?? '') ?>"
                           placeholder="ジャンル名（例：単体作品）" style="width:220px;">
                    <span>→</span>
                    <input type="text" name="genre_tpls[<?= $idx ?>][tpl]"
                           value="<?= esc_attr($entry['tpl'] ?? '') ?>"
                           placeholder="テンプレート投稿タイトル（例：FAP 単体テンプレート）" style="width:300px;">
                    <button type="button" class="button fap-genre-tpl-remove">削除</button>
                </div>
                <?php endforeach; ?>
                </div>
                <button type="button" class="button" id="fap-genre-tpl-add">＋ ルール追加</button>
                <p class="description" style="margin-top:8px;">FANZAに表示されているジャンル名をそのまま入力してください（例：「単体作品」「バーチャル」）。大文字・小文字も含めて完全一致で判定します。</p>
            </div>
            <script>
            (function(){
                document.getElementById('fap-genre-tpl-add').addEventListener('click', function(){
                    var wrap = document.getElementById('fap-genre-tpls-wrap');
                    var idx  = wrap.querySelectorAll('.fap-genre-tpl-row').length;
                    var row  = document.createElement('div');
                    row.className = 'fap-genre-tpl-row';
                    row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;';
                    row.innerHTML = '<input type="text" name="genre_tpls[' + idx + '][genre]" placeholder="ジャンル名（例：単体作品）" style="width:220px;">'
                                  + '<span>→</span>'
                                  + '<input type="text" name="genre_tpls[' + idx + '][tpl]" placeholder="テンプレート投稿タイトル（例：FAP 単体テンプレート）" style="width:300px;">'
                                  + '<button type="button" class="button fap-genre-tpl-remove">削除</button>';
                    wrap.appendChild(row);
                });
                document.getElementById('fap-genre-tpls-wrap').addEventListener('click', function(e){
                    if (e.target.classList.contains('fap-genre-tpl-remove')) {
                        e.target.closest('.fap-genre-tpl-row').remove();
                    }
                });
            })();
            </script>
            <?php else: ?>
            <div class="fap-card" style="border-left:4px solid #9333ea;">
                <h2>🎭 ジャンル別テンプレート <span style="background:#9333ea;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;vertical-align:middle;">PREMIUM</span></h2>
                <?php $this->section_lock('premium', 'ジャンル別テンプレート'); ?>
            </div>
            <?php endif; ?>

            <!-- 4. 画像・動画設定 -->
            <div class="fap-card">
                <h2>🖼️ 画像・動画設定</h2>
                <table class="form-table">
                    <tr>
                        <th>アイキャッチ画像</th>
                        <td>
                            <select name="eyecatch">
                                <?php foreach ([
                                    'package' => 'パッケージ画像',
                                    'sample'  => 'サンプル画像（自動選択）',
                                    '1' => 'サンプル画像: 1枚目', '2' => '2枚目', '3' => '3枚目',
                                    '4' => '4枚目', '99' => '最後のサンプル画像',
                                    'none'    => '指定なし',
                                ] as $val => $lbl): ?>
                                <option value="<?= $val ?>" <?php selected($v['eyecatch'], $val) ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>動画ポスター画像<br><small>[sample-movie2]用</small></th>
                        <td>
                            <select name="poster">
                                <option value="package" <?php selected($v['poster'], 'package') ?>>パッケージ画像</option>
                                <option value="sample"  <?php selected($v['poster'], 'sample')  ?>>サンプル画像</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="fap_maximage">サンプル画像最大枚数</label></th>
                        <td>
                            <input type="number" id="fap_maximage" name="maximage"
                                   value="<?= esc_attr($v['maximage']) ?>" min="0" max="50" style="width:80px;">
                            <span class="description">&nbsp;枚（0＝全て）</span>
                        </td>
                    </tr>
                    <tr>
                        <th>サンプル動画サイズ<br><small>[sample-movie]用</small></th>
                        <td>
                            <select name="movie_size">
                                <?php foreach (['476'=>'476px', '560'=>'560px', '600'=>'600px', '644'=>'644px', '720'=>'720px'] as $val => $lbl): ?>
                                <option value="<?= $val ?>" <?php selected($v['movie_size'], $val) ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 5. カテゴリ・タグ -->
            <div class="fap-card">
                <h2>🏷️ カテゴリ・タグ設定</h2>
                <div class="fap-cards-2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <?php
                    $source_choices = [
                        'genre'    => 'ジャンル',
                        'actress'  => '女優',
                        'director' => '監督',
                        'series'   => 'シリーズ',
                        'maker'    => 'メーカー',
                        'label'    => 'レーベル',
                        'author'   => '作者',
                    ];
                    ?>
                    <div>
                        <h3>カテゴリ</h3>
                        <p><strong>情報源（複数選択可）：</strong></p>
                        <div style="display:flex;flex-wrap:wrap;gap:6px 16px;margin-bottom:8px;">
                            <?php foreach ($source_choices as $val => $lbl): ?>
                            <label>
                                <input type="checkbox" name="cate_source[]" value="<?= esc_attr($val) ?>"
                                    <?php checked(in_array($val, $cate_sources, true)) ?>>
                                <?= esc_html($lbl) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">未チェックの場合は固定カテゴリのみ設定されます。</p>
                        <p><strong>固定カテゴリ（スラッグまたは名前を/区切りで）：</strong><br>
                            <input type="text" name="cate_fixed" value="<?= esc_attr($v['cate_fixed']) ?>" class="large-text" placeholder="例: AV女優/動画">
                        </p>
                        <p><strong>除外カテゴリ（名前を/区切りで）：</strong><br>
                            <input type="text" name="exclude_cate" value="<?= esc_attr($v['exclude_cate']) ?>" class="large-text">
                        </p>
                    </div>
                    <div>
                        <h3>タグ</h3>
                        <p><strong>情報源（複数選択可）：</strong></p>
                        <div style="display:flex;flex-wrap:wrap;gap:6px 16px;margin-bottom:8px;">
                            <?php foreach ($source_choices as $val => $lbl): ?>
                            <label>
                                <input type="checkbox" name="tag_source[]" value="<?= esc_attr($val) ?>"
                                    <?php checked(in_array($val, $tag_sources, true)) ?>>
                                <?= esc_html($lbl) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">未チェックの場合は固定タグのみ設定されます。</p>
                        <p><strong>固定タグ（名前を/区切りで）：</strong><br>
                            <input type="text" name="tag_fixed" value="<?= esc_attr($v['tag_fixed']) ?>" class="large-text" placeholder="例: FANZA/アダルト">
                        </p>
                        <p><strong>除外タグ（名前を/区切りで）：</strong><br>
                            <input type="text" name="exclude_tag" value="<?= esc_attr($v['exclude_tag']) ?>" class="large-text">
                        </p>
                    </div>
                </div>
            </div>

            <!-- 6. アフィリエイトボタン -->
            <div class="fap-card">
                <h2>🔘 アフィリエイトボタン設定</h2>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                    <div>
                        <h3>[aff-button]</h3>
                        <table class="form-table">
                            <tr>
                                <th>ボタンテキスト</th>
                                <td><input type="text" name="btn1_text" value="<?= esc_attr($v['btn1_text']) ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th>背景色</th>
                                <td><input type="text" name="btn1_bg" value="<?= esc_attr($v['btn1_bg']) ?>" class="fap-color-picker"></td>
                            </tr>
                            <tr>
                                <th>文字色</th>
                                <td><input type="text" name="btn1_color" value="<?= esc_attr($v['btn1_color']) ?>" class="fap-color-picker"></td>
                            </tr>
                            <tr>
                                <th>枠線の色</th>
                                <td><input type="text" name="btn1_border" value="<?= esc_attr($v['btn1_border']) ?>" class="fap-color-picker">
                                    <p class="description">空欄で枠線なし</p></td>
                            </tr>
                            <tr>
                                <th>サイズ</th>
                                <td><?php $this->btn_size_select('btn1_size', $v['btn1_size']) ?></td>
                            </tr>
                            <tr>
                                <th>角丸</th>
                                <td><?php $this->btn_radius_select('btn1_radius', $v['btn1_radius']) ?></td>
                            </tr>
                        </table>
                    </div>
                    <div>
                        <h3>[aff-button2]</h3>
                        <table class="form-table">
                            <tr>
                                <th>ボタンテキスト</th>
                                <td><input type="text" name="btn2_text" value="<?= esc_attr($v['btn2_text']) ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th>背景色</th>
                                <td><input type="text" name="btn2_bg" value="<?= esc_attr($v['btn2_bg']) ?>" class="fap-color-picker"></td>
                            </tr>
                            <tr>
                                <th>文字色</th>
                                <td><input type="text" name="btn2_color" value="<?= esc_attr($v['btn2_color']) ?>" class="fap-color-picker"></td>
                            </tr>
                            <tr>
                                <th>枠線の色</th>
                                <td><input type="text" name="btn2_border" value="<?= esc_attr($v['btn2_border']) ?>" class="fap-color-picker">
                                    <p class="description">空欄で枠線なし</p></td>
                            </tr>
                            <tr>
                                <th>サイズ</th>
                                <td><?php $this->btn_size_select('btn2_size', $v['btn2_size']) ?></td>
                            </tr>
                            <tr>
                                <th>角丸</th>
                                <td><?php $this->btn_radius_select('btn2_radius', $v['btn2_radius']) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 7. ランダムテキスト -->
            <div class="fap-card">
                <h2>🎲 ランダムテキスト設定</h2>
                <p class="description">1行に1テキスト。投稿時にランダムで1つ選ばれて [random1]〜[random3] に置換されます。</p>
                <?php for ($i = 1; $i <= 3; $i++): ?>
                <div style="margin-bottom:16px;">
                    <label><strong>[random<?= $i ?>]</strong></label><br>
                    <textarea name="random<?= $i ?>" rows="5" class="large-text"><?= esc_textarea($v['random' . $i]) ?></textarea>
                </div>
                <?php endfor; ?>
            </div>

            <!-- 8. ブラックリスト -->
            <div class="fap-card">
                <h2>🚫 除外設定</h2>
                <table class="form-table">
                    <tr>
                        <th>タイトルキーワード除外</th>
                        <td>
                            <textarea name="blacklist" rows="4" class="large-text"><?= esc_textarea($v['blacklist']) ?></textarea>
                            <p class="description">タイトルにこのキーワードが含まれる作品をスキップします。1行に1キーワード（カンマ区切りも可）。</p>
                        </td>
                    </tr>
                    <?php if (FAP_License::is('pro')): ?>
                    <tr>
                        <th>ジャンル除外<br><span style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;">Pro</span></th>
                        <td>
                            <textarea name="blacklist_genre" rows="3" class="large-text"><?= esc_textarea($v['blacklist_genre']) ?></textarea>
                            <p class="description">指定したジャンルを含む作品をスキップします。1行に1ジャンル名。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>女優除外<br><span style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;">Pro</span></th>
                        <td>
                            <textarea name="blacklist_actress" rows="3" class="large-text"><?= esc_textarea($v['blacklist_actress']) ?></textarea>
                            <p class="description">指定した女優が出演する作品をスキップします。1行に1名。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>メーカー除外<br><span style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;">Pro</span></th>
                        <td>
                            <textarea name="blacklist_maker" rows="3" class="large-text"><?= esc_textarea($v['blacklist_maker']) ?></textarea>
                            <p class="description">指定したメーカーの作品をスキップします。1行に1メーカー名。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>最低レビュー評価<br><span style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;">Pro</span></th>
                        <td>
                            <input type="number" name="min_review_avg" step="0.1" min="0" max="5"
                                   value="<?= esc_attr($v['min_review_avg']) ?>" style="width:80px;"> 以上
                            <p class="description">0または空欄の場合は制限なし。レビューなしの作品は除外されません。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>最低レビュー件数<br><span style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;">Pro</span></th>
                        <td>
                            <input type="number" name="min_review_cnt" min="0"
                                   value="<?= esc_attr($v['min_review_cnt']) ?>" style="width:80px;"> 件以上
                            <p class="description">0または空欄の場合は制限なし。</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

        </form>
        <?php
        $this->wrap_end();
    }

    // -----------------------------------------------------------------------
    // ページ：SEO設定
    // -----------------------------------------------------------------------
    public function page_seo(): void {
        if (!current_user_can('manage_options')) wp_die();
        if (!FAP_License::is('pro')) {
            $this->wrap_start('SEO設定 🔍');
            $this->upgrade_wall('pro', 'SEO設定（JSON-LD / OGP / Twitter Card）');
            $this->wrap_end();
            return;
        }

        $v = [
            'jsonld'       => fap_opt('seo_jsonld',       '1') === '1',
            'og'           => fap_opt('seo_og',           '1') === '1',
            'twitter'      => fap_opt('seo_twitter',      '1') === '1',
            'twitter_card' => fap_opt('seo_twitter_card', 'summary_large_image'),
            'sitemap'      => fap_opt('seo_sitemap',      '1') === '1',
            'breadcrumb'   => fap_opt('seo_breadcrumb',   '0') === '1',
        ];

        $this->wrap_start('SEO設定 🔍');
        ?>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
            <input type="hidden" name="action" value="fap_save_seo">

            <div class="fap-card">
                <h2>📊 構造化データ（JSON-LD）</h2>
                <table class="form-table">
                    <tr>
                        <th>JSON-LD 出力</th>
                        <td>
                            <label><input type="checkbox" name="seo_jsonld" value="1" <?php checked($v['jsonld']) ?>>
                            有効にする（Article + VideoObject schema を自動出力）</label>
                            <p class="description">
                                FAP で生成した記事ページに、Googleが読み取れる商品・動画・評価情報を自動で埋め込みます。<br>
                                検索結果に評価★などのリッチスニペットが表示されやすくなります。
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="fap-card">
                <h2>📤 OGP（Open Graph Protocol）</h2>
                <table class="form-table">
                    <tr>
                        <th>OGP タグ出力</th>
                        <td>
                            <label><input type="checkbox" name="seo_og" value="1" <?php checked($v['og']) ?>>
                            有効にする（og:title / og:description / og:image 等を自動出力）</label>
                            <p class="description">X（旧Twitter）やFacebookでシェアされたときのサムネイル・タイトルを最適化します。Yoast SEOなどSEO系プラグインを使っている場合は無効にしてください（重複します）。</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="fap-card">
                <h2>🐦 Twitter カード</h2>
                <table class="form-table">
                    <tr>
                        <th>Twitter カード出力</th>
                        <td>
                            <label><input type="checkbox" name="seo_twitter" value="1" <?php checked($v['twitter']) ?>>
                            有効にする</label>
                        </td>
                    </tr>
                    <tr>
                        <th>カードタイプ</th>
                        <td>
                            <label><input type="radio" name="seo_twitter_card" value="summary_large_image" <?php checked($v['twitter_card'], 'summary_large_image') ?>>
                            大きなサムネイル（推奨）</label><br>
                            <label><input type="radio" name="seo_twitter_card" value="summary" <?php checked($v['twitter_card'], 'summary') ?>>
                            小さなサムネイル</label>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="fap-card">
                <h2>🗺️ 画像サイトマップ</h2>
                <table class="form-table">
                    <tr>
                        <th>画像サイトマップ</th>
                        <td>
                            <label><input type="checkbox" name="seo_sitemap" value="1" <?php checked($v['sitemap']) ?>>
                            有効にする</label>
                            <p class="description">
                                FAP で投稿した記事の画像一覧をGoogleに伝えるためのファイルを
                                <code><?= esc_html(home_url('/fap-image-sitemap.xml')) ?></code>
                                で公開します。<br>
                                Google Search Console に登録すると画像が検索に表示されやすくなります。<br>
                                ⚠ 設定を変更したあとは「WordPressの設定 → パーマリンク」を一度開いて保存してください（必須）。
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="fap-card">
                <h2>🍞 パンくずリスト schema（BreadcrumbList）</h2>
                <table class="form-table">
                    <tr>
                        <th>パンくず schema</th>
                        <td>
                            <label><input type="checkbox" name="seo_breadcrumb" value="1" <?php checked($v['breadcrumb']) ?>>
                            有効にする</label>
                            <p class="description">「トップ ＞ カテゴリ ＞ 記事名」のようなナビゲーション情報をGoogleに伝えます。テーマにパンくずが既にある場合やYoast SEOを使っている場合は無効にしてください。</p>
                        </td>
                    </tr>
                </table>
            </div>

            <p><button type="submit" class="button button-primary button-large">設定を保存</button></p>
        </form>
        <?php
        $this->wrap_end();
    }

    // -----------------------------------------------------------------------
    // ページ：表示・機能設定
    // -----------------------------------------------------------------------
    public function page_features(): void {
        if (!current_user_can('manage_options')) wp_die();

        $sticky        = fap_opt('feat_sticky',        '0') === '1';
        $sticky_text   = fap_opt('feat_sticky_text',   'この作品をFANZAで見る');
        $sticky_bg     = fap_opt('feat_sticky_bg',     '#e0306e');
        $sticky_bg2    = fap_opt('feat_sticky_bg2',    '#c026d3');
        $sticky_color  = fap_opt('feat_sticky_color',  '#ffffff');
        $sticky_border = fap_opt('feat_sticky_border', '');
        $sticky_radius = fap_opt('feat_sticky_radius', 'medium');
        $notice      = fap_opt('feat_notice',      '0') === '1';
        $notice_text = fap_opt('feat_notice_text', '');
        $dedup       = fap_opt('feat_dedup',       '1') === '1';

        $this->wrap_start('表示・機能設定 ⚙️');
        ?>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
            <input type="hidden" name="action" value="fap_save_features">

            <div class="fap-card" style="border-left:4px solid #2563eb;">
                <h2>📱 スマホ追従CTAバー <span style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;vertical-align:middle;">PRO</span></h2>
                <?php if (!FAP_License::is('pro')): ?>
                <?php $this->section_lock('pro', 'スマホ追従CTAバー'); ?>
                <?php else: ?>
                <table class="form-table">
                    <tr>
                        <th>追従ボタンを表示</th>
                        <td>
                            <label><input type="checkbox" name="feat_sticky" value="1" <?php checked($sticky) ?>>
                            スマホ閲覧時、画面下に常時表示されるアフィリエイトボタンを出す</label>
                            <p class="description">スクロールに追従して表示され、最下部付近では自動的に隠れます。PCでは非表示です。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="feat_sticky_text">ボタン文言</label></th>
                        <td><input type="text" id="feat_sticky_text" name="feat_sticky_text" class="regular-text" value="<?= esc_attr($sticky_text) ?>"></td>
                    </tr>
                    <tr>
                        <th>背景色</th>
                        <td><input type="text" name="feat_sticky_bg" value="<?= esc_attr($sticky_bg) ?>" class="fap-color-picker"></td>
                    </tr>
                    <tr>
                        <th>背景色（グラデ2色目）</th>
                        <td><input type="text" name="feat_sticky_bg2" value="<?= esc_attr($sticky_bg2) ?>" class="fap-color-picker">
                            <p class="description">空欄にすると単色になります。</p></td>
                    </tr>
                    <tr>
                        <th>文字色</th>
                        <td><input type="text" name="feat_sticky_color" value="<?= esc_attr($sticky_color) ?>" class="fap-color-picker"></td>
                    </tr>
                    <tr>
                        <th>枠線の色</th>
                        <td><input type="text" name="feat_sticky_border" value="<?= esc_attr($sticky_border) ?>" class="fap-color-picker">
                            <p class="description">空欄にすると枠線なしになります。</p></td>
                    </tr>
                    <tr>
                        <th>角丸</th>
                        <td><?php $this->btn_radius_select('feat_sticky_radius', $sticky_radius) ?></td>
                    </tr>
                </table>
                <?php endif; ?>
            </div>

            <div class="fap-card">
                <h2>⚠️ アフィリエイト注記（PR表記）</h2>
                <table class="form-table">
                    <tr>
                        <th>注記を表示</th>
                        <td>
                            <label><input type="checkbox" name="feat_notice" value="1" <?php checked($notice) ?>>
                            本文先頭にPR表記・年齢制限の注記を表示</label>
                            <p class="description">広告であることのPR表記と年齢制限の注記です。ステルスマーケティング規制の対応に役立ちます。表示が必要かどうかはご自身でご確認ください。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="feat_notice_text">注記の文言</label></th>
                        <td><textarea id="feat_notice_text" name="feat_notice_text" rows="3" class="large-text"><?= esc_textarea($notice_text) ?></textarea></td>
                    </tr>
                </table>
            </div>

            <div class="fap-card">
                <h2>🔁 重複投稿の防止</h2>
                <table class="form-table">
                    <tr>
                        <th>重複スキップ</th>
                        <td>
                            <label><input type="checkbox" name="feat_dedup" value="1" <?php checked($dedup) ?>>
                            同じ作品（CID）が既に投稿済みの場合はスキップする</label>
                            <p class="description">下書き・予約投稿も含めて判定します。通常はONを推奨します。</p>
                        </td>
                    </tr>
                </table>
            </div>

            <p><button type="submit" class="button button-primary button-large">設定を保存</button></p>
        </form>
        <?php
        $this->wrap_end();
    }

    // -----------------------------------------------------------------------
    // ページ：変数タグ一覧
    // -----------------------------------------------------------------------
    public function page_vartags(): void {
        if (!current_user_can('manage_options')) wp_die();
        $this->wrap_start('変数タグ一覧 🏷️');
        ?>
        <p>テンプレート本文・タイトル・抜粋で使用できる変数タグの一覧です。</p>

        <?php
        $plan_badge = [
            'free'    => '<span style="background:#6b7280;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;vertical-align:middle;">全プラン</span>',
            'premium' => '<span style="background:#9333ea;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;vertical-align:middle;">PREMIUM</span>',
        ];

        $sections = [
            '📝 テキスト系 ' . $plan_badge['free'] => [
                ['[title]',          '作品タイトル'],
                ['[title-short]',    '作品タイトル（42文字で省略）'],
                ['[cid]',            '商品ID（content_id）'],
                ['[actress]',        '女優名（複数の場合「・」区切り）'],
                ['[maker]',          'メーカー名'],
                ['[label]',          'レーベル名'],
                ['[director]',       '監督名'],
                ['[series]',         'シリーズ名'],
                ['[author]',         '作者名'],
                ['[genre]',          'ジャンル（スペース区切り）'],
                ['[volume]',         '収録時間・ページ数など'],
                ['[date]',           '発売日（YYYY-MM-DD）'],
                ['[price]',          '価格（税込表示価格）'],
                ['[review-count]',   'レビュー件数'],
                ['[review-average]', 'レビュー平均点'],
                ['[random1]',        'ランダムテキスト1（設定値からランダム選択）'],
                ['[random2]',        'ランダムテキスト2'],
                ['[random3]',        'ランダムテキスト3'],
            ],
            '🖼️ HTML系（本文テンプレートのみ） ' . $plan_badge['free'] => [
                ['[package]',          'パッケージ画像（アフィリエイトリンク付き）'],
                ['[sample-cap]',       'サンプル画像（キャプション付き・リンクあり）'],
                ['[sample-photo]',     'サンプル画像（キャプションなし・リンクあり）'],
                ['[sample-flex]',      'サンプル画像（横並び2列）'],
                ['[sample-nolink]',    'サンプル画像（リンクなし）'],
                ['[sample-no-cap-link]', 'サンプル画像（キャプションなし・リンクなし。[sample-nolink] と同じ表示）'],
                ['[sample-intro]',     'サンプル画像前の説明文（SEO用）'],
                ['[sample-outro]',     'サンプル画像後の締め文（SEO用）'],
                ['[sample-movie]',     'サンプル動画（小・iframeプレーヤー）'],
                ['[sample-movie2]',    'サンプル動画（大・videoタグ）'],
                ['[detail-list]',      '作品情報（ul リスト形式）'],
                ['[detail-table]',     '作品情報（table 形式）'],
                ['[act-info]',         '女優情報（リスト）'],
                ['[act-table]',        '女優情報（テーブル）'],
                ['[aff-button]',       'アフィリエイトボタン1'],
                ['[aff-button2]',      'アフィリエイトボタン2'],
                ['[tachiyomi]',        '立ち読みボタン（電子書籍・試し読みURLに遷移）'],
                ['[review]',           'レビュー星画像（評価0でも表示）'],
                ['[review2]',          'レビュー星画像（評価0は非表示）'],
                ['[aff-link]',         'アフィリエイトURLのみ（テキストリンク）'],
                ['[tachiyomi-link]',   '立ち読みURLのみ（テキストリンク・電子書籍）'],
                ['[aff-card]',         'アフィリエイトリンクカード（パッケージ画像＋作品名）'],
                ['[tachiyomi-card]',   '立ち読みリンクカード（パッケージ画像＋作品名・電子書籍）'],
                ['[aff-url]',          'アフィリエイトURL文字列のみ（自作HTMLの href= に埋め込む用）'],
                ['[tachiyomi-url]',    '立ち読みURL文字列のみ（href= 埋め込み用・電子書籍）'],
                ['[item-url]',         '作品ページURL文字列のみ（href= 埋め込み用）'],
                ['[comment]',          '作品説明文（スクレイピング取得・blockquote表示）'],
                ['[comment-short]',    '作品説明文（200文字以内に短縮・blockquote表示）'],
                ['[related-maker]',    '同じメーカーの関連記事リンクボックス'],
                ['[related-genre]',    '同じジャンルの関連記事リンクボックス'],
                ['[related-new]',      '新着投稿リスト（自動生成・最新5件）'],
            ],
        ];

        foreach ($sections as $heading => $tags):
        ?>
        <div class="fap-card">
            <h2><?= $heading ?></h2>
            <table class="widefat striped" style="font-size:13px;">
                <thead>
                    <tr><th style="width:30%;">変数タグ</th><th>説明</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($tags as [$tag, $desc]): ?>
                    <tr>
                        <td><code><?= esc_html($tag) ?></code></td>
                        <td><?= esc_html($desc) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <div class="fap-card">
            <h2>💰 セール表示 <?= $plan_badge['premium'] ?></h2>
            <p class="description" style="margin-bottom:8px;">
                セール価格・割引率を表示する変数タグです。タグの出力自体は全プランで動作しますが、
                セール中作品だけを投稿する絞り込み（スロットの「セール中のみ」）は <strong>Pro</strong>、
                セール終了時に表示を自動更新する「セール確認」は <strong>Premium</strong> の機能です。
            </p>
            <table class="widefat striped" style="font-size:13px;">
                <thead>
                    <tr><th style="width:30%;">変数タグ</th><th>説明</th></tr>
                </thead>
                <tbody>
                    <?php foreach ([
                        ['[sale-price]',    'セール価格（セール中のみ・通常時は空）。タイトル・抜粋では描画時点の値（タイトルはセール検知で再描画）、本文では表示時点の最新セール状態を自動反映'],
                        ['[discount-rate]', '割引率ラベル（例: 30%OFF）。セール中のみ・通常時は空'],
                        ['[sale_title_1] 〜 [sale_title_5]', 'タイトルテンプレート専用。セール中だけ「セール中タイトル1〜5」の文言に置換（通常時は空）。Premium'],
                        ['[fap_if_sale] 〜 [/fap_if_sale]',         '囲んだ部分をセール中のみ表示（本文：表示時点の状態で自動切替。タイトル・抜粋：描画時点の状態で確定、タイトルはセール検知で再描画）'],
                        ['[fap_if_not_sale] 〜 [/fap_if_not_sale]', '囲んだ部分をセール中でない時のみ表示（本文・タイトル・抜粋）'],
                        ['[sale-history]',  'セール履歴サマリー（過去のセール回数・平均割引率・前回セール月）。履歴が無い作品では非表示。本文のみ有効'],
                    ] as [$tag, $desc]): ?>
                    <tr>
                        <td><code><?= esc_html($tag) ?></code></td>
                        <td><?= esc_html($desc) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        // ランキングテンプレート専用変数（PREMIUM のみ表示）
        if (FAP_License::is('premium')): ?>
        <div class="fap-card">
            <h2>🏆 ランキングテンプレート専用 <span style="background:#9333ea;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;vertical-align:middle;">PREMIUM</span></h2>
            <p class="description" style="margin-bottom:8px;">ランキングテンプレート（投稿タイトル: <code>FAP ランキングテンプレート</code>）の本文のみで使用できる変数タグです。</p>
            <table class="widefat striped" style="font-size:13px;">
                <thead>
                    <tr><th style="width:30%;">変数タグ</th><th>説明</th></tr>
                </thead>
                <tbody>
                    <?php foreach ([
                        ['[rank]',        '順位番号（1, 2, 3…）'],
                        ['[medal]',       'メダル絵文字（🥇🥈🥉 / 4位以下は空）'],
                        ['[rank-label]',  '順位ラベル（例: 🥇 第1位）'],
                        ['[rank-change]', '順位変動バッジ（▲N 上昇 / ▼N 下降 / NEW 初登場 / 変動なしは空）'],
                        ['[year]',        '生成時の年（例: 2026）※ランキングのタイトル・冒頭・締め・各作品テンプレートで使用可'],
                        ['[month]',       '生成時の月（例: 7）※同上'],
                        ['[day]',         '生成時の日（例: 30）※同上'],
                        ['[count]',       'ランキング掲載件数 ※同上'],
                        ['[floor]',       'フロア表示名（ランキング設定で入力した値）※同上'],
                    ] as [$tag, $desc]): ?>
                    <tr>
                        <td><code><?= esc_html($tag) ?></code></td>
                        <td><?= esc_html($desc) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description" style="margin-top:8px;">
                ランキング本文テンプレートでは、上記のほか
                <code>[title]</code> <code>[aff-url]</code> <code>[package]</code> <code>[actress]</code>
                <code>[maker]</code> <code>[genre]</code> <code>[price]</code> <code>[review-average]</code>
                <code>[review-count]</code> <code>[aff-button]</code> <code>[detail-table]</code> <code>[comment]</code>
                が使用できます（それ以外の通常タグはランキング本文では置換されません）。
            </p>
        </div>
        <?php else: ?>
        <div class="fap-card" style="border-left:4px solid #9333ea;">
            <h2>🏆 ランキングテンプレート専用 <span style="background:#9333ea;color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;vertical-align:middle;">PREMIUM</span></h2>
            <?php $this->section_lock('premium', 'ランキングテンプレート専用変数'); ?>
        </div>
        <?php endif;

        $this->wrap_end();
    }

    // -----------------------------------------------------------------------
    // ページ：実行ログ
    // -----------------------------------------------------------------------
    public function page_log(): void {
        if (!current_user_can('manage_options')) wp_die();
        $this->wrap_start('実行ログ 📄');
        ?>
        <div class="fap-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h2 style="margin:0;">📋 実行ログ</h2>
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>"
                      onsubmit="return confirm('ログをクリアしてもよいですか？')">
                    <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
                    <input type="hidden" name="action" value="fap_clear_log">
                    <button type="submit" class="button button-secondary">ログをクリア</button>
                </form>
            </div>
            <?php
            if (file_exists(FAP_LOG_FILE)) {
                $lines = file(FAP_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $lines = array_reverse($lines);
                echo '<div class="fap-log-box"><pre>';
                foreach ($lines as $line) {
                    $escaped = esc_html($line);
                    if (str_contains($line, 'エラー') || str_contains($line, 'error')) {
                        echo '<span class="fap-log-error">' . $escaped . '</span>' . "\n";
                    } elseif (str_contains($line, '投稿:') && str_contains($line, '0')) {
                        echo '<span class="fap-log-warn">' . $escaped . '</span>' . "\n";
                    } else {
                        echo '<span class="fap-log-ok">' . $escaped . '</span>' . "\n";
                    }
                }
                echo '</pre></div>';
            } else {
                echo '<p>ログファイルが存在しません。自動実行または手動実行後に表示されます。</p>';
            }
            ?>
        </div>
        <?php
        $this->wrap_end();
    }

    // -----------------------------------------------------------------------
    // ページ：設定バックアップ
    // -----------------------------------------------------------------------
    public function page_backup(): void {
        if (!current_user_can('manage_options')) wp_die();
        if (!FAP_License::is('premium')) {
            $this->wrap_start('設定の保存/復元 💾');
            $this->upgrade_wall('premium', '設定のバックアップ・復元');
            $this->wrap_end();
            return;
        }
        $this->wrap_start('設定の保存/復元 💾');
        ?>
        <div class="fap-cards fap-cards-2">
            <div class="fap-card">
                <h2>📤 設定をエクスポート</h2>
                <p>スロット設定・SEO設定・スケジュール設定をJSONファイルとしてダウンロードします。</p>
                <p class="description">※ APIキー・アフィリエイトID・ライセンスキー・AIキーはセキュリティのため含まれません。移行時は別途入力が必要です。</p>
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                    <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
                    <input type="hidden" name="action" value="fap_export">
                    <button type="submit" class="button button-primary">設定をダウンロード</button>
                </form>
            </div>
            <div class="fap-card">
                <h2>📥 設定をインポート</h2>
                <p>エクスポートしたJSONファイルを選択して設定を復元します。<strong>現在の設定は上書きされます。</strong></p>
                <p class="description">※ ライセンス情報はインポートされません。認証が必要な場合はライセンスページで再設定してください。</p>
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
                    <input type="hidden" name="action" value="fap_import">
                    <input type="file" name="import_file" accept=".json" required>
                    <br><br>
                    <button type="submit" class="button button-primary"
                            onclick="return confirm('現在の設定が上書きされます。よいですか？')">
                        インポート実行
                    </button>
                </form>
            </div>
        </div>
        <?php
        $this->wrap_end();
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：API設定保存
    // -----------------------------------------------------------------------
    public function handle_save_api(): void {
        $this->verify_nonce();

        $api_id = sanitize_text_field($_POST['fap_api_id'] ?? '');
        $aff_id = sanitize_text_field($_POST['fap_aff_id'] ?? '');

        // スペース除去
        $api_id = preg_replace('/\s+/u', '', $api_id);
        $aff_id = preg_replace('/\s+/u', '', $aff_id);

        // アフィリエイトID末尾チェック
        $aff_suffix = (int) substr($aff_id, -3);
        if ($aff_id && ($aff_suffix < 990 || $aff_suffix > 999)) {
            set_transient('fap_notice_error', 'アフィリエイトIDの末尾3桁は990〜999で設定してください。', 30);
            $this->redirect_back();
        }

        if (!defined('FAP_FANZA_API_ID')) update_option(FAP_PREFIX . 'api_id', $api_id);
        if (!defined('FAP_FANZA_AFF_ID')) update_option(FAP_PREFIX . 'aff_id', $aff_id);

        // APIを検証してフロア一覧を取得
        $verify_api_id = fap_secret('FAP_FANZA_API_ID', 'api_id');
        $verify_aff_id = fap_secret('FAP_FANZA_AFF_ID', 'aff_id');
        if ($verify_api_id && $verify_aff_id && isset($_POST['verify'])) {
            $api    = new FAP_API();
            $result = $api->verify($verify_api_id, $verify_aff_id);
            if ($result['status'] === 'ok') {
                update_option(FAP_PREFIX . 'api_verified', '1');
                update_option(FAP_PREFIX . 'floor_list',   $result['floors']);
                set_transient('fap_notice_success',
                    'APIキーを確認しました。フロア一覧を取得しました（' . count($result['floors']) . '件）。', 30);
            } else {
                update_option(FAP_PREFIX . 'api_verified', '0');
                set_transient('fap_notice_error', 'API検証エラー: ' . $result['message'], 30);
            }
        } else {
            set_transient('fap_notice_success', 'APIキーを保存しました。', 30);
        }

        $this->redirect_back();
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：自動実行設定保存
    // -----------------------------------------------------------------------
    public function handle_save_scheduler(): void {
        $this->verify_nonce();

        $auto_on = isset($_POST['auto_on']) ? '1' : '0';
        update_option(FAP_PREFIX . 'auto_on', $auto_on);

        // 時間帯×スロット設定を保存
        $hour_slots = [];
        for ($h = 0; $h < 24; $h++) {
            $hkey  = 'h' . str_pad($h, 2, '0', STR_PAD_LEFT);
            $slots = isset($_POST['hour_slots'][$hkey]) ? (array) $_POST['hour_slots'][$hkey] : [];
            $clean = [];
            foreach ($slots as $sn) {
                $sn = (int) $sn;
                if ($sn >= 1 && $sn <= FAP_SLOTS) $clean[] = (string) $sn;
            }
            if (!empty($clean)) {
                $hour_slots[$hkey] = $clean;
            }
        }
        update_option(FAP_PREFIX . 'hour_slots', $hour_slots);

        // 実行分（0〜59、5分刻みで送信される）
        $exe_minute = min(59, max(0, (int) ($_POST['exe_minute'] ?? 0)));
        update_option(FAP_PREFIX . 'exe_minute', $exe_minute);

        update_option(FAP_PREFIX . 'date_today',    isset($_POST['date_today'])    ? '1' : '0');
        update_option(FAP_PREFIX . 'date_3day',     isset($_POST['date_3day'])     ? '1' : '0');
        update_option(FAP_PREFIX . 'date_range',    isset($_POST['date_range'])    ? '1' : '0');
        update_option(FAP_PREFIX . 's_date', sanitize_text_field($_POST['s_date'] ?? ''));
        update_option(FAP_PREFIX . 'e_date', sanitize_text_field($_POST['e_date'] ?? ''));
        update_option(FAP_PREFIX . 'sale_check_on',  isset($_POST['sale_check_on'])  ? '1' : '0');
        update_option(FAP_PREFIX . 'sale_rerender',  isset($_POST['sale_rerender'])  ? '1' : '0');
        update_option(FAP_PREFIX . 'sale_badge_on',    isset($_POST['sale_badge_on']) ? '1' : '0');
        update_option(FAP_PREFIX . 'sale_badge_text',  sanitize_text_field($_POST['sale_badge_text'] ?? 'ただいまセール中'));
        update_option(FAP_PREFIX . 'sale_badge_bg',    sanitize_hex_color($_POST['sale_badge_bg'] ?? '') ?: '#e53935');
        update_option(FAP_PREFIX . 'sale_badge_color', sanitize_hex_color($_POST['sale_badge_color'] ?? '') ?: '#ffffff');
        update_option(FAP_PREFIX . 'sale_title_1', sanitize_text_field($_POST['sale_title_1'] ?? '【セール中】'));
        update_option(FAP_PREFIX . 'sale_title_2', sanitize_text_field($_POST['sale_title_2'] ?? ''));
        update_option(FAP_PREFIX . 'sale_title_3', sanitize_text_field($_POST['sale_title_3'] ?? ''));
        update_option(FAP_PREFIX . 'sale_title_4', sanitize_text_field($_POST['sale_title_4'] ?? ''));
        update_option(FAP_PREFIX . 'sale_title_5', sanitize_text_field($_POST['sale_title_5'] ?? ''));
        update_option(FAP_PREFIX . 'sale_category_on',   isset($_POST['sale_category_on']) ? '1' : '0');
        update_option(FAP_PREFIX . 'sale_category_name', sanitize_text_field($_POST['sale_category_name'] ?? 'セール中'));
        update_option(FAP_PREFIX . 'nonsale_category_on',   isset($_POST['nonsale_category_on']) ? '1' : '0');
        update_option(FAP_PREFIX . 'nonsale_category_name', sanitize_text_field($_POST['nonsale_category_name'] ?? '通常価格'));
        update_option(FAP_PREFIX . 'notify_on_error', isset($_POST['notify_on_error']) ? '1' : '0');
        update_option(FAP_PREFIX . 'notify_email',    sanitize_email($_POST['notify_email'] ?? ''));

        // WP-Cronを再スケジュール（exe_minuteを反映するため常に再登録）
        if ($auto_on === '1') {
            FAP_Scheduler::register();
        } else {
            wp_clear_scheduled_hook('fap_hourly_hook');
        }

        set_transient('fap_notice_success', '自動実行設定を保存しました。', 30);
        $this->redirect_back();
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：スロット設定保存
    // -----------------------------------------------------------------------
    public function handle_save_slot(): void {
        $this->verify_nonce();

        $slot = (int) ($_POST['slot'] ?? 0);
        if ($slot < 1 || $slot > FAP_SLOTS) {
            set_transient('fap_notice_error', 'スロット番号が不正です。', 30);
            $this->redirect_back();
        }
        if ($slot > FAP_License::max_slots()) {
            set_transient('fap_notice_error', "スロット {$slot} はご利用のプランでは使用できません。", 30);
            $this->redirect_back();
        }

        $p  = $_POST;
        $s  = FAP_PREFIX . 's' . $slot . '_';

        $fields_text = ['slot_name', 'floor', 'flr_name', 'keyword', 'article_type', 'article_id',
                        'sort', 'post_status', 'post_date', 'eyecatch', 'poster',
                        'movie_size', 'title_tpl', 'title_tpl2', 'title_tpl3',
                        'excerpt_tpl', 'meta_desc_tpl', 'cate_fixed',
                        'tag_fixed', 'exclude_cate', 'exclude_tag',
                        'min_review_avg', 'min_review_cnt',
                        'btn1_text', 'btn1_bg', 'btn1_color', 'btn1_border', 'btn1_size', 'btn1_radius',
                        'btn2_text', 'btn2_bg', 'btn2_color', 'btn2_border', 'btn2_size', 'btn2_radius'];

        foreach ($fields_text as $key) {
            update_option($s . $key, sanitize_text_field($p[$key] ?? ''));
        }

        // 日付フィールドは YYYY-MM-DD 形式のみ保存（不正値は空文字にリセット）
        foreach (['from_date', 'to_date', 'specify_date', 'rand_start', 'rand_end'] as $key) {
            $val = sanitize_text_field($p[$key] ?? '');
            if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $val = '';
            }
            update_option($s . $key, $val);
        }

        $fields_int = ['hits', 'maximage', 'random_time_max'];
        foreach ($fields_int as $key) {
            update_option($s . $key, (int) ($p[$key] ?? 0));
        }

        // チェックボックス（PRO機能）
        update_option($s . 'sale_only',      isset($p['sale_only'])      ? '1' : '0');
        update_option($s . 'random_time_on', isset($p['random_time_on']) ? '1' : '0');

        // テキストエリア（改行保持）
        foreach (['random1', 'random2', 'random3', 'blacklist',
                  'blacklist_genre', 'blacklist_actress', 'blacklist_maker'] as $key) {
            update_option($s . $key, sanitize_textarea_field($p[$key] ?? ''));
        }

        // カテゴリ情報源（配列・複数選択対応）
        $allowed_src = ['genre', 'actress', 'director', 'series', 'maker', 'label', 'author'];
        $cate_src = [];
        if (!empty($p['cate_source']) && is_array($p['cate_source'])) {
            foreach ($p['cate_source'] as $src) {
                if (in_array($src, $allowed_src, true)) $cate_src[] = $src;
            }
        }
        update_option($s . 'cate_source', $cate_src);

        // タグ情報源（配列・複数選択対応）
        $tag_src = [];
        if (!empty($p['tag_source']) && is_array($p['tag_source'])) {
            foreach ($p['tag_source'] as $src) {
                if (in_array($src, $allowed_src, true)) $tag_src[] = $src;
            }
        }
        update_option($s . 'tag_source', $tag_src);

        // スロット有効化
        update_option($s . 'active', isset($p['slot_active']) ? '1' : '0');

        // ジャンル別テンプレート（PREMIUM）
        if (FAP_License::is('premium')) {
            $genre_tpls = [];
            foreach ((array) ($p['genre_tpls'] ?? []) as $entry) {
                $g = sanitize_text_field($entry['genre'] ?? '');
                $t = sanitize_text_field($entry['tpl']   ?? '');
                if ($g && $t) $genre_tpls[] = ['genre' => $g, 'tpl' => $t];
            }
            update_option($s . 'genre_tpls', $genre_tpls);
        }

        $saved_active = isset($p['slot_active']);
        if ($saved_active) {
            $hour_slots = (array) fap_opt('hour_slots', []);
            $in_schedule = false;
            foreach ($hour_slots as $slots_in_hour) {
                if (in_array((string) $slot, array_map('strval', (array) $slots_in_hour), true)) {
                    $in_schedule = true;
                    break;
                }
            }
            if (!$in_schedule) {
                set_transient('fap_notice_info', "スロット {$slot} を稼働ONにしましたが、<a href=\"" . admin_url('admin.php?page=' . FAP_SLUG . '-sched') . "\">自動実行設定</a>の「時間帯 × スロット」表にまだ登録されていません。自動実行するには両方の設定が必要です。", 30);
            }
        }

        set_transient('fap_notice_success', "スロット {$slot} の設定を保存しました。", 30);
        $this->redirect_back();
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：SEO設定保存
    // -----------------------------------------------------------------------
    public function handle_save_seo(): void {
        $this->verify_nonce();

        update_option(FAP_PREFIX . 'seo_jsonld',       isset($_POST['seo_jsonld'])   ? '1' : '0');
        update_option(FAP_PREFIX . 'seo_og',           isset($_POST['seo_og'])       ? '1' : '0');
        update_option(FAP_PREFIX . 'seo_twitter',      isset($_POST['seo_twitter'])  ? '1' : '0');
        update_option(FAP_PREFIX . 'seo_sitemap',      isset($_POST['seo_sitemap'])  ? '1' : '0');
        update_option(FAP_PREFIX . 'seo_breadcrumb',   isset($_POST['seo_breadcrumb']) ? '1' : '0');

        $card = sanitize_text_field($_POST['seo_twitter_card'] ?? 'summary_large_image');
        if (!in_array($card, ['summary', 'summary_large_image'], true)) $card = 'summary_large_image';
        update_option(FAP_PREFIX . 'seo_twitter_card', $card);

        // サイトマップ変更時はリライトルールを更新
        flush_rewrite_rules();

        set_transient('fap_notice_success', 'SEO設定を保存しました。', 30);
        $this->redirect_back();
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：表示・機能設定
    // -----------------------------------------------------------------------
    public function handle_save_features(): void {
        $this->verify_nonce();

        update_option(FAP_PREFIX . 'feat_sticky', isset($_POST['feat_sticky']) ? '1' : '0');
        update_option(FAP_PREFIX . 'feat_notice', isset($_POST['feat_notice']) ? '1' : '0');
        update_option(FAP_PREFIX . 'feat_dedup',  isset($_POST['feat_dedup'])  ? '1' : '0');

        update_option(FAP_PREFIX . 'feat_sticky_text', sanitize_text_field($_POST['feat_sticky_text'] ?? ''));
        update_option(FAP_PREFIX . 'feat_notice_text', sanitize_textarea_field($_POST['feat_notice_text'] ?? ''));

        // 追従CTAのデザイン
        update_option(FAP_PREFIX . 'feat_sticky_bg',    sanitize_hex_color($_POST['feat_sticky_bg'] ?? '')  ?: '#e0306e');
        update_option(FAP_PREFIX . 'feat_sticky_bg2',   sanitize_hex_color($_POST['feat_sticky_bg2'] ?? '') ?: '');
        update_option(FAP_PREFIX . 'feat_sticky_color', sanitize_hex_color($_POST['feat_sticky_color'] ?? '') ?: '#ffffff');
        update_option(FAP_PREFIX . 'feat_sticky_border', sanitize_hex_color($_POST['feat_sticky_border'] ?? '') ?: '');
        $sradius = sanitize_text_field($_POST['feat_sticky_radius'] ?? 'medium');
        if (!in_array($sradius, ['none', 'small', 'medium', 'large', 'pill'], true)) $sradius = 'medium';
        update_option(FAP_PREFIX . 'feat_sticky_radius', $sradius);

        set_transient('fap_notice_success', '表示・機能設定を保存しました。', 30);
        $this->redirect_back();
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：クイック実行
    // -----------------------------------------------------------------------
    public function handle_quick_post(): void {
        $this->verify_nonce();

        $slot = (int) ($_POST['slot'] ?? 0);
        if ($slot < 1 || $slot > FAP_SLOTS) wp_die('スロット番号が不正です');
        if ($slot > FAP_License::max_slots()) {
            set_transient('fap_notice_error', "スロット {$slot} はご利用のプランでは使用できません。ライセンスページからアップグレードしてください。", 30);
            $this->redirect_back();
        }

        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $api    = new FAP_API();
        $poster = new FAP_Poster($api);
        $result = $poster->run_slot($slot, 'manual');

        fap_log("[QuickPost][Slot {$slot}] posted:{$result['posted']} skipped:{$result['skipped']}" .
                ($result['error'] ? ' error:' . $result['error'] : ''));

        $msg = "スロット {$slot} 実行完了：投稿 {$result['posted']} 件、スキップ {$result['skipped']} 件";
        if ($result['error']) $msg .= '（' . $result['error'] . '）';

        set_transient('fap_notice_info', esc_html($msg), 30);
        $this->redirect_back();
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：商品IDを指定して投稿
    // -----------------------------------------------------------------------
    public function handle_cid_post(): void {
        $this->verify_nonce();

        $slot = (int) ($_POST['slot'] ?? 0);
        if ($slot < 1 || $slot > FAP_SLOTS) wp_die('スロット番号が不正です');
        if ($slot > FAP_License::max_slots()) {
            set_transient('fap_notice_error', "スロット {$slot} はご利用のプランでは使用できません。ライセンスページからアップグレードしてください。", 30);
            $this->redirect_back();
        }

        // 区切り（改行・空白・カンマ）で分割。FANZAのURL（…/cid=xxxx/）を貼っても、IDを取り出す
        $raw    = (string) wp_unslash($_POST['cids'] ?? '');
        $cids   = [];
        $errors = [];
        foreach (preg_split('/[\s,、，]+/u', trim($raw)) as $token) {
            if ($token === '') continue;
            if (preg_match('/cid=([A-Za-z0-9_\-]+)/i', $token, $m)) {
                $cids[] = $m[1];
            } elseif (preg_match('/^[A-Za-z0-9_\-]+$/', $token)) {
                $cids[] = $token;
            } else {
                $errors[] = $token . ': 商品IDの形式が正しくありません';
            }
        }
        $cids = array_values(array_unique($cids));

        if (!$cids && !$errors) {
            set_transient('fap_notice_error', '商品IDを入力してください。', 30);
            $this->redirect_back();
        }
        if (count($cids) > 20) {
            set_transient('fap_notice_error', '一度に指定できる商品IDは20件までです。', 30);
            $this->redirect_back();
        }

        $posted = 0;
        if ($cids) {
            set_time_limit(600);
            ini_set('memory_limit', '512M');

            $poster = new FAP_Poster(new FAP_API());
            $result = $poster->run_cids($slot, $cids);
            $posted = (int) $result['posted'];
            $errors = array_merge($errors, $result['errors']);
        }

        if ($posted > 0) {
            set_transient('fap_notice_info', esc_html("スロット {$slot}：商品ID指定で {$posted} 件を投稿しました。"), 30);
        }
        if ($errors) {
            set_transient('fap_notice_error', implode(' ／ ', $errors), 30);
        }
        $this->redirect_back();
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：ログクリア
    // -----------------------------------------------------------------------
    public function handle_clear_log(): void {
        $this->verify_nonce();
        if (file_exists(FAP_LOG_FILE)) {
            file_put_contents(FAP_LOG_FILE, '', LOCK_EX);
        }
        set_transient('fap_notice_success', 'ログをクリアしました。', 30);
        $this->redirect_back();
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：エクスポート
    // -----------------------------------------------------------------------
    public function handle_export(): void {
        $this->verify_nonce();

        // 機密情報はエクスポートに含めない
        $exclude = [
            'fap_api_id',
            'fap_aff_id',
            'fap_license_key',
            'fap_ai_api_key',
        ];

        $data = ['version' => FAP_VERSION, 'exported_at' => current_time('c'), 'options' => []];

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'fap_%'",
            ARRAY_A
        );
        foreach ($rows as $row) {
            if (in_array($row['option_name'], $exclude, true)) continue;
            $data['options'][$row['option_name']] = maybe_unserialize($row['option_value']);
        }

        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $filename = 'fap-settings-' . date('Y-m-d') . '.json';

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：インポート
    // -----------------------------------------------------------------------
    public function handle_import(): void {
        $this->verify_nonce();

        if (empty($_FILES['import_file']['tmp_name'])) {
            set_transient('fap_notice_error', 'ファイルが選択されていません。', 30);
            $this->redirect_back();
        }

        // ファイルサイズ上限 512KB
        if ($_FILES['import_file']['size'] > 524288) {
            set_transient('fap_notice_error', 'ファイルサイズが大きすぎます（上限: 512KB）。', 30);
            $this->redirect_back();
        }

        // 拡張子チェック（.json のみ許可）
        $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'json') {
            set_transient('fap_notice_error', 'JSONファイル（.json）のみインポートできます。', 30);
            $this->redirect_back();
        }

        $json = file_get_contents($_FILES['import_file']['tmp_name']);
        $data = json_decode($json, true);

        if (!$data || !isset($data['options'])) {
            set_transient('fap_notice_error', '無効なJSONファイルです。', 30);
            $this->redirect_back();
        }

        // ライセンス関連・認証情報キーはインポートで上書き不可
        $import_deny = [
            'fap_license_plan',
            'fap_license_key',
            'fap_license_expires',
            'fap_license_domain',
            'fap_license_verified_at',
            'fap_api_id',
            'fap_aff_id',
            'fap_ai_api_key',
        ];

        $count = 0;
        foreach ($data['options'] as $key => $val) {
            if (!str_starts_with($key, 'fap_')) continue;
            if (in_array($key, $import_deny, true)) continue;
            update_option($key, $val);
            $count++;
        }

        set_transient('fap_notice_success', "設定をインポートしました（{$count}項目）。", 30);
        $this->redirect_back();
    }

    // -----------------------------------------------------------------------
    // AJAX：APIテスト
    // -----------------------------------------------------------------------
    public function ajax_test_api(): void {
        check_ajax_referer('fap_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('権限エラー');

        $api_id = sanitize_text_field($_POST['api_id'] ?? fap_secret('FAP_FANZA_API_ID', 'api_id'));
        $aff_id = sanitize_text_field($_POST['aff_id'] ?? fap_secret('FAP_FANZA_AFF_ID', 'aff_id'));

        if (!$api_id || !$aff_id) {
            wp_send_json_error('APIキーを入力してください。');
        }

        $api    = new FAP_API();
        $result = $api->verify($api_id, $aff_id);

        if ($result['status'] === 'ok') {
            wp_send_json_success('✅ API接続成功！ フロア数: ' . count($result['floors']) . '件');
        } else {
            wp_send_json_error('❌ ' . $result['message']);
        }
    }

    // -----------------------------------------------------------------------
    // AJAX：テンプレートのプレビュー
    // -----------------------------------------------------------------------
    public function ajax_preview(): void {
        check_ajax_referer('fap_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('権限エラー');
        if (!FAP_License::is('pro')) {
            wp_send_json_error('プレビュー機能はProプラン以上で使用できます。ライセンスページからアップグレードしてください。');
        }

        $slot = (int) ($_POST['slot'] ?? 0);
        if ($slot < 1 || $slot > FAP_SLOTS) wp_send_json_error('スロット番号が不正です。');

        set_time_limit(120);

        $api    = new FAP_API();
        $poster = new FAP_Poster($api);
        $res    = $poster->preview_slot($slot);

        if (empty($res['ok'])) {
            wp_send_json_error($res['msg'] ?? 'プレビューの生成に失敗しました。');
        }
        wp_send_json_success([
            'title'   => $res['title'],
            'excerpt' => $res['excerpt'],
            'content' => $res['content'],
            'cid'     => $res['cid'],
            'css'     => wp_get_custom_css(),
        ]);
    }

    // -----------------------------------------------------------------------
    // AJAX：フロア一覧リフレッシュ
    // -----------------------------------------------------------------------
    public function ajax_refresh_floors(): void {
        check_ajax_referer('fap_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('権限エラー');

        $api_id = fap_secret('FAP_FANZA_API_ID', 'api_id');
        $aff_id = fap_secret('FAP_FANZA_AFF_ID', 'aff_id');

        if (!$api_id || !$aff_id) {
            wp_send_json_error('まずAPI設定でAPIキーを保存してください。');
        }

        $api    = new FAP_API();
        $result = $api->verify($api_id, $aff_id);

        if ($result['status'] === 'ok') {
            update_option(FAP_PREFIX . 'floor_list',   $result['floors']);
            update_option(FAP_PREFIX . 'api_verified', '1');
            wp_send_json_success('フロア一覧を更新しました（' . count($result['floors']) . '件）。');
        } else {
            wp_send_json_error($result['message']);
        }
    }

    // -----------------------------------------------------------------------
    // 共通ヘルパー
    // -----------------------------------------------------------------------
    private function wrap_start(string $title): void {
        echo '<div class="wrap fap-wrap">';
        echo '<div class="fap-header">';
        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<p class="fap-subtitle">FANZA SEO Autoposter <span class="fap-version">v' . FAP_VERSION . '</span></p>';
        echo '</div>';
        $this->render_nav();
    }

    // 横タブナビ（主要セクションへの素早い移動）
    private function render_nav(): void {
        $current = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : FAP_SLUG;
        $is_pro     = FAP_License::is('pro');
        $is_premium = FAP_License::is('premium');

        // [slug => [label, icon, min_plan|null]]
        $items = [
            FAP_SLUG               => ['ダッシュボード', 'dashicons-dashboard',     null],
            FAP_SLUG . '-api'      => ['API設定',        'dashicons-admin-network', null],
            FAP_SLUG . '-sched'    => ['自動実行',       'dashicons-clock',         'pro'],
            FAP_SLUG . '-slot1'    => ['スロット',       'dashicons-screenoptions', null],
            FAP_SLUG . '-seo'      => ['SEO',            'dashicons-chart-line',    'pro'],
            FAP_SLUG . '-features' => ['表示・機能',     'dashicons-art',           null],
            FAP_SLUG . '-vartags'  => ['変数タグ',       'dashicons-editor-code',   null],
            FAP_SLUG . '-backup'   => ['保存/復元',      'dashicons-backup',        'premium'],
            FAP_SLUG . '-ranking'  => ['ランキング',     'dashicons-awards',        'premium'],
            FAP_SLUG . '-ctr'      => ['CTR分析',        'dashicons-chart-bar',     'premium'],
            FAP_SLUG . '-ai'       => ['AI設定',         'dashicons-superhero',     'premium'],
            FAP_SLUG . '-history'  => ['投稿履歴',       'dashicons-backup',        'pro'],
            FAP_SLUG . '-license'  => ['ライセンス',     'dashicons-lock',          null],
            FAP_SLUG . '-log'      => ['実行ログ',       'dashicons-list-view',     null],
        ];

        echo '<nav class="fap-nav" aria-label="FANZA Autoposter メニュー">';
        foreach ($items as $slug => [$label, $icon, $min_plan]) {
            // スロットは slot1〜8 のいずれでもアクティブ扱い
            if ($slug === FAP_SLUG . '-slot1') {
                $active = str_starts_with($current, FAP_SLUG . '-slot');
            } else {
                $active = ($current === $slug);
            }
            $url = admin_url('admin.php?page=' . $slug);

            // プランバッジ（未解放の場合のみ表示）
            $badge_html = '';
            if ($min_plan === 'pro' && !$is_pro) {
                $badge_html = '<span class="fap-nav-badge fap-nav-badge-pro">Pro</span>';
            } elseif ($min_plan === 'premium' && !$is_premium) {
                $badge_html = '<span class="fap-nav-badge fap-nav-badge-premium">PRE</span>';
            }

            echo '<a class="fap-nav__item' . ($active ? ' is-active' : '') . '"'
               . ' href="' . esc_url($url) . '"'
               . ' aria-label="' . esc_attr($label) . '">'
               . '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>'
               . '<span>' . esc_html($label) . '</span>'
               . $badge_html
               . '</a>';
        }
        echo '</nav>';
    }

    private function wrap_end(): void {
        echo '<div class="fap-footer-help">';
        echo '<a href="https://autoposter.jp/fanza-seo-autoposter-setup-guide.html" target="_blank" rel="noopener">📖 セットアップ手順書</a>';
        echo '<span class="fap-footer-help__sep">|</span>';
        echo '<a href="mailto:autoposterseo@gmail.com">✉ サポートへ問い合わせ</a>';
        echo '</div>';
        echo '</div>';
    }

    // スロット表示名（ユーザー設定が無ければ「スロットN」）
    private function slot_label(int $n): string {
        $name = (string) fap_slot_opt($n, 'slot_name', '');
        return $name !== '' ? $name : 'スロット' . $n;
    }

    private function btn_size_select(string $name, string $current): void {
        $opts = ['small' => '小', 'medium' => '中', 'large' => '大'];
        echo '<select name="' . esc_attr($name) . '">';
        foreach ($opts as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($current, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    private function btn_radius_select(string $name, string $current): void {
        $opts = ['none' => '角なし', 'small' => '小', 'medium' => '中', 'large' => '大', 'pill' => '丸（ピル）'];
        echo '<select name="' . esc_attr($name) . '">';
        foreach ($opts as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($current, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    private function stat_card(string $label, string $value, string $icon, string $color): void {
        echo '<div class="fap-stat-card">';
        echo '<div class="fap-stat-icon" style="color:' . esc_attr($color) . ';">';
        echo '<span class="dashicons ' . esc_attr($icon) . '"></span>';
        echo '</div>';
        echo '<div class="fap-stat-body">';
        echo '<div class="fap-stat-value" style="color:' . esc_attr($color) . ';">' . esc_html($value) . '</div>';
        echo '<div class="fap-stat-label">' . esc_html($label) . '</div>';
        echo '</div>';
        echo '</div>';
    }

    private function render_log_tail(int $lines_count): void {
        if (!file_exists(FAP_LOG_FILE)) {
            echo '<p>ログがありません。</p>';
            return;
        }
        $lines = file(FAP_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice(array_reverse($lines), 0, $lines_count);
        if (empty($lines)) {
            echo '<p>ログがありません。</p>';
            return;
        }
        echo '<div class="fap-log-box" style="max-height:200px;"><pre>';
        foreach ($lines as $line) {
            echo esc_html($line) . "\n";
        }
        echo '</pre></div>';
    }

    protected function verify_nonce(): void {
        if (!isset($_POST['_fap_nonce']) || !wp_verify_nonce($_POST['_fap_nonce'], FAP_NONCE)) {
            wp_die('セキュリティチェックに失敗しました。');
        }
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。');
        }
    }

    protected function redirect_back(): void {
        $referer = wp_get_referer();
        wp_safe_redirect($referer ?: admin_url('admin.php?page=' . FAP_SLUG));
        exit;
    }

    // -----------------------------------------------------------------------
    // ページ：ランキング記事設定
    // -----------------------------------------------------------------------
    public function page_ranking(): void {
        if (!current_user_can('manage_options')) wp_die();
        if (!FAP_License::is('premium')) {
            $this->wrap_start('ランキング記事設定 🏆');
            $this->upgrade_wall('premium', 'ランキング記事自動生成');
            $this->wrap_end();
            return;
        }

        $floors = FAP_API::get_floor_list();

        // ランキングスロット（1〜4）。1は従来キー、2〜4は ranking{n}_ プレフィックス
        $rk  = max(1, min(4, (int) ($_GET['rk'] ?? 1)));
        $pfx = $rk <= 1 ? 'ranking_' : 'ranking' . $rk . '_';

        $last_at = fap_opt($pfx . 'last_at', '');

        $v = [
            'floor'        => fap_opt($pfx . 'floor',        ''),
            'flr_name'     => fap_opt($pfx . 'flr_name',     ''),
            'sort'         => fap_opt($pfx . 'sort',         'rank'),
            'count'        => fap_opt($pfx . 'count',        10),
            'title_tpl'    => fap_opt($pfx . 'title_tpl',    '[year]年[month]月 FANZAランキング TOP[count]'),
            'post_status'  => fap_opt($pfx . 'post_status',  'publish'),
            'cate_fixed'   => fap_opt($pfx . 'cate_fixed',   ''),
            'tag_fixed'    => fap_opt($pfx . 'tag_fixed',    ''),
            'schedule'     => fap_opt($pfx . 'schedule',     'none'),
            'weekly_day'   => fap_opt($pfx . 'weekly_day',   1),
            'fetch_desc'   => fap_opt($pfx . 'fetch_desc',   '0'),
            'sale_only'    => fap_opt($pfx . 'sale_only',    '0'),
        ];

        // ランキングテンプレート投稿の確認（スロット2〜4は番号付き→共通の順で探す）
        global $wpdb;
        $tpl_names = [];
        if ($rk > 1) $tpl_names[] = 'FAP ランキングテンプレート' . $rk;
        $tpl_names[] = 'FAP ランキングテンプレート';
        $ranking_tpl_label = '❌ 未設定（未作成）';
        foreach ($tpl_names as $tpl_name) {
            $tpl_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_title = %s AND post_type = 'post' AND post_status = 'draft'
                     ORDER BY post_date DESC LIMIT 1",
                    $tpl_name
                )
            );
            if ($tpl_id) {
                $ranking_tpl_label = '✅ 「' . $tpl_name . '」を使用（ID: ' . $tpl_id . '）';
                break;
            }
        }

        $this->wrap_start('ランキング記事設定 🏆');
        ?>
        <h2 class="nav-tab-wrapper" style="margin-bottom:16px;">
            <?php for ($i = 1; $i <= 4; $i++):
                $ip = $i <= 1 ? 'ranking_' : 'ranking' . $i . '_';
                $active_mark = fap_opt($ip . 'schedule', 'none') !== 'none' ? ' ⏱' : '';
            ?>
            <a class="nav-tab <?= $i === $rk ? 'nav-tab-active' : '' ?>" href="<?= esc_url(add_query_arg('rk', $i)) ?>">
                スロット<?= $i ?><?= $active_mark ?>
            </a>
            <?php endfor; ?>
        </h2>
        <p class="description" style="margin-bottom:16px;">スロットごとに独立した設定・スケジュールでランキング記事を自動生成できます（⏱＝スケジュール設定済み）。</p>
        <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline;">
                <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
                <input type="hidden" name="action" value="fap_ranking_post">
                <input type="hidden" name="rk" value="<?= (int) $rk ?>">
                <button type="submit" class="button button-primary"
                        onclick="return confirm('今すぐスロット<?= (int) $rk ?>のランキング記事を生成しますか？')">
                    📊 今すぐランキング記事を生成
                </button>
            </form>
            <button type="button" class="button" id="fap-ranking-preview-btn">👁 プレビュー（上位3件）</button>
            <?php if ($last_at): ?>
            <span class="description">前回生成：<strong><?= esc_html($last_at) ?></strong></span>
            <?php endif; ?>
        </div>

        <div id="fap-ranking-preview-area" style="display:none;margin-bottom:24px;padding:20px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;">
            <h3 style="margin-top:0;border-bottom:1px solid #eee;padding-bottom:8px;">
                プレビュー <span id="fap-ranking-preview-note" style="font-size:13px;color:#666;font-weight:normal;"></span>
            </h3>
            <div id="fap-ranking-preview-body"></div>
        </div>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
            <input type="hidden" name="action" value="fap_save_ranking">
            <input type="hidden" name="rk" value="<?= (int) $rk ?>">

            <div class="fap-card">
                <h2>🔍 取得設定（スロット<?= (int) $rk ?>）</h2>
                <table class="form-table">
                    <tr>
                        <th>フロア選択</th>
                        <td>
                            <?php if (empty($floors)): ?>
                                <p class="description" style="color:#d63638;">⚠️ まず「API設定」でAPIキーを保存・検証してください。</p>
                            <?php else: ?>
                                <select name="ranking_floor" id="fap-ranking-floor-select">
                                    <option value="">-- フロアを選択 --</option>
                                    <?php
                                    $groups = [];
                                    foreach ($floors as $f) $groups[$f['service_name']][] = $f;
                                    foreach ($groups as $svc => $items):
                                    ?>
                                    <optgroup label="<?= esc_attr($svc) ?>">
                                        <?php foreach ($items as $f): ?>
                                        <option value="<?= esc_attr($f['key']) ?>"
                                                data-label="<?= esc_attr($f['label']) ?>"
                                                <?php selected($v['floor'], $f['key']) ?>>
                                            <?= esc_html($f['floor_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="ranking_flr_name" id="fap-ranking-flr-name" value="<?= esc_attr($v['flr_name']) ?>">
                                <?php if ($v['flr_name']): ?>
                                <p class="description">現在: <strong><?= esc_html($v['flr_name']) ?></strong></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>ソート順</th>
                        <td>
                            <select name="ranking_sort">
                                <?php foreach (['rank'=>'人気順', 'review'=>'評価順'] as $val => $lbl): ?>
                                <option value="<?= $val ?>" <?php selected($v['sort'], $val) ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>ランキング件数</th>
                        <td>
                            <select name="ranking_count">
                                <?php foreach ([5=>'TOP5', 10=>'TOP10', 20=>'TOP20'] as $val => $lbl): ?>
                                <option value="<?= $val ?>" <?php selected((int)$v['count'], $val) ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>セール中の作品に限定</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ranking_sale_only" value="1" <?php checked($v['sale_only'], '1') ?>>
                                セール中の作品だけでランキングを作る
                            </label>
                            <p class="description">セール特化サイト向け。該当作品が少ない場合はランキング件数が設定より減ることがあります。</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="fap-card">
                <h2>📝 投稿設定</h2>
                <table class="form-table">
                    <tr>
                        <th>タイトルテンプレート</th>
                        <td>
                            <input type="text" name="ranking_title_tpl" value="<?= esc_attr($v['title_tpl']) ?>" class="large-text">
                            <p class="description">使える変数: <code>[year]</code>（年）、<code>[month]</code>（月）、<code>[count]</code>（件数）、<code>[floor]</code>（フロア名）</p>
                        </td>
                    </tr>
                    <tr>
                        <th>投稿ステータス</th>
                        <td>
                            <?php foreach (['publish'=>'公開（publish）', 'draft'=>'下書き（draft）'] as $val => $lbl): ?>
                            <label style="margin-right:16px;">
                                <input type="radio" name="ranking_post_status" value="<?= $val ?>" <?php checked($v['post_status'], $val) ?>>
                                <?= $lbl ?>
                            </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>固定カテゴリ<br><small>/区切りで複数指定</small></th>
                        <td>
                            <input type="text" name="ranking_cate_fixed" value="<?= esc_attr($v['cate_fixed']) ?>" class="regular-text" placeholder="例: ランキング/FANZA">
                        </td>
                    </tr>
                    <tr>
                        <th>固定タグ<br><small>/区切りで複数指定</small></th>
                        <td>
                            <input type="text" name="ranking_tag_fixed" value="<?= esc_attr($v['tag_fixed']) ?>" class="regular-text" placeholder="例: ランキング/FANZA">
                        </td>
                    </tr>
                    <tr>
                        <th>説明文を取得する<br><small>（処理時間が増加します）</small></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ranking_fetch_desc" value="1" <?php checked($v['fetch_desc'], '1') ?>>
                                各商品の説明文を最大200文字で追加する
                            </label>
                            <p class="description">動画フロアはGraphQL API、その他はスクレイピングで取得します。ONにすると生成に時間がかかります。</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="fap-card">
                <h2>📄 ランキングテンプレート</h2>
                <p>状態: <strong><?= esc_html($ranking_tpl_label) ?></strong></p>
                <p>
                    <a href="<?= esc_url(admin_url('post-new.php?post_type=post')) ?>"
                       class="button" target="_blank">新しい下書き投稿を作成（テンプレート用）</a>
                    <?php if ($ranking_tpl_post_id): ?>
                    &nbsp;<a href="<?= esc_url(get_edit_post_link($ranking_tpl_post_id)) ?>"
                              class="button" target="_blank">テンプレートを編集</a>
                    <?php endif; ?>
                </p>
                <p class="description">
                    投稿タイトルを <code>FAP ランキングテンプレート<?= $rk > 1 ? (int) $rk : '' ?></code>、ステータスを「下書き」にした投稿の本文がテンプレートとして使われます。<?php if ($rk > 1): ?>（無ければ共通の <code>FAP ランキングテンプレート</code> を使用）<?php endif; ?><br>
                    冒頭・締めの文章は、<code>FAP ランキング冒頭テンプレート<?= $rk > 1 ? (int) $rk : '' ?></code> / <code>FAP ランキング締めテンプレート<?= $rk > 1 ? (int) $rk : '' ?></code> という下書きで設定できます<?php if ($rk > 1): ?>（無ければ番号なしの共通版を使用）<?php endif; ?>。<br>
                    テンプレートなしの場合は固定レイアウトで生成されます。<br>
                    使える変数: <code>[rank]</code> <code>[medal]</code> <code>[rank-label]</code> <code>[rank-change]</code> <code>[title]</code> <code>[aff-url]</code>
                    <code>[package]</code> <code>[actress]</code> <code>[maker]</code> <code>[genre]</code>
                    <code>[price]</code> <code>[review-average]</code> <code>[review-count]</code>
                    <code>[aff-button]</code> <code>[detail-table]</code> <code>[comment]</code>
                </p>
            </div>

            <div class="fap-card">
                <h2>⏱️ 自動生成スケジュール</h2>
                <table class="form-table">
                    <tr>
                        <th>スケジュール</th>
                        <td>
                            <?php foreach (['none'=>'自動生成しない', 'weekly'=>'毎週', 'monthly'=>'毎月1日'] as $val => $lbl): ?>
                            <label style="display:block;margin-bottom:4px;">
                                <input type="radio" name="ranking_schedule" value="<?= $val ?>" <?php checked($v['schedule'], $val) ?>>
                                <?= esc_html($lbl) ?>
                            </label>
                            <?php endforeach; ?>
                            <p class="description">自動実行スケジューラー（自動実行設定ページ）がONのときのみ動作します。</p>
                        </td>
                    </tr>
                    <tr>
                        <th>曜日<br><small>（毎週のとき）</small></th>
                        <td>
                            <select name="ranking_weekly_day">
                                <?php
                                $days = [1=>'月曜日', 2=>'火曜日', 3=>'水曜日', 4=>'木曜日', 5=>'金曜日', 6=>'土曜日', 7=>'日曜日'];
                                foreach ($days as $val => $lbl):
                                ?>
                                <option value="<?= $val ?>" <?php selected((int)$v['weekly_day'], $val) ?>><?= esc_html($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <p><button type="submit" class="button button-primary button-large">設定を保存</button></p>
        </form>

        <script>
        (function($){
            $('#fap-ranking-floor-select').on('change', function(){
                var opt = $(this).find(':selected');
                $('#fap-ranking-flr-name').val(opt.data('label') || '');
            });
        })(jQuery);
        </script>
        <?php
        $this->wrap_end();
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：ランキング設定保存
    // -----------------------------------------------------------------------
    public function handle_save_ranking(): void {
        $this->verify_nonce();
        if (!FAP_License::is('premium')) {
            wp_die('この機能はプレミアムプラン専用です。');
        }

        // 保存先スロット（フォーム項目名は共通、保存先オプションキーだけ切り替える）
        $rk  = max(1, min(4, (int) ($_POST['rk'] ?? 1)));
        $pfx = $rk <= 1 ? 'ranking_' : 'ranking' . $rk . '_';

        $text_fields = ['floor', 'flr_name', 'sort',
                        'title_tpl', 'post_status',
                        'cate_fixed', 'tag_fixed', 'schedule'];
        foreach ($text_fields as $key) {
            update_option(FAP_PREFIX . $pfx . $key, sanitize_text_field($_POST['ranking_' . $key] ?? ''));
        }

        update_option(FAP_PREFIX . $pfx . 'count',      (int) ($_POST['ranking_count']      ?? 10));
        update_option(FAP_PREFIX . $pfx . 'weekly_day', (int) ($_POST['ranking_weekly_day'] ?? 1));
        update_option(FAP_PREFIX . $pfx . 'fetch_desc', isset($_POST['ranking_fetch_desc']) ? '1' : '0');
        update_option(FAP_PREFIX . $pfx . 'sale_only',  isset($_POST['ranking_sale_only'])  ? '1' : '0');

        set_transient('fap_notice_success', 'ランキング記事設定（スロット' . $rk . '）を保存しました。', 30);
        $this->redirect_back();
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：ランキング記事 手動生成
    // -----------------------------------------------------------------------
    public function handle_ranking_post(): void {
        $this->verify_nonce();

        if (!FAP_License::is('premium')) {
            set_transient('fap_notice_error', 'Premiumプランが必要です。', 30);
            $this->redirect_back();
        }

        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $rk     = max(1, min(4, (int) ($_POST['rk'] ?? 1)));
        $api    = new FAP_API();
        $poster = new FAP_Poster($api);
        $result = $poster->run_ranking($rk);

        if ($result['ok']) {
            $edit_link = get_edit_post_link($result['post_id']);
            $msg = 'ランキング記事（スロット' . $rk . '）を生成しました（' . (int)$result['count'] . '件）。'
                 . '<a href="' . esc_url($edit_link) . '" target="_blank">投稿を確認する</a>';
            set_transient('fap_notice_info', $msg, 30);
        } else {
            set_transient('fap_notice_error', 'ランキング記事の生成に失敗しました：' . esc_html($result['msg']), 30);
        }

        $this->redirect_back();
    }

    // -----------------------------------------------------------------------
    // AJAX：ランキング記事プレビュー（上位3件、説明文取得スキップ）
    // -----------------------------------------------------------------------
    public function ajax_ranking_preview(): void {
        check_ajax_referer('fap_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('権限がありません。');
        if (!FAP_License::is('premium'))         wp_send_json_error('Premiumプランが必要です。');

        $api_id = fap_secret('FAP_FANZA_API_ID', 'api_id');
        $aff_id = fap_secret('FAP_FANZA_AFF_ID', 'aff_id');
        if (!$api_id || !$aff_id) wp_send_json_error('APIキーが設定されていません。');

        $rk  = max(1, min(4, (int) ($_POST['rk'] ?? 1)));
        $pfx = $rk <= 1 ? 'ranking_' : 'ranking' . $rk . '_';

        $floor_key = fap_opt($pfx . 'floor', '');
        if (!$floor_key) wp_send_json_error('ランキングのフロアが未設定です。');

        $fp = FAP_API::parse_floor_key($floor_key);
        if (!$fp['site'] || !$fp['service'] || !$fp['floor']) wp_send_json_error('フロアキーが不正です。');

        $api    = new FAP_API();
        $params = [
            'api_id'     => $api_id,
            'aff_id'     => $aff_id,
            'site'       => $fp['site'],
            'service'    => $fp['service'],
            'floor'      => $fp['floor'],
            'sort'       => fap_opt($pfx . 'sort', 'rank') ?: 'rank',
            'skip_dedup' => true,
            'sale_only'  => fap_opt($pfx . 'sale_only', '0') === '1',
        ];

        $items = $api->search($params, 3);
        if (empty($items)) wp_send_json_error('商品を取得できませんでした。');

        $poster = new FAP_Poster($api);
        wp_send_json_success(['html' => $poster->preview_ranking($items, $fp['service'], $rk), 'css' => wp_get_custom_css()]);
    }

    // -----------------------------------------------------------------------
    // ページ：ライセンス管理
    // -----------------------------------------------------------------------
    public function page_license(): void {
        if (!current_user_can('manage_options')) wp_die();

        $plan    = FAP_License::plan();
        $key     = FAP_License::key();
        $expires = FAP_License::expires();
        $label   = FAP_License::label();

        $badge_colors = ['free' => '#64748b', 'pro' => '#2563eb', 'pro_annual' => '#2563eb', 'premium' => '#9333ea', 'premium_annual' => '#9333ea'];
        $badge_color  = $badge_colors[$plan] ?? '#64748b';

        $this->wrap_start('ライセンス 🔐');
        ?>
        <div class="fap-card">
            <h2>🔑 現在のプラン</h2>
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <span style="font-size:28px;font-weight:700;color:<?= esc_attr($badge_color) ?>;"><?= esc_html($label) ?></span>
                <?php if ($plan !== 'free' && $expires): ?>
                <span style="color:#64748b;font-size:14px;">有効期限: <strong><?= esc_html($expires) ?></strong></span>
                <?php endif; ?>
                <?php if ($plan !== 'free' && $key): ?>
                <span style="color:#64748b;font-size:13px;">キー: <code><?= esc_html(substr($key, 0, 8)) ?>****</code></span>
                <?php endif; ?>
            </div>
            <?php if ($plan === 'free'): ?>
            <p style="margin:12px 0 0;color:#64748b;">現在は無料プランです（スロット1個まで）。ライセンスキーを入力してアップグレードしてください。</p>
            <?php endif; ?>
        </div>

        <div class="fap-card">
            <h2>🔐 ライセンスキー認証</h2>
            <p class="description">購入後にメールで届くライセンスキーを入力してください。</p>
            <div style="display:flex;gap:8px;max-width:600px;">
                <input type="text" id="fap-license-key" placeholder="AP-XXXXXXXX-XXXXXXXX-XXXXXXXX" class="regular-text" style="flex:1;"
                       value="<?= esc_attr($key) ?>">
                <button type="button" id="fap-verify-license" class="button button-primary">ライセンスキーを認証する</button>
            </div>
            <div id="fap-license-result" style="margin-top:10px;"></div>

            <?php if ($plan !== 'free'): ?>
            <hr style="margin:24px 0 16px;">
            <h3>ライセンスの解除</h3>
            <p class="description">このサイトのライセンスを解除します。複数サイトでの同時利用は許可されていますので、別サイトへ移行する場合は先に解除しなくても構いません。</p>
            <p class="description" style="color:#d63638;">⚠ 解除するとプランが Free に戻り、以下の機能が使えなくなります：スロット2以降・自動実行スケジューラー・SEO設定・スマホ追従CTAバー・投稿履歴など（Proプラン）、AIリライト・CTR分析・ランキング記事・設定バックアップなど（Premiumプラン）。既存の投稿データは削除されません。</p>
            <p class="description" style="color:#d63638;font-weight:600;">⚠ ライセンスキーの第三者への譲渡・転売・共有は利用規約により厳禁です。違反が確認された場合、ライセンスの即時停止および法的措置を含む対応を取る場合があります。</p>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>"
                  onsubmit="return confirm('ライセンスを解除しますか？プランが Free に戻り、有料機能が使えなくなります。')">
                <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
                <input type="hidden" name="action" value="fap_deactivate_license">
                <button type="submit" class="button button-secondary">このサイトのライセンスを解除する</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="fap-card">
            <h2>📊 プラン比較</h2>
            <div class="fap-plan-table"><div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;border:2px solid #c7d2fe;border-radius:10px;overflow:hidden;font-size:12px;">
                <!-- ヘッダー -->
                <div style="background:#e2e8f0;padding:8px 12px;font-weight:700;border-bottom:2px solid #cbd5e1;color:#1e293b;">機能</div>
                <div style="background:#e2e8f0;text-align:center;padding:8px 6px;border-bottom:2px solid #cbd5e1;border-left:2px solid #cbd5e1;">
                    <div style="color:#475569;font-weight:700;font-size:13px;">Free</div>
                    <div style="color:#64748b;font-size:10px;margin-top:2px;">無料</div>
                </div>
                <div style="background:#2563eb;text-align:center;padding:8px 6px;border-bottom:2px solid #1d4ed8;border-left:2px solid #1d4ed8;">
                    <div style="color:#fff;font-weight:700;font-size:13px;">Pro</div>
                    <div style="color:#bfdbfe;font-size:10px;margin-top:2px;">3,980円/月</div>
                </div>
                <div style="background:#7c3aed;text-align:center;padding:8px 6px;border-bottom:2px solid #6d28d9;border-left:2px solid #6d28d9;position:relative;">
                    <div style="position:absolute;top:4px;right:4px;background:#fde68a;color:#92400e;font-size:8px;padding:1px 4px;border-radius:999px;font-weight:700;">おすすめ</div>
                    <div style="color:#fff;font-weight:700;font-size:13px;">Premium</div>
                    <div style="color:#ddd6fe;font-size:10px;margin-top:2px;">6,980円/月</div>
                </div>
                <?php
                $ck = '<span style="color:#16a34a;font-size:15px;font-weight:700;">✓</span>';
                $ng = '<span style="color:#94a3b8;font-size:12px;">—</span>';
                $sections = [
                    ['label' => '基本機能', 'color' => '#475569', 'bg' => '#e2e8f0', 'rows' => [
                        ['投稿スロット数',         '1個', '4個', '8個'],
                        ['テンプレート・投稿設定', $ck,   $ck,   $ck],
                        ['プレビュー・ログ閲覧',   $ck,   $ck,   $ck],
                    ]],
                    ['label' => 'Pro 機能', 'color' => '#1d4ed8', 'bg' => '#dbeafe', 'rows' => [
                        ['自動実行スケジューラー',                   $ng, $ck, $ck],
                        ['セール品のみ取得',                         $ng, $ck, $ck],
                        ['投稿時間ランダム化',                       $ng, $ck, $ck],
                        ['タイトルテンプレート 2・3（ランダム選択）',$ng, $ck, $ck],
                        ['SEO設定（JSON-LD / OGP / Twitterカード）', $ng, $ck, $ck],
                        ['投稿履歴',                                 $ng, $ck, $ck],
                        ['スマホ追従CTAバー',                        $ng, $ck, $ck],
                    ]],
                    ['label' => 'Premium 機能', 'color' => '#6d28d9', 'bg' => '#ede9fe', 'rows' => [
                        ['販売停止チェック＆一括ゴミ箱', $ng, $ng, $ck],
                        ['セール検知＆自動リライト',     $ng, $ng, $ck],
                        ['ジャンル別テンプレート',       $ng, $ng, $ck],
                        ['CTR分析（PV・クリック計測）',  $ng, $ng, $ck],
                        ['AIリライト（OpenAI / Claude）',$ng, $ng, $ck],
                        ['ランキング記事自動生成',       $ng, $ng, $ck],
                        ['設定バックアップ・復元',       $ng, $ng, $ck],
                    ]],
                    ['label' => '料金', 'color' => '#374151', 'bg' => '#f1f5f9', 'rows' => [
                        ['7日間無料トライアル',    $ng,    $ck,           $ck],
                        ['月額料金',               '無料', '3,980円',     '6,980円'],
                        ['年額料金（2ヶ月分お得）',$ng,    '39,800円/年', '69,800円/年'],
                    ]],
                ];
                $row_i = 0;
                foreach ($sections as $sec):
                ?>
                <div style="grid-column:1/-1;background:<?= esc_attr($sec['bg']) ?>;padding:4px 12px;font-size:10px;font-weight:700;color:<?= esc_attr($sec['color']) ?>;letter-spacing:.06em;text-transform:uppercase;border-bottom:1px solid #cbd5e1;border-top:2px solid #cbd5e1;"><?= esc_html($sec['label']) ?></div>
                <?php foreach ($sec['rows'] as [$feat, $f, $p, $pr]): $odd = ($row_i++ % 2 !== 0); ?>
                <div style="background:<?= $odd ? '#f8fafc' : '#fff' ?>;padding:6px 12px;border-bottom:1px solid #e2e8f0;color:#1e293b;"><?= esc_html($feat) ?></div>
                <div style="background:<?= $odd ? '#f1f5f9' : '#f8fafc' ?>;text-align:center;padding:6px 4px;border-bottom:1px solid #e2e8f0;border-left:1px solid #cbd5e1;"><?= $f ?></div>
                <div style="background:<?= $odd ? '#dbeafe' : '#eff6ff' ?>;text-align:center;padding:6px 4px;border-bottom:1px solid #bfdbfe;border-left:2px solid #93c5fd;"><?= $p ?></div>
                <div style="background:<?= $odd ? '#ede9fe' : '#f5f3ff' ?>;text-align:center;padding:6px 4px;border-bottom:1px solid #ddd6fe;border-left:2px solid #c4b5fd;"><?= $pr ?></div>
                <?php endforeach; endforeach; ?>
            </div></div>
            <p style="margin-top:20px;">
                <a href="https://autoposter.jp/" target="_blank" rel="noopener" class="button button-primary button-large" style="background:#9333ea;border-color:#9333ea;">
                    プランを購入・アップグレード →
                </a>
            </p>
        </div>
        <?php
        $this->wrap_end();
    }

    // -----------------------------------------------------------------------
    // AJAX：ライセンスキー認証
    // -----------------------------------------------------------------------
    public function ajax_verify_license(): void {
        check_ajax_referer('fap_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('権限エラー');

        $key = sanitize_text_field(trim($_POST['license_key'] ?? ''));
        if (!$key) {
            wp_send_json_error('ライセンスキーを入力してください。');
        }

        $result = FAP_License::verify($key);

        if ($result['status'] === 'ok') {
            wp_send_json_success([
                'message' => $result['message'],
                'plan'    => $result['plan'],
                'expires' => $result['expires'] ?? '',
            ]);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：セール検知 手動実行
    // -----------------------------------------------------------------------
    public function handle_sale_check(): void {
        $this->verify_nonce();
        if (!FAP_License::is('premium')) wp_die('Premium プランが必要です。');
        $api    = new FAP_API();
        $poster = new FAP_Poster($api);
        $result = $poster->run_sale_check();
        if ($result['ok']) {
            set_transient('fap_notice_success', sprintf(
                'セール検知（1バッチ）完了：確認 %d 件 / セール発見 %d 件 / セール終了 %d 件%s',
                $result['processed'], $result['sale_found'], $result['sale_ended'],
                !empty($result['done']) ? '（全記事1周完了）' : '（続きは自動実行が1時間ごとに処理します）'
            ), 30);
        } else {
            set_transient('fap_notice_error', 'セール検知に失敗しました：' . $result['msg'], 30);
        }
        $this->redirect_back();
    }

    // -----------------------------------------------------------------------
    // AJAX：販売停止チェック（バッチ実行、offsetを進めながら繰り返し呼ばれる）
    // -----------------------------------------------------------------------
    public function ajax_discontinued_check(): void {
        check_ajax_referer('fap_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('権限がありません。');
        if (!FAP_License::is('premium'))         wp_send_json_error('Premium プランが必要です。');

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $api    = new FAP_API();
        $poster = new FAP_Poster($api);
        $result = $poster->run_discontinued_check($offset);

        if ($result['ok']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['msg']);
        }
    }

    // -----------------------------------------------------------------------
    // AJAX：販売停止記事の一括ゴミ箱移動
    // FAP生成記事（_fap_cid あり）以外はスキップして誤削除を防ぐ
    // -----------------------------------------------------------------------
    public function ajax_discontinued_trash(): void {
        check_ajax_referer('fap_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('権限がありません。');
        if (!FAP_License::is('premium'))         wp_send_json_error('Premium プランが必要です。');

        $ids = isset($_POST['post_ids']) ? array_map('absint', (array) $_POST['post_ids']) : [];
        if (empty($ids)) wp_send_json_error('記事が選択されていません。');

        $trashed = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $cid = get_post_meta($id, '_fap_cid', true);
            if (!$cid || get_post_type($id) !== 'post') {
                $skipped++;
                continue;
            }
            if (wp_trash_post($id)) {
                $trashed++;
            } else {
                $skipped++;
                fap_log('[DiscontinuedCheck] ゴミ箱移動失敗 post_id=' . $id);
            }
        }

        fap_log(sprintf('[DiscontinuedCheck] ゴミ箱移動 trashed=%d skipped=%d', $trashed, $skipped));
        wp_send_json_success(['trashed' => $trashed, 'skipped' => $skipped]);
    }

    // -----------------------------------------------------------------------
    // フォームハンドラ：ライセンス解除
    // -----------------------------------------------------------------------
    public function handle_deactivate_license(): void {
        $this->verify_nonce();
        FAP_License::deactivate();
        set_transient('fap_notice_success', 'ライセンスを解除しました。プランが Free に戻りました。', 30);
        wp_safe_redirect(admin_url('admin.php?page=' . FAP_SLUG . '-license'));
        exit;
    }

    // -----------------------------------------------------------------------
    // プランゲート表示ヘルパー
    // -----------------------------------------------------------------------
    private function upgrade_wall(string $min_plan, string $feature_name, string $description = ''): void {
        $label = FAP_License::LABELS[$min_plan] ?? $min_plan;
        $price = FAP_License::PRICES[$min_plan] ?? '';
        $color = ($min_plan === 'premium') ? '#9333ea' : '#2563eb';
        $badge = '<span style="background:' . esc_attr($color) . ';color:#fff;padding:2px 10px;border-radius:999px;font-size:13px;vertical-align:middle;margin-left:8px;">' . esc_html($label) . '</span>';
        echo '<div class="fap-card" style="border-left:4px solid ' . esc_attr($color) . ';text-align:center;padding:40px 24px;">';
        echo '<h3 style="margin:0 0 8px;font-size:20px;">' . esc_html($feature_name) . $badge . '</h3>';
        if ($description !== '') {
            echo '<p style="color:#374151;margin:0 0 12px;">' . esc_html($description) . '</p>';
        }
        echo '<p style="color:#6b7280;margin:0 0 24px;">' . esc_html($label) . ' プランにアップグレードすると利用できます（' . esc_html($price) . '）。</p>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=' . FAP_SLUG . '-license')) . '" class="button button-primary button-large" style="background:' . esc_attr($color) . ';border-color:' . esc_attr($color) . ';">プランをアップグレード →</a>';
        echo '</div>';
    }

    private function section_lock(string $min_plan, string $label): void {
        $plan_label = FAP_License::LABELS[$min_plan] ?? $min_plan;
        $color = ($min_plan === 'premium') ? '#9333ea' : '#2563eb';
        echo '<p style="color:#6b7280;margin:4px 0 0;">';
        echo esc_html($label) . ' は <span style="background:' . esc_attr($color) . ';color:#fff;padding:1px 8px;border-radius:999px;font-size:11px;">' . esc_html($plan_label) . '</span> プランで利用できます。';
        echo ' <a href="' . esc_url(admin_url('admin.php?page=' . FAP_SLUG . '-license')) . '">アップグレード →</a>';
        echo '</p>';
    }

    // -----------------------------------------------------------------------
    // CTR分析ページ
    // -----------------------------------------------------------------------
    public function page_ctr(): void {
        if (!current_user_can('manage_options')) wp_die();

        $track_on = fap_opt('ctr_track_on', '0') === '1';

        $this->wrap_start('CTR分析 📈');
        ?>
        <?php if (!FAP_License::is('premium')): ?>
            <?php $this->upgrade_wall('premium', 'CTR分析'); ?>
        <?php else: ?>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
            <input type="hidden" name="action" value="fap_save_ctr">

            <div class="fap-card">
                <h2>計測設定</h2>
                <table class="form-table">
                    <tr>
                        <th>CTR計測を有効化</th>
                        <td>
                            <label><input type="checkbox" name="ctr_track_on" value="1" <?php checked($track_on) ?>>
                            FAP生成記事のページビューとアフィリンクのクリック数を記録する</label>
                            <p class="description">有効にすると front.js が読み込まれ、ページ表示時・リンククリック時にカウントが更新されます。<br>
                            レートリミット：同じIPアドレスと記事の組み合わせで1時間に1カウントのみ記録されます（重複カウント防止）。</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('設定を保存'); ?>
            </div>
        </form>

        <?php
        // CTRレポートテーブル
        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'numberposts'    => 100,
            'meta_key'       => '_fap_cid',
            'meta_compare'   => 'EXISTS',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        ?>
        <div class="fap-card">
            <h2>記事別 PV / クリック / CTR</h2>
            <?php if (!empty($posts)): ?>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-bottom:12px;">
                <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
                <input type="hidden" name="action" value="fap_export_ctr">
                <button type="submit" class="button button-secondary">📥 CSVエクスポート</button>
            </form>
            <?php endif; ?>
            <?php if (empty($posts)): ?>
                <p style="color:#64748b;">FAP生成の公開済み記事がまだありません。</p>
            <?php else: ?>
            <div class="fap-table-scroll">
            <table class="widefat striped" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th>記事タイトル</th>
                        <th style="width:80px;text-align:right;">PV</th>
                        <th style="width:80px;text-align:right;">クリック</th>
                        <th style="width:80px;text-align:right;">CTR</th>
                        <th style="width:140px;">投稿日</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $p):
                    $views   = (int) get_post_meta($p->ID, '_fap_view_count',  true);
                    $clicks  = (int) get_post_meta($p->ID, '_fap_click_count', true);
                    $ctr_str = ($views > 0) ? number_format($clicks / $views * 100, 1) . '%' : '-';
                ?>
                    <tr>
                        <td><a href="<?= esc_url(get_edit_post_link($p->ID)) ?>"><?= esc_html($p->post_title) ?></a></td>
                        <td style="text-align:right;"><?= number_format($views) ?></td>
                        <td style="text-align:right;"><?= number_format($clicks) ?></td>
                        <td style="text-align:right;"><?= esc_html($ctr_str) ?></td>
                        <td><?= esc_html(get_the_date('Y/m/d', $p->ID)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <?php endif; ?>
        <?php
        $this->wrap_end();
    }

    public function handle_save_ctr(): void {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer(FAP_NONCE, '_fap_nonce');

        update_option('fap_ctr_track_on', isset($_POST['ctr_track_on']) ? '1' : '0');

        set_transient('fap_notice_success', 'CTR設定を保存しました。', 30);
        wp_safe_redirect(admin_url('admin.php?page=' . FAP_SLUG . '-ctr'));
        exit;
    }

    // -----------------------------------------------------------------------
    // AI設定ページ
    // -----------------------------------------------------------------------
    public function page_ai(): void {
        if (!current_user_can('manage_options')) wp_die();

        $provider   = fap_opt('ai_provider',     'openai');
        $api_key    = fap_secret('FAP_AI_API_KEY', 'ai_api_key');
        $model      = fap_opt('ai_model',         '');
        $prompt_tpl = fap_opt('ai_prompt_tpl',    "以下の記事タイトルと本文を、読者に魅力が伝わるよう自然な日本語でリライトしてください。\n\nタイトル: [title]\n\n本文:\n[content]");
        $auto_sale  = fap_opt('ai_auto_sale',     '0') === '1';
        $auto_new   = fap_opt('ai_auto_new',      '0') === '1';

        $default_models = ['openai' => 'gpt-4o-mini', 'claude' => 'claude-haiku-4-5-20251001'];

        $this->wrap_start('AI設定 🤖');
        ?>
        <?php if (!FAP_License::is('premium')): ?>
            <?php $this->upgrade_wall('premium', 'AIリライト'); ?>
        <?php else: ?>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
            <?php wp_nonce_field(FAP_NONCE, '_fap_nonce'); ?>
            <input type="hidden" name="action" value="fap_save_ai">

            <div class="fap-card">
                <h2>AIプロバイダー設定</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="ai_provider">プロバイダー</label></th>
                        <td>
                            <select id="ai_provider" name="ai_provider">
                                <option value="openai" <?php selected($provider, 'openai') ?>>OpenAI (ChatGPT)</option>
                                <option value="claude" <?php selected($provider, 'claude') ?>>Anthropic (Claude)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_api_key">APIキー</label></th>
                        <td>
                            <?php if (defined('FAP_AI_API_KEY')): ?>
                                <input type="password" class="regular-text" value="***** (定数設定済み)" disabled />
                                <p class="description" style="color:#2271b1;">wp-config.php の定数 <code>FAP_AI_API_KEY</code> が優先されます。DB への保存は行われません。</p>
                            <?php else: ?>
                                <input type="password" id="ai_api_key" name="ai_api_key" class="regular-text" value="<?= esc_attr($api_key) ?>">
                                <p class="description">入力した値はWordPressのオプションテーブルに暗号化せず保存されます。wp-config.php で <code>define('FAP_AI_API_KEY', '...')</code> と定義するとDBに保存されなくなります。</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ai_model">モデル名</label></th>
                        <td>
                            <input type="text" id="ai_model" name="ai_model" class="regular-text"
                                   value="<?= esc_attr($model) ?>"
                                   placeholder="OpenAI: gpt-4o-mini　／　Claude: claude-haiku-4-5-20251001">
                            <p class="description">空欄の場合はプロバイダーのデフォルトモデルを使用します（OpenAI: <?= esc_html($default_models['openai']) ?>、Claude: <?= esc_html($default_models['claude']) ?>）。</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="fap-card">
                <h2>リライトプロンプト</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="ai_prompt_tpl">プロンプトテンプレート</label></th>
                        <td>
                            <textarea id="ai_prompt_tpl" name="ai_prompt_tpl" rows="8" class="large-text"><?= esc_textarea($prompt_tpl) ?></textarea>
                            <p class="description">使用できる変数: <code>[title]</code>（記事タイトル）、<code>[content]</code>（本文）</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="fap-card">
                <h2>自動リライト設定</h2>
                <table class="form-table">
                    <tr>
                        <th>新規投稿時に自動リライト</th>
                        <td>
                            <label><input type="checkbox" name="ai_auto_new" value="1" <?php checked($auto_new) ?>>
                            自動投稿・手動実行で記事が新規作成された直後に、AIで記事タイトル・本文を自動リライトする</label>
                            <p class="description">
                                記事の作成後にリライトを実行します。リライトに失敗した場合でも、テンプレートで生成した記事はそのまま残ります。<br>
                                リライト前のタイトル・本文は <code>_fap_pre_ai_title</code> / <code>_fap_pre_ai_content</code> に保存されます。
                            </p>
                            <p class="description" style="color:#b32d2e;">
                                <strong>ご注意：</strong>1記事ごとにAI APIを1回呼び出すため、投稿件数ぶんの API 利用料と処理時間（1件あたり数秒〜十数秒）がかかります。
                                1回の実行で多くの記事を投稿する設定にしている場合は、まず取得件数を少なめにして費用と仕上がりを確認してください。<br>
                                また、プロンプトの内容によってはAIが本文中のアフィリエイトリンクや画像タグを書き換えてしまうことがあります。
                                プロンプトに「HTMLタグ・リンクはそのまま残す」旨を明記し、最初の数件は必ず表示を確認してください。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>セール検知時に自動リライト</th>
                        <td>
                            <label><input type="checkbox" name="ai_auto_sale" value="1" <?php checked($auto_sale) ?>>
                            セール検知＆リライト機能でセール状態が変わった際に、AIで記事タイトル・本文を自動更新する</label>
                            <p class="description">APIキーの設定が必要です。リライト対象は <code>_fap_cid</code> を持つFAP生成記事のみです。</p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button('AI設定を保存'); ?>
        </form>

        <div class="fap-card">
            <h2>接続テスト</h2>
            <p>保存済みのAPIキーとモデルでダミーテキストを送信し、AIが正常に応答するか確認します。</p>
            <button id="fap-ai-test-btn" class="button button-secondary">接続テストを実行</button>
            <span id="fap-ai-test-result" style="margin-left:12px;font-weight:bold;"></span>
        </div>

        <div class="fap-card">
            <h2>手動リライト（投稿IDを指定）</h2>
            <p>FAP生成記事のタイトル・本文をAIで上書きします。元のタイトルは <code>_fap_original_title</code>、元の本文は <code>_fap_original_content</code> として保存されます。</p>
            <p class="description">元に戻すには：投稿一覧から該当記事を開き、「カスタムフィールド」の <code>_fap_original_title</code> と <code>_fap_original_content</code> の値をコピーして手動で復元してください。</p>
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="number" id="fap-ai-rewrite-id" placeholder="投稿ID" min="1" style="width:120px;" class="regular-text">
                <button id="fap-ai-rewrite-btn" class="button button-secondary">AIリライト実行</button>
                <span id="fap-ai-rewrite-result" style="font-weight:bold;"></span>
            </div>
        </div>

        <script>
        document.getElementById('fap-ai-test-btn').addEventListener('click', function () {
            var btn    = this;
            var result = document.getElementById('fap-ai-test-result');
            btn.disabled = true;
            result.style.color = '#555';
            result.textContent = '送信中...';

            var fd = new FormData();
            fd.append('action', 'fap_test_ai');
            fd.append('_fap_nonce', '<?= esc_js(wp_create_nonce(FAP_NONCE)) ?>');

            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    result.style.color = data.success ? '#16a34a' : '#dc2626';
                    result.textContent = data.data;
                })
                .catch(function () {
                    result.style.color = '#dc2626';
                    result.textContent = 'リクエストに失敗しました。';
                })
                .finally(function () { btn.disabled = false; });
        });
        </script>

        <?php endif; ?>
        <?php
        $this->wrap_end();
    }

    public function ajax_test_ai(): void {
        check_ajax_referer(FAP_NONCE, '_fap_nonce');
        if (!current_user_can('manage_options')) wp_die();

        $result = FAP_AI::test_connection();
        if ($result['ok']) {
            wp_send_json_success($result['msg']);
        } else {
            wp_send_json_error($result['msg']);
        }
    }

    // 手動AIリライト（投稿一覧rowアクション + ID指定の両方から呼ばれる）
    public function ajax_manual_ai_rewrite(): void {
        check_ajax_referer(FAP_NONCE, '_fap_nonce');
        if (!current_user_can('manage_options')) wp_die();
        if (!FAP_License::is('premium')) {
            wp_send_json_error('Premiumプランが必要です。');
        }

        $post_id = (int) ($_POST['post_id'] ?? 0);
        $post    = $post_id ? get_post($post_id) : null;
        if (!$post) {
            wp_send_json_error('投稿が見つかりません (ID=' . $post_id . ')');
        }

        // 元のタイトル・本文を保存
        if (!get_post_meta($post_id, '_fap_original_title', true)) {
            update_post_meta($post_id, '_fap_original_title', $post->post_title);
        }
        if (!get_post_meta($post_id, '_fap_original_content', true)) {
            update_post_meta($post_id, '_fap_original_content', $post->post_content);
        }

        $ai_result = FAP_AI::rewrite($post->post_title, $post->post_content);
        if (!$ai_result['ok']) {
            wp_send_json_error($ai_result['msg']);
        }

        $update = ['ID' => $post_id];
        if ($ai_result['title'])   $update['post_title']   = $ai_result['title'];
        if ($ai_result['content']) $update['post_content'] = $ai_result['content'];
        wp_update_post($update);

        fap_log('[AI] 手動リライト完了 post_id=' . $post_id);
        wp_send_json_success(['msg' => 'リライト完了: ' . esc_html(get_the_title($post_id))]);
    }

    // 投稿一覧にAIリライトrowアクションを追加（FAP生成記事のみ）
    public function add_ai_rewrite_row_action(array $actions, \WP_Post $post): array {
        if ($post->post_type !== 'post') return $actions;
        if (!get_post_meta($post->ID, '_fap_cid', true)) return $actions;
        if (!FAP_License::is('premium')) return $actions;

        $nonce = wp_create_nonce(FAP_NONCE);
        $actions['fap_ai_rewrite'] = sprintf(
            '<a href="#" class="fap-row-ai-rewrite" data-post-id="%d" data-nonce="%s">🤖 AIリライト</a>',
            $post->ID,
            esc_attr($nonce)
        );
        return $actions;
    }

    // 投稿履歴ページ
    public function page_history(): void {
        if (!current_user_can('manage_options')) wp_die();

        $this->wrap_start('投稿履歴 📋');

        if (!FAP_License::is('pro')) {
            $this->upgrade_wall('pro', '投稿履歴');
            $this->wrap_end();
            return;
        }

        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => ['publish', 'future', 'draft', 'pending', 'private'],
            'numberposts'    => 200,
            'meta_key'       => '_fap_cid',
            'meta_compare'   => 'EXISTS',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        ?>
        <div class="fap-card">
            <h2>FAP生成記事一覧（最新200件）</h2>
            <?php if (empty($posts)): ?>
                <p style="color:#64748b;">FAP生成の記事がまだありません。</p>
            <?php else: ?>
            <div class="fap-table-scroll">
            <table class="widefat striped" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th style="width:140px;">投稿日時</th>
                        <th style="width:60px;text-align:center;">スロット</th>
                        <th>タイトル</th>
                        <th style="width:120px;">CID</th>
                        <th style="width:80px;text-align:center;">ステータス</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $p):
                    $slot   = get_post_meta($p->ID, '_fap_slot',  true);
                    $cid    = get_post_meta($p->ID, '_fap_cid',   true);
                    $status_labels = [
                        'publish' => '<span style="color:#00a32a;">公開</span>',
                        'future'  => '<span style="color:#2563eb;">予約</span>',
                        'draft'   => '<span style="color:#9ca3af;">下書き</span>',
                        'pending' => '<span style="color:#f59e0b;">承認待ち</span>',
                        'private' => '<span style="color:#6b7280;">非公開</span>',
                    ];
                    $status_html = $status_labels[$p->post_status] ?? esc_html($p->post_status);
                ?>
                    <tr>
                        <td style="font-size:12px;"><?= esc_html(get_the_date('Y/m/d H:i', $p->ID)) ?></td>
                        <td style="text-align:center;"><?= $slot ? esc_html($slot) : '-' ?></td>
                        <td><a href="<?= esc_url(get_edit_post_link($p->ID)) ?>"><?= esc_html($p->post_title) ?></a></td>
                        <td style="font-size:12px;font-family:monospace;"><?= esc_html($cid) ?></td>
                        <td style="text-align:center;"><?= $status_html ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
        $this->wrap_end();
    }

    // CTR分析 CSVエクスポート
    public function handle_export_ctr(): void {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer(FAP_NONCE, '_fap_nonce');
        if (!FAP_License::is('premium')) wp_die('Premiumプランが必要です。');

        $posts = get_posts([
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'numberposts'  => -1,
            'meta_key'     => '_fap_cid',
            'meta_compare' => 'EXISTS',
            'orderby'      => 'date',
            'order'        => 'DESC',
        ]);

        $filename = 'fap-ctr-' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        // BOM付きUTF-8でExcel対応
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['タイトル', 'URL', 'PV', 'クリック', 'CTR(%)', '投稿日']);

        foreach ($posts as $p) {
            $views  = (int) get_post_meta($p->ID, '_fap_view_count',  true);
            $clicks = (int) get_post_meta($p->ID, '_fap_click_count', true);
            $ctr    = ($views > 0) ? number_format($clicks / $views * 100, 1) : '0';
            fputcsv($out, [
                $p->post_title,
                get_permalink($p->ID),
                $views,
                $clicks,
                $ctr,
                get_the_date('Y/m/d', $p->ID),
            ]);
        }
        fclose($out);
        exit;
    }

    public function handle_save_ai(): void {
        if (!current_user_can('manage_options')) wp_die();
        check_admin_referer(FAP_NONCE, '_fap_nonce');

        update_option('fap_ai_provider',    sanitize_text_field($_POST['ai_provider']    ?? 'openai'));
        if (!defined('FAP_AI_API_KEY')) {
            update_option('fap_ai_api_key', sanitize_text_field($_POST['ai_api_key'] ?? ''));
        }
        update_option('fap_ai_model',       sanitize_text_field($_POST['ai_model']       ?? ''));
        update_option('fap_ai_prompt_tpl',  sanitize_textarea_field($_POST['ai_prompt_tpl'] ?? ''));
        update_option('fap_ai_auto_sale',   isset($_POST['ai_auto_sale']) ? '1' : '0');
        update_option('fap_ai_auto_new',    isset($_POST['ai_auto_new'])  ? '1' : '0');

        set_transient('fap_notice_success', 'AI設定を保存しました。', 30);
        wp_safe_redirect(admin_url('admin.php?page=' . FAP_SLUG . '-ai'));
        exit;
    }

    // -----------------------------------------------------------------------
    // S-3: テンプレート作成時のデフォルトタイトル自動入力
    // post-new.php?fap_template=1&fap_slot=N を開いたとき、
    // タイトルを「FAP テンプレートN」（スロット1のみ「FAP テンプレート」）に設定する
    // -----------------------------------------------------------------------
    public function default_post_title_for_template(string $title): string {
        if (!isset($_GET['fap_template']) || $_GET['fap_template'] !== '1') return $title;
        $slot = isset($_GET['fap_slot']) ? max(1, min(FAP_SLOTS, (int) $_GET['fap_slot'])) : 1;
        return $slot === 1 ? 'FAP テンプレート' : 'FAP テンプレート' . $slot;
    }

    public function default_post_content_for_template(string $content): string {
        if (!isset($_GET['fap_template']) || $_GET['fap_template'] !== '1') return $content;
        if ($content !== '') return $content;
        $slot = isset($_GET['fap_slot']) ? max(1, min(FAP_SLOTS, (int) $_GET['fap_slot'])) : 1;
        return "<!-- FAP テンプレート（スロット{$slot}）-->\n[title]\n\n[images]\n\n[actress] / [genre]\n\n[aff-btn1]";
    }
}
