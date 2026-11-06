<?php
defined('ABSPATH') || exit;

/**
 * AIリライトの差分表示
 *  ・記事編集画面に「AIリライトの差分」ボックスを表示（削除=赤／追加=緑）
 *  ・投稿一覧に「AI」列を追加（差分がある記事に「差分」リンク）
 *  ・ショートコード・HTMLタグが消えた／増えた場合は警告を表示
 */
class FAP_Diff {

    // LCS（最長共通部分列）の計算量の上限。超えたら粗い単位（文・行）で比較する
    private const MAX_CELLS = 1000000;

    public function __construct() {
        add_action('add_meta_boxes_post', [$this, 'add_box']);
        add_filter('manage_posts_columns', [$this, 'add_column']);
        add_action('manage_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_action('admin_head', [$this, 'print_css']);
    }

    // -----------------------------------------------------------------------
    // 差分セットの収集（変更があるものだけ返す）
    // -----------------------------------------------------------------------
    private function collect_sets(WP_Post $post): array {
        if (!get_post_meta($post->ID, '_fap_cid', true)) return [];

        $sets = [];

        // 1) 作品説明文のリライト（[comment]）
        $src = (string) get_post_meta($post->ID, '_fap_ai_desc_src', true);
        $ai  = (string) get_post_meta($post->ID, '_fap_ai_desc', true);
        if ($src !== '' && $ai !== '' && $src !== $ai) {
            $sets[] = ['label' => '作品説明文のリライト（[comment]）', 'rows' => [
                ['name' => '説明文', 'before' => $src, 'after' => $ai, 'html' => false],
            ]];
        }

        // 2) 新規投稿時の全文リライト（リライト前 → 現在）
        $pre_t = (string) get_post_meta($post->ID, '_fap_pre_ai_title',   true);
        $pre_c = (string) get_post_meta($post->ID, '_fap_pre_ai_content', true);
        $rows  = [];
        if ($pre_t !== '' && $pre_t !== $post->post_title)   $rows[] = ['name' => 'タイトル', 'before' => $pre_t, 'after' => $post->post_title,   'html' => false];
        if ($pre_c !== '' && $pre_c !== $post->post_content) $rows[] = ['name' => '本文',     'before' => $pre_c, 'after' => $post->post_content, 'html' => true];
        if ($rows) $sets[] = ['label' => '新規投稿時の全文リライト（リライト前 → 現在）', 'rows' => $rows];

        // 3) セール時・手動リライト（元の内容 → 現在）
        $org_t = (string) get_post_meta($post->ID, '_fap_original_title',   true);
        $org_c = (string) get_post_meta($post->ID, '_fap_original_content', true);
        $rows  = [];
        if ($org_t !== '' && $org_t !== $post->post_title)   $rows[] = ['name' => 'タイトル', 'before' => $org_t, 'after' => $post->post_title,   'html' => false];
        if ($org_c !== '' && $org_c !== $post->post_content) $rows[] = ['name' => '本文',     'before' => $org_c, 'after' => $post->post_content, 'html' => true];
        if ($rows) $sets[] = ['label' => 'セール時・手動リライト（元の内容 → 現在）', 'rows' => $rows];

        return $sets;
    }

    // -----------------------------------------------------------------------
    // メタボックス
    // -----------------------------------------------------------------------
    public function add_box(WP_Post $post): void {
        if (!$this->collect_sets($post)) return;
        add_meta_box('fap_ai_diff', '🤖 AIリライトの差分', [$this, 'render_box'], 'post', 'normal', 'high');
    }

    public function render_box(WP_Post $post): void {
        $sets = $this->collect_sets($post);
        echo '<div class="fap-diff">';
        echo '<p class="fap-diff-legend"><del>削除された部分</del> <ins>追加された部分</ins></p>';

        foreach ($sets as $set) {
            echo '<h4 class="fap-diff-set">' . esc_html($set['label']) . '</h4>';
            foreach ($set['rows'] as $row) {
                $diff = self::diff($row['before'], $row['after']);
                [$del_len, $ins_len] = self::count_changes($diff);

                echo '<p class="fap-diff-name"><strong>' . esc_html($row['name']) . '</strong>　'
                   . '<span class="fap-diff-stat">削除 ' . (int) $del_len . ' 文字 ／ 追加 ' . (int) $ins_len . ' 文字</span></p>';

                // ショートコード・HTMLタグが消えた／増えたときの警告
                $warn = self::structure_warnings($row['before'], $row['after']);
                if ($warn) {
                    echo '<div class="fap-diff-warn">⚠ ' . implode('<br>⚠ ', array_map('esc_html', $warn)) . '</div>';
                }

                echo '<div class="fap-diff-body">' . self::render_diff($diff) . '</div>';
            }
        }
        echo '<p class="description">「現在」との差分です。記事を手で編集した場合は、その編集も差分に含まれます。</p>';
        echo '</div>';
    }

    // -----------------------------------------------------------------------
    // 投稿一覧の「AI」列
    // -----------------------------------------------------------------------
    public function add_column(array $cols): array {
        $cols['fap_ai'] = '🤖AI';
        return $cols;
    }

    public function render_column(string $col, int $post_id): void {
        if ($col !== 'fap_ai') return;
        $post = get_post($post_id);
        if (!$post || !$this->collect_sets($post)) { echo '—'; return; }
        printf('<a href="%s">差分</a>', esc_url(get_edit_post_link($post_id) . '#fap_ai_diff'));
    }

    public function print_css(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->base, ['post', 'edit'], true)) return;
        ?>
        <style>
            .fap-diff-legend del, .fap-diff-body del { background:#fde2e2; color:#b32d2e; text-decoration:line-through; }
            .fap-diff-legend ins, .fap-diff-body ins { background:#dcfce7; color:#166534; text-decoration:none; }
            .fap-diff-set  { margin:14px 0 4px; padding-top:8px; border-top:1px solid #dcdcde; }
            .fap-diff-stat { color:#646970; font-size:12px; }
            .fap-diff-body { white-space:pre-wrap; word-break:break-all; max-height:380px; overflow:auto;
                             background:#fff; border:1px solid #dcdcde; border-radius:4px; padding:10px; line-height:1.7; font-size:13px; }
            .fap-diff-warn { background:#fff8e5; border-left:4px solid #dba617; padding:8px 12px; margin:6px 0; font-size:13px; }
            .column-fap_ai { width:64px; }
        </style>
        <?php
    }

    // -----------------------------------------------------------------------
    // 差分エンジン
    // -----------------------------------------------------------------------

    // 細かい単位に分割：HTMLタグ / ショートコード / 英数字の連続 / 空白の連続 / 1文字
    private static function tokenize(string $s): array {
        preg_match_all('/<[^>]+>|\[\/?[A-Za-z_][A-Za-z0-9_\-]*(?:\s[^\]]*)?\]|[A-Za-z0-9_]+|\s+|./us', $s, $m);
        return $m[0];
    }

    // 粗い単位に分割：文末・改行・タグの終わりで区切る
    private static function tokenize_coarse(string $s): array {
        $parts = preg_split('/(?<=[。！？\n>])/u', $s, -1, PREG_SPLIT_NO_EMPTY);
        return $parts ?: [$s];
    }

    /**
     * 差分を [[op, text], ...] で返す。op は '=' 同じ / '-' 削除 / '+' 追加
     */
    public static function diff(string $before, string $after): array {
        if ($before === $after) return $before === '' ? [] : [['=', $before]];

        $a = self::tokenize($before);
        $b = self::tokenize($after);

        // 先頭・末尾の共通部分を除いて計算量を減らす
        $pre = 0;
        $na = count($a); $nb = count($b);
        while ($pre < $na && $pre < $nb && $a[$pre] === $b[$pre]) $pre++;
        $suf = 0;
        while ($suf < $na - $pre && $suf < $nb - $pre && $a[$na - 1 - $suf] === $b[$nb - 1 - $suf]) $suf++;

        $ma = array_slice($a, $pre, $na - $pre - $suf);
        $mb = array_slice($b, $pre, $nb - $pre - $suf);

        if (count($ma) * count($mb) > self::MAX_CELLS) {
            // 長すぎる場合は、文・行単位で比較する
            $a2 = self::tokenize_coarse(implode('', $ma));
            $b2 = self::tokenize_coarse(implode('', $mb));
            if (count($a2) * count($b2) > self::MAX_CELLS) {
                $mid = [['-', implode('', $ma)], ['+', implode('', $mb)]];
            } else {
                $mid = self::lcs_diff($a2, $b2);
            }
        } else {
            $mid = self::lcs_diff($ma, $mb);
        }

        $out = [];
        if ($pre > 0) $out[] = ['=', implode('', array_slice($a, 0, $pre))];
        foreach ($mid as $op) $out[] = $op;
        if ($suf > 0) $out[] = ['=', implode('', array_slice($a, $na - $suf))];

        return self::merge($out);
    }

    private static function lcs_diff(array $a, array $b): array {
        $n = count($a); $m = count($b);
        if ($n === 0) return $m ? [['+', implode('', $b)]] : [];
        if ($m === 0) return [['-', implode('', $a)]];

        // DPテーブル（後ろから）
        $t = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $t[$i][$j] = ($a[$i] === $b[$j])
                    ? $t[$i + 1][$j + 1] + 1
                    : max($t[$i + 1][$j], $t[$i][$j + 1]);
            }
        }

        $out = [];
        $i = 0; $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j])                    { $out[] = ['=', $a[$i]]; $i++; $j++; }
            elseif ($t[$i + 1][$j] >= $t[$i][$j + 1]) { $out[] = ['-', $a[$i]]; $i++; }
            else                                      { $out[] = ['+', $b[$j]]; $j++; }
        }
        while ($i < $n) { $out[] = ['-', $a[$i++]]; }
        while ($j < $m) { $out[] = ['+', $b[$j++]]; }
        return $out;
    }

    // 同じ種類が連続する要素をまとめる
    private static function merge(array $ops): array {
        $out = [];
        foreach ($ops as [$op, $text]) {
            if ($text === '') continue;
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last][0] === $op) $out[$last][1] .= $text;
            else $out[] = [$op, $text];
        }
        return $out;
    }

    private static function count_changes(array $diff): array {
        $del = 0; $ins = 0;
        foreach ($diff as [$op, $text]) {
            if ($op === '-') $del += mb_strlen($text);
            if ($op === '+') $ins += mb_strlen($text);
        }
        return [$del, $ins];
    }

    private static function render_diff(array $diff): string {
        $html = '';
        foreach ($diff as [$op, $text]) {
            $e = esc_html($text);
            if     ($op === '-') $html .= '<del>' . $e . '</del>';
            elseif ($op === '+') $html .= '<ins>' . $e . '</ins>';
            else                 $html .= $e;
        }
        return $html;
    }

    // ショートコード・HTMLタグの増減を検出して警告文を返す
    private static function structure_warnings(string $before, string $after): array {
        $warn = [];
        $pick = static function (string $s): array {
            preg_match_all('/\[\/?[A-Za-z_][A-Za-z0-9_\-]*(?:\s[^\]]*)?\]/u', $s, $sc);
            preg_match_all('/<!--\s*\/?wp:[^>]*-->|<[^>]+>/u', $s, $tg);
            return [array_count_values($sc[0]), array_count_values($tg[0])];
        };
        [$sc_b, $tg_b] = $pick($before);
        [$sc_a, $tg_a] = $pick($after);

        $lost = static function (array $x, array $y): array {
            $r = [];
            foreach ($x as $tok => $cnt) {
                $d = $cnt - ($y[$tok] ?? 0);
                if ($d > 0) $r[] = $tok . ' ×' . $d;
            }
            return $r;
        };

        if ($l = $lost($sc_b, $sc_a)) $warn[] = 'ショートコードが消えています: ' . implode(' / ', array_slice($l, 0, 8));
        if ($l = $lost($sc_a, $sc_b)) $warn[] = 'ショートコードが増えています: ' . implode(' / ', array_slice($l, 0, 8));
        if ($l = $lost($tg_b, $tg_a)) $warn[] = 'HTMLタグ・ブロックが消えています（' . count($l) . '種類）: ' . implode(' / ', array_slice($l, 0, 5)) . (count($l) > 5 ? ' …' : '');
        if ($l = $lost($tg_a, $tg_b)) $warn[] = 'HTMLタグ・ブロックが増えています（' . count($l) . '種類）: ' . implode(' / ', array_slice($l, 0, 5)) . (count($l) > 5 ? ' …' : '');
        return $warn;
    }
}
