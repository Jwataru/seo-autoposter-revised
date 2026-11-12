<?php
defined('ABSPATH') || exit;

/**
 * AI リライト処理（OpenAI / Anthropic Claude）
 */
class FAP_AI {

    // 作品説明文（[comment]）専用リライトの既定プロンプト。[title] と [description] を置き換える。
    public const DESC_PROMPT = <<<'TXT'
あなたはアフィリエイトサイトの編集者です。以下の作品の紹介文（メーカー提供の説明文）を読み、読者向けの紹介文として、まったく新しい文章で書き直してください。

【ルール】
1. 元の文章の言い回し・語順・文の並びを使い回さず、内容を理解したうえで、自分の言葉で組み立て直す（単語の置き換えだけにしない）。
2. 元の文章にある事実（設定・登場人物・ジャンル・見どころ）だけを使い、ない情報は書き足さない。作品名・キャラクター名・サークル名・数値は、そのまま使う。
3. 文字数は元の60〜100%、2〜4文程度にまとめる。
4. 文体（です・ます調か、だ・である調か）は、元の文章に合わせる。誇大表現（「絶対」「必ず」「日本一」など）は使わない。価格・セール・割引の話は書かない。
5. ジャンル・題材・属性・シチュエーションを表す語（例: NTR、人妻、巨乳、学園、ハーレムなど）は、検索のキーワードになるので、元の文章にあるものはそのまま残す。エロいワードは削らずになるべく残す。
6. 出力は紹介文の本文だけにする。前置き、見出し、補足説明、HTMLタグ、全体を囲む引用符は付けない。

作品名: [title]

元の紹介文:
[description]
TXT;

    private const DESC_SYSTEM_PROMPT = 'あなたはアフィリエイトサイトの編集者です。指示に従い、紹介文の本文だけを出力してください。前置きや説明は一切不要です。';

    private const SYSTEM_PROMPT = "あなたはアフィリエイトブログのライターです。指定された記事タイトルと本文を読者に魅力が伝わるようリライトしてください。\n必ず以下の形式のみで返してください（余分なテキスト不要）：\nTITLE: リライト後のタイトル\n\nCONTENT:\nリライト後の本文";

    /**
     * タイトルと本文をAIでリライトする
     *
     * @return array{ok:bool, title:string, content:string, msg:string}
     */
    public static function rewrite(string $title, string $content): array {
        $provider   = fap_opt('ai_provider', 'openai');
        $api_key    = fap_secret('FAP_AI_API_KEY', 'ai_api_key');
        $model      = fap_opt('ai_model', '');
        $default_prompt = "以下の記事タイトルと本文を、読者に魅力が伝わるよう自然な日本語でリライトしてください。\n\nタイトル: [title]\n\n本文:\n[content]";
        $prompt_tpl     = fap_opt('ai_prompt_tpl', $default_prompt) ?: $default_prompt;

        if (!$api_key) {
            return self::err('AIのAPIキーが未設定です。');
        }

        $user_prompt = str_replace(['[title]', '[content]'], [$title, $content], $prompt_tpl);

        if ($provider === 'claude') {
            return self::call_claude($api_key, $model ?: 'claude-haiku-4-5-20251001', $user_prompt);
        }
        return self::call_openai($api_key, $model ?: 'gpt-4o-mini', $user_prompt);
    }

    /**
     * 作品説明文だけをAIでリライトする（本文のHTML・ショートコードには触れない）
     *
     * @return array{ok:bool, text:string, msg:string}
     */
    public static function rewrite_description(string $title, string $description): array {
        $provider = fap_opt('ai_provider', 'openai');
        $api_key  = fap_secret('FAP_AI_API_KEY', 'ai_api_key');
        $model    = fap_opt('ai_model', '');
        $tpl      = fap_opt('ai_desc_prompt', self::DESC_PROMPT) ?: self::DESC_PROMPT;

        if (!$api_key) {
            return ['ok' => false, 'text' => '', 'msg' => 'AIのAPIキーが未設定です。'];
        }

        $prompt = str_replace(['[title]', '[description]'], [$title, $description], $tpl);

        // 説明文は短いので出力上限は小さめ。上限で途切れた場合はエラー扱い（途中で切れた文を使わない）
        if ($provider === 'claude') {
            $r = self::call_claude($api_key, $model ?: 'claude-haiku-4-5-20251001', $prompt, self::DESC_SYSTEM_PROMPT, 1500, true);
        } else {
            $r = self::call_openai($api_key, $model ?: 'gpt-4o-mini', $prompt, self::DESC_SYSTEM_PROMPT, 800, true);
        }
        if (!$r['ok']) {
            return ['ok' => false, 'text' => '', 'msg' => $r['msg']];
        }

        // 念のため、全体を囲む引用符・コードブロック記号・タグを除去
        $text = trim($r['content']);
        $text = preg_replace('/^```[a-z]*\s*|\s*```$/u', '', $text);
        $text = trim(wp_strip_all_tags($text));
        // 全体を囲む括弧・引用符だけを除去（trim() の文字リストはバイト単位で日本語を壊すため preg を使う）
        $text = trim(preg_replace('/^[「"\']+|[」"\']+$/u', '', $text));
        if ($text === '') {
            return ['ok' => false, 'text' => '', 'msg' => 'AIの応答が空でした。'];
        }

        // 断りの返答が、そのまま説明文として保存されないようにする
        if (preg_match('/^(申し訳|すみません|お手伝いでき|お応えでき|対応でき|できません|I\s*(can\s*not|can\x27t|cannot|am\s*sorry|\x27m\s*sorry)|Sorry)/iu', $text)
            || preg_match('/(お手伝いできません|対応できません|書き直すことはできません|リライトできません|cannot\s+(help|assist|rewrite))/iu', $text)) {
            return ['ok' => false, 'text' => '', 'msg' => 'AIが書き直しを断りました（返答: ' . mb_substr($text, 0, 40) . '…）'];
        }
        // 極端に短い・長い場合は、元の説明文と大きく食い違うので採用しない
        $len_src = mb_strlen($description);
        $len_new = mb_strlen($text);
        if ($len_new < 15 || ($len_src > 0 && $len_new > $len_src * 3 + 100)) {
            return ['ok' => false, 'text' => '', 'msg' => 'AIの返答の長さが不自然でした（' . $len_new . '文字）'];
        }
        return ['ok' => true, 'text' => $text, 'msg' => ''];
    }

    /**
     * ダミーテキストでAPI接続を確認する
     *
     * @return array{ok:bool, msg:string}
     */
    public static function test_connection(): array {
        $result = self::rewrite('テスト用タイトル', 'これはAPIの接続テスト用のサンプル本文です。正常に動作しているか確認中です。');
        if (!$result['ok']) {
            return ['ok' => false, 'msg' => $result['msg']];
        }
        return ['ok' => true, 'msg' => '接続成功。AIからの応答を受信しました。'];
    }

    // -----------------------------------------------------------------------

    private static function call_openai(string $api_key, string $model, string $user_prompt, ?string $system = null, int $max_tokens = 2000, bool $raw = false): array {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'messages'   => [
                    ['role' => 'system', 'content' => $system ?? self::SYSTEM_PROMPT],
                    ['role' => 'user',   'content' => $user_prompt],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            fap_log('[AI][OpenAI] 接続失敗: ' . $response->get_error_message());
            return self::err($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($body === null) {
            fap_log('[AI][OpenAI] JSONデコード失敗: ' . json_last_error_msg());
            return self::err('AIサーバーからの応答が不正です。');
        }
        $oa_text = (string) ($body['choices'][0]['message']['content'] ?? '');
        if ($code !== 200 || $oa_text === '') {
            $err_msg = (string) ($body['error']['message'] ?? '');
            if ($err_msg === '') {
                $refusal = (string) ($body['choices'][0]['message']['refusal'] ?? '');
                $finish  = (string) ($body['choices'][0]['finish_reason'] ?? '');
                $err_msg = $refusal !== ''
                    ? 'AIが応答を拒否しました: ' . mb_substr($refusal, 0, 80)
                    : 'HTTP ' . $code . '：本文なし（finish_reason=' . ($finish ?: '不明') . '）';
            }
            fap_log('[AI][OpenAI] APIエラー: ' . $err_msg);
            return self::err('OpenAI エラー: ' . $err_msg);
        }

        if ($raw) {
            if (($body['choices'][0]['finish_reason'] ?? '') === 'length') {
                return self::err('AIの応答が出力上限で途切れました。');
            }
            return ['ok' => true, 'title' => '', 'content' => (string) $body['choices'][0]['message']['content'], 'msg' => ''];
        }
        return self::parse_response($body['choices'][0]['message']['content']);
    }

    private static function call_claude(string $api_key, string $model, string $user_prompt, ?string $system = null, int $max_tokens = 2000, bool $raw = false): array {
        // 新しいモデル（Haiku 5.5 など）は、既定で「考える処理（thinking）」が有効で、
        // 考える処理が max_tokens を使い切ると本文が返ってこない。
        // リライトには不要なので、thinking は無効にして呼び出す。
        $payload = [
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'system'     => $system ?? self::SYSTEM_PROMPT,
            'messages'   => [
                ['role' => 'user', 'content' => $user_prompt],
            ],
            'thinking'   => ['type' => 'disabled'],
        ];

        $response = null;
        $code     = 0;
        $body     = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
                'timeout' => 60,
                'headers' => [
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type'      => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]);

            if (is_wp_error($response)) {
                fap_log('[AI][Claude] 接続失敗: ' . $response->get_error_message());
                return self::err($response->get_error_message());
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            // thinking の無効化を受け付けないモデルの場合：thinking 指定を外し、出力上限を増やして1回だけ再試行
            if ($attempt === 1 && $code === 400 && is_array($body)
                && stripos((string) ($body['error']['message'] ?? ''), 'thinking') !== false) {
                fap_log('[AI][Claude] thinking無効化が非対応のため、指定を外して再試行します');
                unset($payload['thinking']);
                $payload['max_tokens'] = max($max_tokens, 4096);
                continue;
            }
            break;
        }

        if ($body === null) {
            fap_log('[AI][Claude] JSONデコード失敗: ' . json_last_error_msg());
            return self::err('AIサーバーからの応答が不正です。');
        }

        // type=text のブロックだけを集める（先頭に thinking 等の別ブロックが来ても、本文を取り出せるように）
        $text  = '';
        $types = [];
        foreach ((array) ($body['content'] ?? []) as $block) {
            $types[] = (string) ($block['type'] ?? '?');
            if (($block['type'] ?? '') === 'text') $text .= (string) ($block['text'] ?? '');
        }
        $stop = (string) ($body['stop_reason'] ?? '');

        if ($code !== 200 || $text === '') {
            $err_msg = (string) ($body['error']['message'] ?? '');
            if ($err_msg === '') {
                // HTTP 200 なのに本文がない場合：理由（stop_reason）と応答の中身の種類を残す
                $err_msg = ($stop === 'refusal')
                    ? 'AIが応答を拒否しました（stop_reason=refusal）。内容がAI側の安全基準に該当した可能性があります'
                    : 'HTTP ' . $code . '：本文なし（stop_reason=' . ($stop ?: '不明') . ' / content=' . ($types ? implode(',', $types) : 'なし') . '）';
            }
            fap_log('[AI][Claude] APIエラー: ' . $err_msg);
            return self::err('Claude エラー: ' . $err_msg);
        }

        if ($raw) {
            if ($stop === 'max_tokens') {
                return self::err('AIの応答が出力上限で途切れました。');
            }
            return ['ok' => true, 'title' => '', 'content' => $text, 'msg' => ''];
        }
        return self::parse_response($text);
    }

    private static function parse_response(string $text): array {
        $new_title   = '';
        $new_content = '';

        if (preg_match('/^TITLE:\s*(.+)/m', $text, $m)) {
            $new_title = trim($m[1]);
        }
        if (preg_match('/^CONTENT:\s*([\s\S]+)$/m', $text, $m)) {
            $new_content = trim($m[1]);
        }

        // フォーマット非遵守の場合は全テキストを本文として扱う
        if (!$new_title && !$new_content) {
            $new_content = trim($text);
        }

        return ['ok' => true, 'title' => $new_title, 'content' => $new_content, 'msg' => ''];
    }

    private static function err(string $msg): array {
        return ['ok' => false, 'title' => '', 'content' => '', 'msg' => $msg];
    }
}
