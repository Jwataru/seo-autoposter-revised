<?php
defined('ABSPATH') || exit;

class FAP_Updater {

    private const API_URL    = 'https://autoposter.jp/wp-json/fap-license/v1/plugin-info';
    private const PLUGIN_KEY = 'fanza-seo-autoposter/fanza-seo-autoposter.php';
    private const CACHE_KEY  = 'fap_update_cache';

    public static function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject_update']);
        add_filter('plugins_api', [self::class, 'plugin_info'], 10, 3);
    }

    private static function fetch(): ?object {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached) return $cached;

        $res = wp_remote_get(self::API_URL, ['timeout' => 10]);
        if (is_wp_error($res)) return null;

        $data = json_decode(wp_remote_retrieve_body($res));
        if (!$data || empty($data->version)) return null;

        set_transient(self::CACHE_KEY, $data, 12 * HOUR_IN_SECONDS);
        return $data;
    }

    public static function inject_update($transient) {
        if (empty($transient->checked)) return $transient;

        $remote  = self::fetch();
        if (!$remote) return $transient;

        $current = $transient->checked[self::PLUGIN_KEY] ?? '';
        if (version_compare($current, $remote->version, '<')) {
            $transient->response[self::PLUGIN_KEY] = (object) [
                'slug'        => FAP_SLUG,
                'plugin'      => self::PLUGIN_KEY,
                'new_version' => $remote->version,
                'url'         => 'https://autoposter.jp/download.html',
                'package'     => $remote->download_url,
            ];
        }

        return $transient;
    }

    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== FAP_SLUG) {
            return $result;
        }

        $remote = self::fetch();
        if (!$remote) return $result;

        return (object) [
            'name'          => 'FANZA SEO Autoposter',
            'slug'          => FAP_SLUG,
            'version'       => $remote->version,
            'requires'      => $remote->requires     ?? '5.8',
            'tested'        => $remote->tested        ?? '6.5',
            'requires_php'  => $remote->requires_php  ?? '7.4',
            'download_link' => $remote->download_url,
            'last_updated'  => $remote->last_updated  ?? '',
            'sections'      => ['changelog' => $remote->changelog ?? ''],
        ];
    }
}
