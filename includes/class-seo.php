<?php
defined('ABSPATH') || exit;

/**
 * SEO出力（JSON-LD / OGP / Twitter Card / 画像サイトマップ / パンくず）
 */
class FAP_SEO {

    public function __construct() {
        add_action('wp_head',           [$this, 'output_head'], 3);
        add_action('init',              [$this, 'register_sitemap_rule']);
        add_filter('query_vars',        [$this, 'add_sitemap_var']);
        add_action('template_redirect', [$this, 'serve_sitemap']);
        add_filter('wp_kses_allowed_html', [$this, 'allow_attrs'], 10, 2);
    }

    // -----------------------------------------------------------------------
    // wp_head 出力
    // -----------------------------------------------------------------------
    public function output_head(): void {
        if (!FAP_License::is('pro')) return;
        if (!is_single()) return;

        $post_id = get_the_ID();
        if (!$post_id) return;

        $cid = get_post_meta($post_id, '_fap_cid', true);
        if (!$cid) return; // FAP で生成された投稿のみ対象

        $post = get_post($post_id);

        // カスタムメタディスクリプション（スロット設定で指定された場合のみ）
        $custom_desc = get_post_meta($post_id, '_fap_meta_desc', true);
        if ($custom_desc) {
            echo "\n<!-- FAP Meta Description -->\n";
            printf('<meta name="description" content="%s" />' . "\n", esc_attr($custom_desc));
        }

        if (fap_opt('seo_jsonld', '1') === '1') {
            $this->output_jsonld($post);
        }
        if (fap_opt('seo_og', '1') === '1') {
            $this->output_og($post);
        }
        if (fap_opt('seo_twitter', '1') === '1') {
            $this->output_twitter($post);
        }
        if (fap_opt('seo_breadcrumb', '0') === '1') {
            $this->output_breadcrumb($post);
        }
    }

    // -----------------------------------------------------------------------
    // JSON-LD（Article + VideoObject + AggregateRating）
    // -----------------------------------------------------------------------
    private function output_jsonld(WP_Post $post): void {
        $meta    = $this->get_meta($post->ID);
        $blog    = get_bloginfo('name');
        $url     = get_permalink($post->ID);
        $title   = $meta['title'] ?: get_the_title($post->ID);
        $actress = $meta['actress'];
        $maker   = $meta['maker'];
        $genre   = $meta['genre'];
        $image   = $meta['package_image'];
        $service = $meta['service'];
        $avg     = (float) $meta['review_avg'];
        $cnt     = (int)   $meta['review_cnt'];

        // Article スキーマ
        $article = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => $title . ' レビュー',
            'url'              => $url,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'author'           => ['@type' => 'Organization', 'name' => $blog],
            'publisher'        => ['@type' => 'Organization', 'name' => $blog],
            'datePublished'    => $post->post_date_gmt,
            'dateModified'     => $post->post_modified_gmt,
        ];
        if ($image) {
            $article['image'] = [
                '@type'   => 'ImageObject',
                'url'     => $image,
                'caption' => '『' . $title . '』パッケージ画像',
            ];
        }
        if ($genre)  $article['keywords'] = $genre;
        if ($maker || $actress) {
            $article['about'] = ['@type' => 'CreativeWork', 'name' => $title];
            if ($maker)   $article['about']['creator'] = $maker;
            if ($actress) $article['about']['actor']   = $actress;
        }
        $this->print_jsonld($article);

        // Product スキーマ（価格・レビューがある場合のみ：レビュー/価格のリッチリザルト対象）
        // ※ AggregateRating は Article ではなく Product に載せるのが Google の要件
        $price_num = preg_replace('/[^0-9]/', '', $meta['price']);
        if (($avg > 0 && $cnt > 0) || $price_num !== '') {
            $product = [
                '@context'    => 'https://schema.org',
                '@type'       => 'Product',
                'name'        => $title,
                'url'         => $url,
                'description' => '『' . $title . '』' . ($maker ? '／メーカー：' . $maker : '') . ' のレビュー・サンプル・詳細情報。',
            ];
            if ($image) $product['image'] = $image;
            if ($maker) $product['brand'] = ['@type' => 'Brand', 'name' => $maker];

            if ($avg > 0 && $cnt > 0) {
                $product['aggregateRating'] = [
                    '@type'       => 'AggregateRating',
                    'ratingValue' => number_format($avg, 1),
                    'reviewCount' => $cnt,
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ];
            }
            if ($price_num !== '') {
                $product['offers'] = [
                    '@type'         => 'Offer',
                    'price'         => $price_num,
                    'priceCurrency' => 'JPY',
                    'availability'  => 'https://schema.org/InStock',
                    'url'           => $meta['affiliate_url'] ?: $url,
                    // デジタル配信のため物理配送なし（送料0円）
                    'shippingDetails' => [
                        '@type'               => 'OfferShippingDetails',
                        'shippingRate'        => [
                            '@type'    => 'MonetaryAmount',
                            'value'    => '0',
                            'currency' => 'JPY',
                        ],
                        'shippingDestination' => [
                            '@type'          => 'DefinedRegion',
                            'addressCountry' => 'JP',
                        ],
                        // デジタル配信＝即時ダウンロード（手配0日・配送0日）
                        'deliveryTime' => [
                            '@type'        => 'ShippingDeliveryTime',
                            'handlingTime' => [
                                '@type'    => 'QuantitativeValue',
                                'minValue' => 0,
                                'maxValue' => 0,
                                'unitCode' => 'DAY',
                            ],
                            'transitTime' => [
                                '@type'    => 'QuantitativeValue',
                                'minValue' => 0,
                                'maxValue' => 0,
                                'unitCode' => 'DAY',
                            ],
                        ],
                    ],
                    // デジタルコンテンツのため返品不可
                    'hasMerchantReturnPolicy' => [
                        '@type'                => 'MerchantReturnPolicy',
                        'applicableCountry'    => 'JP',
                        'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
                    ],
                ];
            }
            $this->print_jsonld($product);
        }

        // VideoObject スキーマ（動画フロアの場合）
        if (in_array($service, ['videoa', 'videoc', 'video', 'digital'], true)) {
            $volume    = $meta['volume'];
            $rel_raw   = $meta['release_date'] ?: substr($post->post_date_gmt, 0, 10);
            $upload    = $this->to_iso8601_jst($rel_raw);
            $desc_base = '『' . $title . '』';
            if ($actress) $desc_base .= '主演：' . $actress . '。';
            if ($maker)   $desc_base .= 'メーカー：' . $maker . '。';
            $desc_base .= '詳細レビューページ。';

            $video = [
                '@context'     => 'https://schema.org',
                '@type'        => 'VideoObject',
                'name'         => $title,
                'description'  => $desc_base,
                'url'          => $url,
            ];
            // ISO 8601（JST付き）に変換できた場合のみ出力（無効値を出さない）
            if ($upload) $video['uploadDate'] = $upload;
            if ($image)  $video['thumbnailUrl'] = $image;
            if ($volume) $video['duration']     = 'PT' . preg_replace('/[^0-9]/', '', $volume) . 'M';

            // embedUrl：FANZA公式litevideoプレーヤー（cidから生成）
            $cid = $meta['cid'];
            if ($cid) {
                $aff = fap_opt('aff_id', '');
                $video['embedUrl'] = 'https://www.dmm.co.jp/litevideo/-/part/=/'
                    . ($aff ? 'affi_id=' . rawurlencode($aff) . '/' : '')
                    . 'cid=' . rawurlencode($cid) . '/size=720_480/';
            }

            $this->print_jsonld($video);
        }
    }

    // -----------------------------------------------------------------------
    // Open Graph タグ
    // -----------------------------------------------------------------------
    private function output_og(WP_Post $post): void {
        $meta    = $this->get_meta($post->ID);
        $title   = $meta['title'] ?: get_the_title($post->ID);
        $actress = $meta['actress'];
        $maker   = $meta['maker'];
        $image   = $meta['package_image'];
        $service = $meta['service'];
        $url     = get_permalink($post->ID);

        $og_title = $title . '【レビュー】';
        $og_desc  = '';
        if ($actress) $og_desc .= '主演：' . $actress . '。';
        if ($maker)   $og_desc .= 'メーカー：' . $maker . '。';
        if (!$og_desc) $og_desc = $og_title . 'のレビューページです。';

        $og_type = in_array($service, ['videoa', 'videoc', 'video', 'digital'], true) ? 'video.other' : 'article';

        echo "\n<!-- FAP OGP -->\n";
        $this->og('og:type',        $og_type);
        $this->og('og:title',       $og_title);
        $this->og('og:description', mb_strimwidth($og_desc, 0, 120, '…'));
        $this->og('og:url',         $url);
        $this->og('og:site_name',   get_bloginfo('name'));
        $this->og('og:locale',      'ja_JP');
        if ($image) $this->og('og:image', $image);
    }

    // -----------------------------------------------------------------------
    // Twitter Card タグ
    // -----------------------------------------------------------------------
    private function output_twitter(WP_Post $post): void {
        $card  = fap_opt('seo_twitter_card', 'summary_large_image');
        $meta  = $this->get_meta($post->ID);
        $title = ($meta['title'] ?: get_the_title($post->ID)) . ' レビュー';
        $image = $meta['package_image'];

        echo "\n<!-- FAP Twitter Card -->\n";
        $this->tc('twitter:card',  $card);
        $this->tc('twitter:title', mb_strimwidth($title, 0, 70, '…'));
        if ($image) $this->tc('twitter:image', $image);
    }

    // -----------------------------------------------------------------------
    // パンくず JSON-LD（BreadcrumbList）
    // -----------------------------------------------------------------------
    private function output_breadcrumb(WP_Post $post): void {
        $cats = get_the_category($post->ID);
        if (empty($cats)) return;

        $items = [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'ホーム', 'item' => home_url('/')],
        ];
        $pos = 2;
        foreach ($cats as $cat) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => $cat->name,
                'item'     => get_category_link($cat->term_id),
            ];
        }
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos,
            'name'     => get_post_meta($post->ID, '_fap_title', true) ?: get_the_title($post->ID),
        ];

        $this->print_jsonld([
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ]);
    }

    // -----------------------------------------------------------------------
    // 画像サイトマップ（/fap-image-sitemap.xml）
    // -----------------------------------------------------------------------
    public function register_sitemap_rule(): void {
        if (fap_opt('seo_sitemap', '1') !== '1') return;
        add_rewrite_rule('^fap-image-sitemap\.xml$', 'index.php?fap_image_sitemap=1', 'top');
    }

    public function add_sitemap_var(array $vars): array {
        $vars[] = 'fap_image_sitemap';
        return $vars;
    }

    public function serve_sitemap(): void {
        if ((string) get_query_var('fap_image_sitemap') !== '1') return;

        $post_ids = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 2000,
            'meta_key'       => '_fap_images',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        header('Content-Type: application/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
           . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        foreach ($post_ids as $pid) {
            $images = json_decode(get_post_meta($pid, '_fap_images', true), true);
            if (empty($images) || !is_array($images)) continue;

            $title = esc_html(get_post_meta($pid, '_fap_title', true) ?: get_the_title($pid));
            echo '<url>' . "\n";
            echo '  <loc>' . esc_url(get_permalink($pid)) . '</loc>' . "\n";
            foreach (array_values(array_filter($images)) as $i => $img_url) {
                echo '  <image:image>' . "\n";
                echo '    <image:loc>' . esc_url($img_url) . '</image:loc>' . "\n";
                echo '    <image:title>' . $title . ' 画像' . ($i + 1) . '</image:title>' . "\n";
                echo '  </image:image>' . "\n";
            }
            echo '</url>' . "\n";
        }

        echo '</urlset>';
        exit;
    }

    // -----------------------------------------------------------------------
    // kses：img タグの loading 属性を許可
    // -----------------------------------------------------------------------
    public function allow_attrs(array $tags, string $context): array {
        if (isset($tags['img'])) {
            $tags['img']['loading']  = true;
            $tags['img']['decoding'] = true;
            $tags['img']['fetchpriority'] = true;
        }
        if (isset($tags['video'])) {
            $tags['video']['playsinline'] = true;
            $tags['video']['poster']      = true;
        }
        return $tags;
    }

    // -----------------------------------------------------------------------
    // ヘルパー
    // -----------------------------------------------------------------------
    private function get_meta(int $post_id): array {
        $keys = ['title', 'actress', 'performer', 'maker', 'label', 'director',
                 'series', 'genre', 'volume', 'release_date', 'price',
                 'affiliate_url', 'package_image', 'review_avg', 'review_cnt',
                 'service', 'cid', 'has_movie', 'movie_url'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = (string) get_post_meta($post_id, '_fap_' . $k, true);
        }
        return $out;
    }

    // 日付文字列を ISO 8601（JST +09:00）に変換。変換不能なら '' を返す。
    private function to_iso8601_jst(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') return '';
        // 「YYYY-MM-DD HH:MM:SS」形式
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $raw, $m)) {
            return $m[1] . 'T' . $m[2] . '+09:00';
        }
        // 「YYYY-MM-DD」形式（日付のみ）
        if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $raw, $m)) {
            return $m[1] . 'T00:00:00+09:00';
        }
        return '';
    }

    private function print_jsonld(array $data): void {
        echo "\n<script type=\"application/ld+json\">\n";
        echo wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        echo "\n</script>\n";
    }

    private function og(string $property, string $content): void {
        printf('<meta property="%s" content="%s" />' . "\n", esc_attr($property), esc_attr($content));
    }

    private function tc(string $name, string $content): void {
        printf('<meta name="%s" content="%s" />' . "\n", esc_attr($name), esc_attr($content));
    }
}
