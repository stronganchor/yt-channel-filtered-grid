<?php
/**
 * Plugin Name: YouTube Channel Filtered Grid
 * Description: Shortcode to show a grid of videos from a YouTube channel filtered by title keywords.
 * Version: 1.0.6
 * Author: Strong Anchor Tech
 */

if (!defined('ABSPATH')) exit;

class YTCFG_Plugin {
    const OPT_API_KEY         = 'ytcfg_api_key';
    const OPT_DEFAULT_CHANNEL = 'ytcfg_default_channel_id';

    const LS_OPT_API_KEY         = 'livestream_embedder_api_key';
    const LS_OPT_DEFAULT_CHANNEL = 'livestream_embedder_default_channel';

    const MIRROR_LS_SETTINGS_IF_OURS_EMPTY = true;

    // Bump this when logic changes so cached transients naturally invalidate.
    const VERSION = '1.0.6';

    // Admin auto-refresh throttle: even admins will use cached results if cache age < this.
    // (Implemented by forcing a minimum cache_minutes of 2 for admins.)
    const ADMIN_MIN_CACHE_MINUTES = 2;

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'admin_init']);
        add_shortcode('yt_channel_grid', [__CLASS__, 'shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets() {
        $css = "
        .ytcfg-grid{display:grid;gap:16px}
        .ytcfg-card{display:block;text-decoration:none;color:inherit;cursor:pointer}
        .ytcfg-thumbwrap{position:relative;border-radius:10px;overflow:hidden}
        .ytcfg-thumb{width:100%;aspect-ratio:16/9;object-fit:cover;display:block}
        .ytcfg-play{
            position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
            background:linear-gradient(to bottom, rgba(0,0,0,0.05), rgba(0,0,0,0.35));
        }
        .ytcfg-playbtn{
            width:62px;height:44px;border-radius:12px;
            background:rgba(0,0,0,0.65);
            display:flex;align-items:center;justify-content:center;
            box-shadow:0 10px 30px rgba(0,0,0,0.25);
        }
        .ytcfg-playbtn:before{
            content:'';display:block;margin-left:4px;
            width:0;height:0;border-top:10px solid transparent;border-bottom:10px solid transparent;border-left:16px solid #fff;
        }
        .ytcfg-title{margin:8px 0 0 0;font-size:14px;line-height:1.25}
        .ytcfg-empty,.ytcfg-error{padding:12px 0}
        .ytcfg-iframewrap{position:relative;width:100%;aspect-ratio:16/9;border-radius:10px;overflow:hidden}
        .ytcfg-iframewrap iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
        ";

        wp_register_style('ytcfg-inline', false);
        wp_enqueue_style('ytcfg-inline');
        wp_add_inline_style('ytcfg-inline', $css);

        wp_register_script('ytcfg-inline-js', '', [], self::VERSION, true);
        wp_enqueue_script('ytcfg-inline-js');

        $js = <<<JS
(function(){
    function closest(el, sel){
        while (el && el.nodeType === 1) {
            if (el.matches(sel)) return el;
            el = el.parentElement;
        }
        return null;
    }

    document.addEventListener('click', function(e){
        var card = closest(e.target, '.ytcfg-card');
        if (!card) return;

        if (card.getAttribute('data-ytcfg-playing') === '1') return;

        var vid = card.getAttribute('data-video-id');
        if (!vid) return;

        e.preventDefault();

        card.setAttribute('data-ytcfg-playing', '1');
        var titleEl = card.querySelector('.ytcfg-title');
        var titleHtml = titleEl ? titleEl.outerHTML : '';

        var iframeSrc = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(vid) + '?autoplay=1&rel=0';
        card.innerHTML =
            '<div class="ytcfg-iframewrap">' +
                '<iframe loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen src="' + iframeSrc + '"></iframe>' +
            '</div>' +
            titleHtml;
    }, {passive:false});
})();
JS;

        wp_add_inline_script('ytcfg-inline-js', $js);
    }

    public static function admin_menu() {
        add_options_page(
            'YouTube Filtered Grid',
            'YouTube Filtered Grid',
            'manage_options',
            'ytcfg',
            [__CLASS__, 'admin_page']
        );
    }

    public static function admin_init() {
        register_setting('ytcfg', self::OPT_API_KEY, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
        register_setting('ytcfg', self::OPT_DEFAULT_CHANNEL, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) return;

        $effective = self::get_effective_settings();
        ?>
        <div class="wrap">
            <h1>YouTube Channel Filtered Grid</h1>

            <h2>Effective Settings</h2>
            <p>
                <strong>API key source:</strong> <?php echo esc_html($effective['api_key_source']); ?><br/>
                <strong>Default channel source:</strong> <?php echo esc_html($effective['channel_source']); ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('ytcfg'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_API_KEY); ?>">YouTube Data API Key</label></th>
                        <td>
                            <input type="text" class="regular-text"
                                   id="<?php echo esc_attr(self::OPT_API_KEY); ?>"
                                   name="<?php echo esc_attr(self::OPT_API_KEY); ?>"
                                   value="<?php echo esc_attr(get_option(self::OPT_API_KEY, '')); ?>" />
                            <p class="description">If empty, falls back to: <code><?php echo esc_html(self::LS_OPT_API_KEY); ?></code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_DEFAULT_CHANNEL); ?>">Default Channel ID</label></th>
                        <td>
                            <input type="text" class="regular-text"
                                   id="<?php echo esc_attr(self::OPT_DEFAULT_CHANNEL); ?>"
                                   name="<?php echo esc_attr(self::OPT_DEFAULT_CHANNEL); ?>"
                                   value="<?php echo esc_attr(get_option(self::OPT_DEFAULT_CHANNEL, '')); ?>" />
                            <p class="description">If empty, falls back to: <code><?php echo esc_html(self::LS_OPT_DEFAULT_CHANNEL); ?></code></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Shortcode</h2>
            <p><code>[yt_channel_grid include="listening to god|listening to god's" include_mode="or" match="any" order="oldest" max="24" cols="3"]</code></p>
        </div>
        <?php
    }

    private static function get_effective_settings() {
        $our_api_key = trim((string) get_option(self::OPT_API_KEY, ''));
        $our_channel = trim((string) get_option(self::OPT_DEFAULT_CHANNEL, ''));

        $ls_api_key = trim((string) get_option(self::LS_OPT_API_KEY, ''));
        $ls_channel = trim((string) get_option(self::LS_OPT_DEFAULT_CHANNEL, ''));

        $api_key = $our_api_key !== '' ? $our_api_key : $ls_api_key;
        $channel = $our_channel !== '' ? $our_channel : $ls_channel;

        $api_key_source = ($our_api_key !== '') ? 'ytcfg_api_key (this plugin)' : (($ls_api_key !== '') ? self::LS_OPT_API_KEY . ' (Livestream Embedder)' : 'none');
        $channel_source = ($our_channel !== '') ? 'ytcfg_default_channel_id (this plugin)' : (($ls_channel !== '') ? self::LS_OPT_DEFAULT_CHANNEL . ' (Livestream Embedder)' : 'none');

        if (self::MIRROR_LS_SETTINGS_IF_OURS_EMPTY) {
            if ($our_api_key === '' && $ls_api_key !== '') update_option(self::OPT_API_KEY, $ls_api_key, false);
            if ($our_channel === '' && $ls_channel !== '') update_option(self::OPT_DEFAULT_CHANNEL, $ls_channel, false);
        }

        return [
            'api_key' => $api_key,
            'channel' => $channel,
            'api_key_source' => $api_key_source,
            'channel_source' => $channel_source,
        ];
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts([
            'channel_id'     => '',
            'include'        => '',
            'include_mode'   => 'auto',    // auto|phrase|or|words
            'exclude'        => '',
            'match'          => 'any',     // any|all
            'order'          => 'oldest',  // oldest|newest
            'max'            => '24',
            'cols'           => '4',
            'cache_minutes'  => '120',
            'scan_limit'     => '2000',
        ], $atts, 'yt_channel_grid');

        $effective = self::get_effective_settings();
        $api_key = $effective['api_key'];
        if ($api_key === '') return self::err('Missing API key.');

        $channel_id = trim((string) $atts['channel_id']);
        if ($channel_id === '') $channel_id = $effective['channel'];
        if ($channel_id === '') return self::err('Missing channel_id.');

        $max = max(1, min(200, intval($atts['max'])));
        $cols = max(1, min(8, intval($atts['cols'])));
        $cache_minutes = max(0, min(10080, intval($atts['cache_minutes'])));
        $scan_limit = max(50, min(50000, intval($atts['scan_limit'])));

        $order = strtolower(trim((string)$atts['order']));
        $order = ($order === 'newest') ? 'newest' : 'oldest';

        $match = strtolower(trim((string) $atts['match'])) === 'all' ? 'all' : 'any';

        $include_terms = self::parse_include_terms($atts['include'], $atts['include_mode']);
        $exclude_terms = self::split_terms_or($atts['exclude']);

        // Admin refresh behavior:
        // - Admins *do not* bypass caching completely.
        // - Instead, enforce a small minimum cache window so rapid refreshes/page hopping won't hammer the API.
        $is_admin_view = (is_user_logged_in() && current_user_can('manage_options'));
        if ($is_admin_view && $cache_minutes < self::ADMIN_MIN_CACHE_MINUTES) {
            $cache_minutes = self::ADMIN_MIN_CACHE_MINUTES;
        }

        $cache_key = 'ytcfg_' . md5(wp_json_encode([
            'v' => self::VERSION,
            'channel_id' => $channel_id,
            'include' => $include_terms,
            'exclude' => $exclude_terms,
            'match' => $match,
            'order' => $order,
            'max' => $max,
            'scan_limit' => $scan_limit,
        ]));

        // Cache read logic:
        // - Visitors: use cache if available.
        // - Admins: use cache if available, but because admins often want "fresh",
        //   set cache_minutes small on those pages (e.g., 2) so it refreshes quickly.
        if ($cache_minutes > 0) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) return self::render_grid($cached, $cols);
        }

        $uploads_playlist_id = self::get_uploads_playlist_id($api_key, $channel_id);
        if (is_wp_error($uploads_playlist_id)) return self::err($uploads_playlist_id->get_error_message());

        $videos = self::fetch_and_filter_playlist_videos(
            $api_key,
            $uploads_playlist_id,
            $include_terms,
            $exclude_terms,
            $match,
            $order,
            $max,
            $scan_limit
        );

        if (is_wp_error($videos)) return self::err($videos->get_error_message());

        if ($cache_minutes > 0) {
            set_transient($cache_key, $videos, $cache_minutes * MINUTE_IN_SECONDS);
        }

        return self::render_grid($videos, $cols);
    }

    private static function normalize_text($s) {
        $s = (string)$s;

        // Decode entities just in case (titles can include &amp; etc.)
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize apostrophes/quotes to straight variants
        $search = ["’","‘","‛","`","´","“","”","„","‟"];
        $replace = ["'","'","'","'","'","\"","\"","\"","\""];
        $s = str_replace($search, $replace, $s);

        $s = trim($s);
        if ($s === '') return '';

        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    private static function split_terms_or($raw) {
        $raw = (string) $raw;
        $parts = array_filter(array_map('trim', explode('|', $raw)), function($v){ return $v !== ''; });
        $out = [];
        foreach ($parts as $p) {
            $n = self::normalize_text($p);
            if ($n !== '') $out[] = $n;
        }
        return $out;
    }

    private static function split_terms_words($raw) {
        $raw = (string) $raw;
        $parts = preg_split('/\s+/u', trim($raw));
        $out = [];
        foreach ($parts as $p) {
            $n = self::normalize_text($p);
            if ($n !== '') $out[] = $n;
        }
        return $out;
    }

    private static function parse_include_terms($raw, $mode) {
        $raw = trim((string) $raw);
        if ($raw === '') return [];

        $mode = strtolower(trim((string)$mode));
        if ($mode === 'phrase') {
            // Exactly one phrase (no | splitting)
            return [ self::normalize_text($raw) ];
        }

        if ($mode === 'or') {
            // Split by | into phrases
            return self::split_terms_or($raw);
        }

        if ($mode === 'words') {
            return self::split_terms_words($raw);
        }

        // auto:
        // If contains | => treat as OR phrases, else split into words
        if (strpos($raw, '|') !== false) return self::split_terms_or($raw);
        return self::split_terms_words($raw);
    }

    private static function title_contains_all($title_lc, $terms) {
        foreach ($terms as $t) {
            if ($t === '') continue;
            if (function_exists('mb_strpos')) {
                if (mb_strpos($title_lc, $t, 0, 'UTF-8') === false) return false;
            } else {
                if (strpos($title_lc, $t) === false) return false;
            }
        }
        return true;
    }

    private static function title_contains_any($title_lc, $terms) {
        foreach ($terms as $t) {
            if ($t === '') continue;
            if (function_exists('mb_strpos')) {
                if (mb_strpos($title_lc, $t, 0, 'UTF-8') !== false) return true;
            } else {
                if (strpos($title_lc, $t) !== false) return true;
            }
        }
        return false;
    }

    private static function get_uploads_playlist_id($api_key, $channel_id) {
        $url = add_query_arg([
            'part' => 'contentDetails',
            'id'   => $channel_id,
            'key'  => $api_key,
        ], 'https://www.googleapis.com/youtube/v3/channels');

        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);

        if ($code !== 200 || !is_array($json)) {
            return new WP_Error('ytcfg_channels_failed', 'YouTube API error (channels).');
        }

        $items = $json['items'] ?? [];
        if (!isset($items[0]['contentDetails']['relatedPlaylists']['uploads'])) {
            return new WP_Error('ytcfg_no_uploads', 'Could not find uploads playlist for this channel ID.');
        }

        return $items[0]['contentDetails']['relatedPlaylists']['uploads'];
    }

    /**
     * Duplicate handling for "mobile" re-uploads:
     * If two videos have the same title except one includes the 📱 emoji,
     * keep the non-📱 title version and drop the 📱 version.
     */
    private static function has_mobile_marker($title) {
        return (strpos((string)$title, "📱") !== false);
    }

    private static function dupe_key_for_title($title) {
        // Lowercase + entity decode + normalized quotes
        $t = self::normalize_text((string)$title);

        // Remove the phone emoji (and common variation selector if present)
        // Note: FE0F is a variation selector that can appear with some emoji sequences.
        $t = str_replace(["📱", "\u{FE0F}"], '', $t);

        // Collapse whitespace
        $t = preg_replace('/\s+/u', ' ', trim($t));

        return $t;
    }

    private static function fetch_and_filter_playlist_videos($api_key, $playlist_id, $include_terms, $exclude_terms, $match, $order, $max, $scan_limit) {
        $picked_by_key = [];   // key => ['video' => [...], 'is_mobile' => bool]
        $key_order = [];       // preserves first-seen order of unique titles
        $mobile_only_count = 0;

        $page_token = '';
        $scanned = 0;

        while (true) {
            $args = [
                'part'       => 'snippet',
                'playlistId' => $playlist_id,
                'maxResults' => 50,
                'key'        => $api_key,
            ];
            if ($page_token !== '') $args['pageToken'] = $page_token;

            $url = add_query_arg($args, 'https://www.googleapis.com/youtube/v3/playlistItems');
            $res = wp_remote_get($url, ['timeout' => 15]);
            if (is_wp_error($res)) return $res;

            $code = wp_remote_retrieve_response_code($res);
            $body = wp_remote_retrieve_body($res);
            $json = json_decode($body, true);

            if ($code !== 200 || !is_array($json)) {
                return new WP_Error('ytcfg_playlist_failed', 'YouTube API error (playlistItems).');
            }

            $items = $json['items'] ?? [];
            if (empty($items)) break;

            foreach ($items as $it) {
                $scanned++;
                if ($scanned > $scan_limit) break 2;

                $sn = $it['snippet'] ?? null;
                if (!is_array($sn)) continue;

                $title = (string) ($sn['title'] ?? '');
                $video_id = (string) ($sn['resourceId']['videoId'] ?? '');
                if ($video_id === '' || $title === '') continue;

                $title_lc = self::normalize_text($title);

                if (!empty($exclude_terms) && self::title_contains_any($title_lc, $exclude_terms)) continue;

                if (!empty($include_terms)) {
                    if ($match === 'all') {
                        if (!self::title_contains_all($title_lc, $include_terms)) continue;
                    } else {
                        if (!self::title_contains_any($title_lc, $include_terms)) continue;
                    }
                }

                $thumb = $sn['thumbnails']['high']['url'] ?? ($sn['thumbnails']['medium']['url'] ?? '');
                $video = [
                    'video_id' => $video_id,
                    'title'    => $title,
                    'thumb'    => (string) $thumb,
                ];

                $is_mobile = self::has_mobile_marker($title);
                $key = self::dupe_key_for_title($title);
                if ($key === '') continue;

                if (!isset($picked_by_key[$key])) {
                    $picked_by_key[$key] = [
                        'video' => $video,
                        'is_mobile' => $is_mobile,
                    ];
                    $key_order[] = $key;
                    if ($is_mobile) $mobile_only_count++;
                } else {
                    // If we already have a mobile version and we now see a non-mobile version, prefer non-mobile.
                    if ($picked_by_key[$key]['is_mobile'] && !$is_mobile) {
                        $picked_by_key[$key]['video'] = $video;
                        $picked_by_key[$key]['is_mobile'] = false;
                        $mobile_only_count = max(0, $mobile_only_count - 1);
                    }
                    // Otherwise keep the first-seen entry (newer in playlist order).
                }

                $unique_count = count($key_order);

                // For newest: stop once we have enough unique videos AND none are mobile-only placeholders.
                if ($order === 'newest') {
                    if ($unique_count >= $max && $mobile_only_count === 0) break 2;
                }
            }

            $page_token = (string) ($json['nextPageToken'] ?? '');
            if ($page_token === '') break;
        }

        // Rebuild ordered list
        $videos = [];
        foreach ($key_order as $k) {
            if (isset($picked_by_key[$k]['video'])) $videos[] = $picked_by_key[$k]['video'];
        }

        if ($order === 'oldest') {
            $videos = array_reverse($videos);
        }

        if (count($videos) > $max) {
            $videos = array_slice($videos, 0, $max);
        }

        return $videos;
    }

    private static function render_grid($videos, $cols) {
        if (empty($videos)) return '<div class="ytcfg-empty">No videos matched your filter.</div>';

        $style = 'style="grid-template-columns:repeat(' . intval($cols) . ',minmax(0,1fr))"';

        $out = '<div class="ytcfg-grid" ' . $style . '>';
        foreach ($videos as $v) {
            $vid   = esc_attr($v['video_id']);
            $title = esc_html($v['title']);
            $thumb = esc_url($v['thumb']);

            $out .= '<div class="ytcfg-card" role="button" tabindex="0" data-video-id="' . $vid . '">';
            $out .=   '<div class="ytcfg-thumbwrap">';
            if ($thumb !== '') $out .= '<img class="ytcfg-thumb" src="' . $thumb . '" alt="' . $title . '"/>';
            $out .=     '<div class="ytcfg-play"><div class="ytcfg-playbtn" aria-hidden="true"></div></div>';
            $out .=   '</div>';
            $out .=   '<div class="ytcfg-title">' . $title . '</div>';
            $out .= '</div>';
        }
        $out .= '</div>';

        return $out;
    }

    private static function err($msg) {
        return '<div class="ytcfg-error">' . esc_html($msg) . '</div>';
    }
}

YTCFG_Plugin::init();
