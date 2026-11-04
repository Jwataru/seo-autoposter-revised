/* FANZA SEO Autoposter - front.js (記事ページ用) */
(function () {
    'use strict';

    var data = (typeof fapData !== 'undefined') ? fapData : {};
    var ajaxUrl = data.ajaxUrl || '';
    var nonce   = data.nonce   || '';
    var postId  = data.postId  || 0;
    var trackOn = data.trackOn === '1';

    // -----------------------------------------------------------------------
    // CTR計測（AJAX送信ヘルパー）
    // -----------------------------------------------------------------------
    function sendTrack(action) {
        if (!ajaxUrl || !nonce || !postId || !trackOn) return;
        var fd = new FormData();
        fd.append('action',  action);
        fd.append('nonce',   nonce);
        fd.append('post_id', postId);
        navigator.sendBeacon
            ? navigator.sendBeacon(ajaxUrl, fd)
            : fetch(ajaxUrl, { method: 'POST', body: fd }).catch(function () {});
    }

    document.addEventListener('DOMContentLoaded', function () {

        /* --- 追従CTA：少しスクロールしたら表示、最下部付近では隠す --- */
        var cta = document.getElementById('fap-sticky-cta');
        if (cta) {
            var onScroll = function () {
                var y       = window.pageYOffset || document.documentElement.scrollTop;
                var docH    = document.documentElement.scrollHeight;
                var winH    = window.innerHeight;
                var nearBtm = (y + winH) >= (docH - 120);
                if (y > 480 && !nearBtm) {
                    cta.classList.add('is-visible');
                } else {
                    cta.classList.remove('is-visible');
                }
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            window.addEventListener('resize', onScroll, { passive: true });
            onScroll();
        }

        /* --- CTR: ページビューを1回だけ記録（セッション内重複排除） --- */
        if (trackOn && postId) {
            var key = 'fap_viewed_' + postId;
            if (!sessionStorage.getItem(key)) {
                sessionStorage.setItem(key, '1');
                sendTrack('fap_track_view');
            }
        }

        /* --- CTR: アフィリエイトリンクのクリックを記録 --- */
        if (trackOn) {
            document.addEventListener('click', function (e) {
                var a = e.target.closest('a[href]');
                if (!a) return;
                var href = a.getAttribute('href') || '';
                // nofollow sponsored = FAP アフィリリンク
                var rel = (a.getAttribute('rel') || '').toLowerCase();
                if (rel.indexOf('sponsored') !== -1 || href.indexOf('dmm.co.jp') !== -1) {
                    sendTrack('fap_track_click');
                }
            }, { passive: true });
        }
    });
})();
