/* ============================================================
   FANZA SEO Autoposter - Admin JavaScript
   ============================================================ */

jQuery(function ($) {

    // ----------------------------------------------------------
    // プレビュー用 iframe ドキュメント生成
    // 追加CSS・基本スタイル・日付置換を含むフルHTMLを返す
    // ----------------------------------------------------------
    function fapPreviewDoc(body, css) {
        var t = new Date();
        var today = t.getFullYear() + '年' + (t.getMonth() + 1) + '月' + t.getDate() + '日';
        var content = body.replace(/>本日</g, '>' + today + '<');
        return '<!DOCTYPE html><html lang="ja"><head>'
            + '<meta charset="UTF-8">'
            + '<meta name="viewport" content="width=device-width,initial-scale=1">'
            + '<style>*{box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans","Noto Sans JP",sans-serif;padding:16px;max-width:720px;margin:0 auto;color:#333;line-height:1.7;font-size:15px}img{max-width:100%;height:auto}a{color:#e53935}ul,ol{padding-left:1.5em}p{margin:0 0 1em}strong{font-weight:bold}</style>'
            + (css ? '<style>' + css + '</style>' : '')
            + '</head><body>'
            + content
            + '</body></html>';
    }

    // ----------------------------------------------------------
    // カラーピッカー初期化
    // ----------------------------------------------------------
    $('.fap-color-picker').wpColorPicker({
        width: 200
    });

    // ----------------------------------------------------------
    // フロアセレクトの変更 → 隠しフィールドに label を反映
    // ----------------------------------------------------------
    $('#fap-floor-select').on('change', function () {
        var selected = $(this).find(':selected');
        var label    = selected.data('label') || selected.text();
        $('#fap-flr-name').val(label);
    });

    // ----------------------------------------------------------
    // AJAX：APIテスト
    // ----------------------------------------------------------
    $('#fap-test-api').on('click', function (e) {
        e.preventDefault();
        var $btn    = $(this);
        var $result = $('#fap-test-result');
        var apiId   = $('#fap_api_id').val().trim();
        var affId   = $('#fap_aff_id').val().trim();

        if (!apiId || !affId) {
            $result.text('API IDとアフィリエイトIDを入力してください。').css('color', '#d63638');
            return;
        }

        $btn.prop('disabled', true).text('確認中...');
        $result.text('').css('color', '');

        $.post(fapData.ajaxUrl, {
            action: 'fap_test_api',
            nonce:  fapData.nonce,
            api_id: apiId,
            aff_id: affId
        }, function (resp) {
            if (resp.success) {
                $result.text(resp.data).css('color', '#00a32a');
            } else {
                $result.text(resp.data).css('color', '#d63638');
            }
        }).fail(function () {
            $result.text('通信エラーが発生しました。').css('color', '#d63638');
        }).always(function () {
            $btn.prop('disabled', false).text('接続テスト（保存せずに確認）');
        });
    });

    // ----------------------------------------------------------
    // 時間帯チェックボックス：Shift クリックで範囲選択（.fap-sched-table 対応）
    // ----------------------------------------------------------
    var $hourCheckboxes = $('.fap-sched-table input[type="checkbox"]');
    var lastChecked     = null;

    $hourCheckboxes.on('click', function (e) {
        if (e.shiftKey && lastChecked !== null) {
            var start = $hourCheckboxes.index(lastChecked);
            var end   = $hourCheckboxes.index(this);
            var state = $(this).prop('checked');
            $hourCheckboxes.slice(Math.min(start, end), Math.max(start, end) + 1)
                           .prop('checked', state);
        }
        lastChecked = this;
    });

    // ----------------------------------------------------------
    // 投稿日時ラジオ：関連フィールドの表示切り替え
    // ----------------------------------------------------------
    function toggleDateFields() {
        var mode = $('input[name="post_date"]:checked').val();
        $('[data-post-date]').hide();
        $('[data-post-date="' + mode + '"]').show();
    }
    $('input[name="post_date"]').on('change', toggleDateFields);
    toggleDateFields();

    // ----------------------------------------------------------
    // AJAX：テンプレートのプレビュー
    // ----------------------------------------------------------
    $('.fap-preview-btn').on('click', function () {
        var $btn  = $(this);
        var slot  = $btn.data('slot');
        var $area = $('#fap-preview-' + slot);

        $btn.prop('disabled', true).text('生成中...');
        $.post(fapData.ajaxUrl, {
            action: 'fap_preview',
            nonce:  fapData.nonce,
            slot:   slot
        }, function (resp) {
            if (resp.success) {
                var d = resp.data;
                $area.find('.fap-preview-cid').text(d.cid ? '（' + d.cid + '）' : '');
                $area.find('.fap-preview-title').html(
                    '<p style="font-size:18px;font-weight:700;margin:0 0 12px;">' +
                    $('<div>').text(d.title || '(タイトルなし)').html() + '</p>'
                );
                var $pFrame = $('<iframe sandbox="allow-popups" style="width:100%;min-height:400px;border:1px solid #ddd;border-radius:4px;" frameborder="0"></iframe>');
                $area.find('.fap-preview-body').html('').append($pFrame);
                $pFrame[0].srcdoc = fapPreviewDoc(d.content || '', d.css || '');
                $area.show();
                $area[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                $area.find('.fap-preview-body').html('<p style="color:#d63638;">プレビュー失敗: ' + (resp && resp.data ? resp.data : '不明なエラー') + '</p>');
                $area.show();
            }
        }).fail(function () {
            $area.find('.fap-preview-body').html('<p style="color:#d63638;">通信エラーが発生しました。</p>');
            $area.show();
        }).always(function () {
            $btn.prop('disabled', false).text('👁 プレビュー');
        });
    });

    // ----------------------------------------------------------
    // AJAX：ライセンスキー認証
    // ----------------------------------------------------------
    $('#fap-verify-license').on('click', function () {
        var $btn    = $(this);
        var $result = $('#fap-license-result');
        var key     = $('#fap-license-key').val().trim();

        if ($btn.data('pending')) return;
        if (!key) {
            $result.html('<span style="color:#d63638;">ライセンスキーを入力してください。</span>');
            return;
        }

        $btn.data('pending', true).prop('disabled', true).text('認証中...');
        $result.html('<span style="color:#64748b;">サーバーに確認しています...</span>');

        $.post(fapData.ajaxUrl, {
            action:      'fap_verify_license',
            nonce:       fapData.nonce,
            license_key: key
        }, function (resp) {
            if (resp.success) {
                $result.html('<span style="color:#00a32a;font-weight:bold;">✅ ' + resp.data.message + ' — ページを再読み込みしています...</span>');
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                $result.html('<span style="color:#d63638;">❌ ' + resp.data + '</span>');
            }
        }).fail(function () {
            $result.html('<span style="color:#d63638;">通信エラーが発生しました。</span>');
        }).always(function () {
            $btn.data('pending', false).prop('disabled', false).text('ライセンスキーを認証する');
        });
    });

    // ----------------------------------------------------------
    // AJAX：ランキング記事プレビュー
    // ----------------------------------------------------------
    $('#fap-ranking-preview-btn').on('click', function () {
        var $btn  = $(this);
        var $area = $('#fap-ranking-preview-area');
        var $body = $('#fap-ranking-preview-body');
        var $note = $('#fap-ranking-preview-note');

        $btn.prop('disabled', true).text('取得中...');
        $body.html('<p style="color:#666;">APIから上位3件を取得しています...</p>');
        $area.show();

        $.post(fapData.ajaxUrl, {
            action: 'fap_ranking_preview',
            nonce:  fapData.nonce,
            rk:     $('input[name="rk"]').first().val() || 1
        }, function (resp) {
            if (resp.success) {
                var $rFrame = $('<iframe sandbox="allow-popups" style="width:100%;min-height:300px;border:1px solid #ddd;border-radius:4px;" frameborder="0"></iframe>');
                $body.html('').append($rFrame);
                $rFrame[0].srcdoc = fapPreviewDoc(resp.data.html || '', resp.data.css || '');
                $note.text('（上位3件 / 説明文・画像合成はなし）');
                $area[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                $body.html('<p style="color:#d63638;">エラー: ' + resp.data + '</p>');
                $note.text('');
            }
        }).fail(function () {
            $body.html('<p style="color:#d63638;">通信エラーが発生しました。</p>');
        }).always(function () {
            $btn.prop('disabled', false).text('👁 プレビュー（上位3件）');
        });
    });

    // ----------------------------------------------------------
    // 投稿一覧 rowアクション：AIリライト
    // ----------------------------------------------------------
    $(document).on('click', '.fap-row-ai-rewrite', function (e) {
        e.preventDefault();
        var $link   = $(this);
        var postId  = $link.data('post-id');
        var nonce   = $link.data('nonce');
        var origTxt = $link.text();

        if (!confirm('投稿 ID=' + postId + ' をAIでリライトします。元のタイトル・本文は _fap_original_title / _fap_original_content に保存されます。続行しますか？')) return;

        $link.text('処理中...').css('pointer-events', 'none');

        $.post(fapData.ajaxUrl, {
            action:   'fap_manual_ai_rewrite',
            _fap_nonce: nonce,
            post_id:  postId
        }, function (resp) {
            if (resp && resp.success) {
                alert('✅ ' + resp.data.msg);
                location.reload();
            } else {
                alert('❌ ' + (resp && resp.data ? resp.data : '不明なエラーが発生しました。'));
                $link.text(origTxt).css('pointer-events', '');
            }
        }).fail(function () {
            alert('通信エラーが発生しました。');
            $link.text(origTxt).css('pointer-events', '');
        });
    });

    // ----------------------------------------------------------
    // AI設定ページ：手動リライト（投稿IDを指定）
    // ----------------------------------------------------------
    $('#fap-ai-rewrite-btn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#fap-ai-rewrite-result');
        var postId  = parseInt($('#fap-ai-rewrite-id').val(), 10);

        if (!postId || postId < 1) {
            $result.text('投稿IDを入力してください。').css('color', '#dc2626');
            return;
        }
        if (!confirm('投稿 ID=' + postId + ' をAIでリライトします。続行しますか？')) return;

        $btn.prop('disabled', true);
        $result.text('処理中...').css('color', '#555');

        $.post(fapData.ajaxUrl, {
            action:     'fap_manual_ai_rewrite',
            _fap_nonce: fapData.fap_action_nonce,
            post_id:    postId
        }, function (resp) {
            if (resp && resp.success) {
                $result.css('color', '#16a34a').text(resp.data.msg);
            } else {
                $result.css('color', '#dc2626').text(resp && resp.data ? resp.data : '不明なエラーが発生しました。');
            }
        }).fail(function () {
            $result.css('color', '#dc2626').text('通信エラーが発生しました。');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    // ----------------------------------------------------------
    // 販売停止作品チェック（バッチAJAX：offsetを進めながら全件確認）
    // ----------------------------------------------------------
    (function () {
        var $checkBtn = $('#fap-disc-check');
        if (!$checkBtn.length) return;

        var $progress = $('#fap-disc-progress');
        var $results  = $('#fap-disc-results');
        var $list     = $('#fap-disc-list');
        var $trashBtn = $('#fap-disc-trash');
        var running   = false;
        var errorTotal = 0;

        function esc(s) {
            return $('<span>').text(s == null ? '' : String(s)).html();
        }

        function appendRows(items) {
            items.forEach(function (item) {
                $list.append(
                    '<tr>' +
                    '<td><input type="checkbox" class="fap-disc-item" value="' + parseInt(item.id, 10) + '" checked></td>' +
                    '<td><a href="' + esc(item.view_url) + '" target="_blank" rel="noopener">' + esc(item.title) + '</a>' +
                    ' <a href="' + esc(item.edit_url) + '" target="_blank" rel="noopener">[編集]</a></td>' +
                    '<td>' + esc(item.cid) + '</td>' +
                    '<td>' + esc(item.date) + '</td>' +
                    '</tr>'
                );
            });
        }

        function finish(msg, color) {
            running = false;
            $checkBtn.prop('disabled', false).text('販売停止チェックを開始');
            $progress.text(msg).css('color', color || '#555');
        }

        function step(offset) {
            $.post(fapData.ajaxUrl, {
                action: 'fap_discontinued_check',
                nonce:  fapData.nonce,
                offset: offset
            }, function (resp) {
                if (!resp || !resp.success) {
                    finish(resp && resp.data ? resp.data : 'エラーが発生しました。', '#dc2626');
                    return;
                }
                var d = resp.data;
                errorTotal += d.errors;
                if (d.discontinued.length) {
                    appendRows(d.discontinued);
                    $results.show();
                }
                var found = $list.children().length;
                if (d.done) {
                    var msg = 'チェック完了：全 ' + d.total + ' 件中、販売停止 ' + found + ' 件';
                    if (errorTotal > 0) msg += '（' + errorTotal + ' 件は確認できず。時間をおいて再実行してください）';
                    finish(msg, found > 0 ? '#dc2626' : '#16a34a');
                    if (found === 0) $results.hide();
                } else {
                    $progress.text('チェック中... ' + d.next_offset + ' / ' + d.total + ' 件（販売停止 ' + found + ' 件）');
                    step(d.next_offset);
                }
            }).fail(function () {
                finish('通信エラーが発生しました。再実行してください。', '#dc2626');
            });
        }

        $checkBtn.on('click', function () {
            if (running) return;
            if (!confirm('公開中のFAP記事を全件チェックします。記事数が多いと数分かかります。開始しますか？')) return;
            running    = true;
            errorTotal = 0;
            $list.empty();
            $results.hide();
            $checkBtn.prop('disabled', true).text('チェック中...');
            $progress.text('チェックを開始しました...').css('color', '#555');
            step(0);
        });

        // 全選択チェックボックス
        $('#fap-disc-all').on('change', function () {
            $('.fap-disc-item').prop('checked', $(this).prop('checked'));
        });

        // 一括ゴミ箱移動
        $trashBtn.on('click', function () {
            var ids = $('.fap-disc-item:checked').map(function () {
                return parseInt(this.value, 10);
            }).get();

            if (!ids.length) {
                alert('記事が選択されていません。');
                return;
            }
            if (!confirm(ids.length + ' 件の記事をゴミ箱へ移動します。よろしいですか？\n（ゴミ箱からは復元できます）')) return;

            $trashBtn.prop('disabled', true).text('移動中...');

            $.post(fapData.ajaxUrl, {
                action:   'fap_discontinued_trash',
                nonce:    fapData.nonce,
                post_ids: ids
            }, function (resp) {
                if (resp && resp.success) {
                    var msg = resp.data.trashed + ' 件をゴミ箱へ移動しました。';
                    if (resp.data.skipped > 0) msg += '（' + resp.data.skipped + ' 件はスキップ）';
                    $progress.text(msg).css('color', '#16a34a');
                    $('.fap-disc-item:checked').closest('tr').remove();
                    if (!$list.children().length) $results.hide();
                } else {
                    $progress.text(resp && resp.data ? resp.data : 'ゴミ箱への移動に失敗しました。').css('color', '#dc2626');
                }
            }).fail(function () {
                $progress.text('通信エラーが発生しました。').css('color', '#dc2626');
            }).always(function () {
                $trashBtn.prop('disabled', false).text('選択した記事をゴミ箱へ移動');
            });
        });
    })();

    // ----------------------------------------------------------
    // 管理画面通知の自動非表示（3秒）
    // ----------------------------------------------------------
    setTimeout(function () {
        $('.notice.is-dismissible').not('.notice-error').fadeOut(600);
    }, 5000);

});
