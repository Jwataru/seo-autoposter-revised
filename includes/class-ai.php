<?php
defined('ABSPATH') || exit;

/**
 * AI リライト処理（OpenAI / Anthropic Claude）
 */
class FAP_AI {

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

    private static function call_openai(string $api_key, string $model, string $user_prompt): array {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'      => $model,
                'max_tokens' => 2000,
                'messages'   => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
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
        if ($code !== 200 || !isset($body['choices'][0]['message']['content'])) {
            $err_msg = $body['error']['message'] ?? ('HTTP ' . $code);
            fap_log('[AI][OpenAI] APIエラー: ' . $err_msg);
            return self::err('OpenAI エラー: ' . $err_msg);
        }

        return self::parse_response($body['choices'][0]['message']['content']);
    }

    private static function call_claude(string $api_key, string $model, string $user_prompt): array {
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'      => $model,
                'max_tokens' => 2000,
                'system'     => self::SYSTEM_PROMPT,
                'messages'   => [
                    ['role' => 'user', 'content' => $user_prompt],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            fap_log('[AI][Claude] 接続失敗: ' . $response->get_error_message());
            return self::err($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($body === null) {
            fap_log('[AI][Claude] JSONデコード失敗: ' . json_last_error_msg());
            return self::err('AIサーバーからの応答が不正です。');
        }
        if ($code !== 200 || !isset($body['content'][0]['text'])) {
            $err_msg = $body['error']['message'] ?? ('HTTP ' . $code);
            fap_log('[AI][Claude] APIエラー: ' . $err_msg);
            return self::err('Claude エラー: ' . $err_msg);
        }

        return self::parse_response($body['content'][0]['text']);
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
