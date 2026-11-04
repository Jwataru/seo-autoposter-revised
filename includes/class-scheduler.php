<?php
defined('ABSPATH') || exit;

/**
 * WP-Cron ベースの自動実行スケジューラー
 */
class FAP_Scheduler {

    public function __construct() {
        add_action('fap_hourly_hook', array($this, 'run'));
    }

    public function run(): void {
        if (!FAP_License::is('pro')) return;
        if (fap_opt('auto_on', '0') !== '1') return;

        $api    = new FAP_API();
        $poster = new FAP_Poster($api);

        // スロット実行（取りこぼした時間帯をまとめて処理する繰り越し方式）
        $this->run_due_hours($poster);

        // ランキング記事の自動生成チェック（PREMIUM・スロット1〜4）
        if (FAP_License::is('premium')) {
            for ($rk = 1; $rk <= 4; $rk++) {
                $this->maybe_run_ranking_slot($poster, $rk);
            }
        }

        // セール検知＆自動リライト（PREMIUM・1日1回）
        if (FAP_License::is('premium') && fap_opt('sale_check_on', '0') === '1') {
            $this->maybe_run_sale_check($poster);
        }
    }

    // -----------------------------------------------------------------------
    // 未消化の時間帯をまとめて実行する（繰り越し方式）
    //
    // WP-Cron が低トラフィックで発火を取りこぼすと、該当時刻のスロットが
    // 消えてしまう問題への対策。前回発火時刻を記録しておき、発火時に
    // 「前回発火 < 時間帯の実行予定時刻 <= 今」に入る全時間帯を処理する。
    //   ・半開区間(>と<=)で判定するため同じ時間帯を二重に実行しない
    //   ・下限を当日0時で切るため前日分は繰り越さない（同日内のみ）
    //   ・初回発火（記録なし）は現在の時間帯のみ実行し過去分の暴発を防ぐ
    // -----------------------------------------------------------------------
    private function run_due_hours(FAP_Poster $poster): void {
        $hour_slots = (array) fap_opt('hour_slots', []);
        if (empty($hour_slots)) return;

        $now_ts     = current_time('timestamp');
        $today0     = strtotime(current_time('Y-m-d') . ' 00:00:00');
        $exe_minute = min(59, max(0, (int) fap_opt('exe_minute', 0)));
        $last_fire  = (int) fap_opt('slot_last_fire_ts', 0);

        if ($last_fire <= 0) {
            // 初回発火：現在の時間帯のみを対象にする（過去分の暴発を防ぐ）
            $cur_h       = (int) current_time('G');
            $lower_bound = $today0 + $cur_h * 3600 + $exe_minute * 60 - 1;
        } else {
            // 2回目以降：前回発火〜今回発火の間の未消化を拾う（当日0時より前は切る）
            $lower_bound = max($last_fire, $today0);
        }

        // 対象となる時間帯（実行予定時刻が下限超〜現在以下）を集める
        $due = [];
        foreach (array_keys($hour_slots) as $hkey) {
            if (!preg_match('/^h(\d{2})$/', $hkey, $m)) continue;
            $h         = (int) $m[1];
            $slot_time = $today0 + $h * 3600 + $exe_minute * 60;
            if ($slot_time > $lower_bound && $slot_time <= $now_ts) {
                $due[$h] = $hkey;
            }
        }
        ksort($due);

        // 発火時刻を記録（今回対象が無くても更新して区間を進める）
        update_option(FAP_PREFIX . 'slot_last_fire_ts', $now_ts);

        if (empty($due)) return;

        $catchup = count($due) > 1 ? '（繰り越し' . count($due) . '時間帯）' : '';
        fap_log('[Scheduler] 実行開始 hours=' . implode(',', array_keys($due)) . $catchup);

        foreach ($due as $hkey) {
            $slots = (array) $hour_slots[$hkey];
            foreach ($slots as $slot_n) {
                $this->execute_slot($poster, (int) $slot_n);
            }
        }

        fap_log('[Scheduler] 実行完了');
    }

    // 単一スロットを実行してログ・エラー通知を行う
    private function execute_slot(FAP_Poster $poster, int $slot): void {
        if ($slot < 1 || $slot > FAP_SLOTS) return;

        if (fap_slot_opt($slot, 'active', '0') !== '1') {
            fap_log('[Scheduler][Slot ' . $slot . '] スキップ（有効化OFF）');
            return;
        }

        $hits = (int) fap_slot_opt($slot, 'hits', 0);
        if ($hits < 1) {
            fap_log('[Scheduler][Slot ' . $slot . '] スキップ（投稿件数0）');
            return;
        }

        $result = $poster->run_slot($slot, 'auto');

        fap_log(sprintf(
            '[Scheduler][Slot %d] 投稿:%d スキップ:%d%s',
            $slot,
            $result['posted'],
            $result['skipped'],
            $result['error'] ? ' エラー:' . $result['error'] : ''
        ));

        if ($result['error'] && fap_opt('notify_on_error', '0') === '1') {
            $to      = fap_opt('notify_email', '') ?: get_option('admin_email');
            $subject = '[FANZA Autoposter] スロット' . $slot . ' でエラーが発生しました';
            $body    = sprintf(
                "スロット %d の自動実行でエラーが発生しました。\n\nエラー内容: %s\n\n発生日時: %s\n管理画面: %s",
                $slot,
                $result['error'],
                current_time('Y-m-d H:i:s'),
                admin_url('admin.php?page=fanza-seo-autoposter')
            );
            wp_mail($to, $subject, $body);
        }
    }

    // ランキング記事の自動生成（週次/月次・スロットごとに独立したスケジュールで動く）
    private function maybe_run_ranking_slot(FAP_Poster $poster, int $rk): void {
        $p = $rk <= 1 ? 'ranking_' : 'ranking' . $rk . '_';

        $schedule = fap_opt($p . 'schedule', 'none');
        if ($schedule === 'none') return;

        $floor = fap_opt($p . 'floor', '');
        if (!$floor) return;

        $last_at = (string) fap_opt($p . 'last_at', '');
        $last_ts = $last_at ? (int) strtotime($last_at) : 0;
        $now_ts  = current_time('timestamp');

        if ($schedule === 'weekly') {
            $weekly_day  = (int) fap_opt($p . 'weekly_day', 1); // 1=月曜...7=日曜
            $current_day = (int) date('N', $now_ts);
            if ($current_day !== $weekly_day) return;
            if ($last_ts && ($now_ts - $last_ts) < 6 * DAY_IN_SECONDS) return;
        } elseif ($schedule === 'monthly') {
            if ((int) date('j', $now_ts) !== 1) return;
            if ($last_ts && ($now_ts - $last_ts) < 25 * DAY_IN_SECONDS) return;
        }

        $result = $poster->run_ranking($rk);
        if ($result['ok']) {
            fap_log('[Ranking][Scheduler] スロット' . $rk . ' 自動生成完了 post_id=' . $result['post_id'] . ' count=' . $result['count']);
        } else {
            fap_log('[Ranking][Scheduler] スロット' . $rk . ' 自動生成失敗: ' . $result['msg']);
        }
    }

    // セール検知: 1時間ごとに50件ずつ処理し、全記事を1日1周する
    // （1周が完了した日はその日の残り時間はスキップ。翌日また先頭から）
    private function maybe_run_sale_check(FAP_Poster $poster): void {
        if ((string) fap_opt('sale_check_done_date', '') === current_time('Y-m-d')) return;
        $poster->run_sale_check();
    }

    // -----------------------------------------------------------------------
    // WP-Cron の登録（アクティベーション時・設定保存時に呼ぶ）
    // exe_minute 設定に合わせて次回実行時刻を計算してスケジュールする
    // -----------------------------------------------------------------------
    public static function register(): void {
        wp_clear_scheduled_hook('fap_hourly_hook');

        $exe_minute = min(59, max(0, (int) fap_opt('exe_minute', 0)));

        // wp_schedule_event はUTCタイムスタンプを要求するため time() を使う
        $now_ts  = time();
        $now_min = (int) gmdate('i', $now_ts);

        if ($now_min < $exe_minute) {
            // 同じ時間内にまだ指定分が来ていない → その分まで待つ
            $start = $now_ts + ($exe_minute - $now_min) * 60;
        } else {
            // 指定分はすでに過ぎた → 次の時間の指定分
            $start = $now_ts + (60 - $now_min + $exe_minute) * 60;
        }

        wp_schedule_event($start, 'hourly', 'fap_hourly_hook');
    }

    public static function unregister(): void {
        wp_clear_scheduled_hook('fap_hourly_hook');
    }

    // -----------------------------------------------------------------------
    // 次回実行予定日時を返す（管理画面表示用）
    // -----------------------------------------------------------------------
    public static function next_run_label(): string {
        if (fap_opt('auto_on', '0') !== '1') return '未スケジュール';

        $hour_slots = (array) fap_opt('hour_slots', []);
        $configured_hours = [];
        foreach (array_keys($hour_slots) as $hkey) {
            if (preg_match('/^h(\d{2})$/', $hkey, $m)) {
                $configured_hours[] = (int) $m[1];
            }
        }
        sort($configured_hours);

        if (empty($configured_hours)) return '時間帯が未設定です';

        $exe_minute = (int) fap_opt('exe_minute', 0);
        $now_h      = (int) current_time('G');
        $now_m      = (int) current_time('i');

        $next_hour = null;
        $add_day   = false;

        foreach ($configured_hours as $h) {
            if ($h > $now_h || ($h === $now_h && $now_m < $exe_minute)) {
                $next_hour = $h;
                break;
            }
        }

        if ($next_hour === null) {
            $next_hour = $configured_hours[0];
            $add_day   = true;
        }

        $next_date = $add_day
            ? wp_date('Y-m-d', time() + DAY_IN_SECONDS)
            : wp_date('Y-m-d');

        return sprintf('%s %02d:%02d', $next_date, $next_hour, $exe_minute);
    }
}
