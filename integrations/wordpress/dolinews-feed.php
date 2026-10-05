<?php
/**
 * Plugin Name:       DoliNews Feed
 * Description:       Shows the DoliNews announcement feed, filtered by editor or by project, through the [dolinews] shortcode.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       dolinews-feed
 *
 * One file, no dependency: no Composer, no third-party plugin, no
 * JavaScript, no build step. Drop it in wp-content/plugins/ and
 * activate it, or paste it at the end of a theme's functions.php -
 * both work, and the second needs no plugin at all.
 *
 * It reads the public JSON feed, which needs no account and no token:
 * reading DoliNews is free and accountless, and so are its feeds.
 * Announcements are published under CC BY-SA 4.0, which is why every
 * rendered block carries the editor's name, a link to the announcement
 * and the licence it travels under - remove them and the copy stops
 * being allowed.
 *
 * Three rules of the service are respected here, and are the reason
 * this file exists instead of a generic RSS widget:
 *
 *  - only stable announcements are listed unless another maturity is
 *    named: a production site has no use for a feed of test versions;
 *  - a maturity badge is never shown without how long ago it was
 *    announced: nobody ever comes back to say a version left beta;
 *  - nothing here says a module "is compatible" with a Dolibarr
 *    version. The feed says what was announced, and when.
 *
 * The wording of those badges comes from the feed itself, already
 * written in the requested language, so this file translates nothing
 * and stays correct in the ten languages the service speaks.
 */

if (! defined('ABSPATH')) {
    exit;
}

// Load this file once: as a plugin, or pasted into a theme, never
// both. A guard here would not help - PHP declares the functions below
// while compiling the file, before a single line of it runs, so a
// second load fails on the redeclaration whatever this file says.

if (! defined('DOLINEWS_FEED_BASE')) {
    /**
     * Where the feed is read. Override it in wp-config.php to point at
     * another instance of the service.
     */
    define('DOLINEWS_FEED_BASE', 'https://dolinews.com');
}

/**
 * [dolinews] - the announcement feed, filtered.
 *
 * Attributes, all optional:
 *
 *   editor    editor slug, as it appears in /editeurs/<slug>
 *   project   project slug, as it appears in /projets/<slug>
 *   focus     security, bugfix_major, feature_minor, compat, eol...
 *   dolibarr  major version the announcements concern (22)
 *   maturity  comma-separated: beta,rc - stable only by default
 *   locale    content locale of the reading (fr_FR, es_ES...)
 *   q         free-text search
 *   limit     how many announcements, 1 to 50 (default 5)
 *   layout    list or cards (default list)
 *   summary   yes or no, show the summary (default yes)
 *   cache     seconds the answer is kept locally (default 900)
 *   base      base address of the service, overrides DOLINEWS_FEED_BASE
 *   title     heading shown above the block, none by default
 *   link_text wording of the link into an announcement
 *   empty_text what to say when the filter matches nothing
 *
 * @param array<string, string>|string $atts
 *
 * @return string rendered HTML, empty on failure
 */
function dolinews_feed_shortcode($atts)
{
    $atts = shortcode_atts(
        array(
            'editor' => '',
            'project' => '',
            'focus' => '',
            'dolibarr' => '',
            'maturity' => '',
            'locale' => '',
            'q' => '',
            'limit' => '5',
            'layout' => 'list',
            'summary' => 'yes',
            'cache' => '900',
            'base' => DOLINEWS_FEED_BASE,
            'title' => '',
            // Left empty on purpose: the wording comes from the feed, in
            // the language it was asked in. Set them to override.
            'link_text' => '',
            'empty_text' => '',
        ),
        is_array($atts) ? $atts : array(),
        'dolinews'
    );

    $feed = dolinews_feed_fetch($atts);

    if ($feed === null) {
        // Nothing is printed when the service could not be reached:
        // a page of a third-party site is not the place to display
        // our outage. The reason is in the error log.
        return '';
    }

    return dolinews_feed_render($feed, $atts);
}

add_shortcode('dolinews', 'dolinews_feed_shortcode');

/**
 * The feed as the service answered it, from cache when possible.
 *
 * Two transients per filter combination, which is what makes a slow or
 * unreachable service harmless: the short one is the cache, the long
 * one keeps the last good answer for a week. A site that shows last
 * hour's announcements is right; a site that shows an empty box
 * because of a timeout looks broken.
 *
 * @param array<string, string> $atts
 *
 * @return array<string, mixed>|null decoded feed, null when nothing can be served
 */
function dolinews_feed_fetch($atts)
{
    $url = dolinews_feed_url($atts);
    $key = 'dolinews_' . md5($url);
    $stale_key = 'dolinews_stale_' . md5($url);

    $cached = get_transient($key);

    if (is_array($cached)) {
        return $cached;
    }

    $response = wp_remote_get(
        $url,
        array(
            'timeout' => 5,
            'redirection' => 2,
            'headers' => array('Accept' => 'application/json'),
            'user-agent' => 'DoliNews-WordPress/1.0; ' . home_url('/'),
        )
    );

    if (is_wp_error($response)) {
        error_log(sprintf(
            '[dolinews-feed] request to %s failed: %s',
            $url,
            $response->get_error_message()
        ));

        return dolinews_feed_stale($stale_key);
    }

    $status = (int) wp_remote_retrieve_response_code($response);

    if ($status !== 200) {
        error_log(sprintf('[dolinews-feed] %s answered HTTP %d', $url, $status));

        return dolinews_feed_stale($stale_key);
    }

    $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

    if (! is_array($decoded) || ! isset($decoded['items']) || ! is_array($decoded['items'])) {
        error_log(sprintf('[dolinews-feed] %s answered a body that is not a JSON feed', $url));

        return dolinews_feed_stale($stale_key);
    }

    $ttl = max(60, (int) $atts['cache']);

    set_transient($key, $decoded, $ttl);
    set_transient($stale_key, $decoded, WEEK_IN_SECONDS);

    return $decoded;
}

/**
 * The last good answer, when the current one could not be had.
 *
 * @param string $stale_key
 *
 * @return array<string, mixed>|null
 */
function dolinews_feed_stale($stale_key)
{
    $stale = get_transient($stale_key);

    if (is_array($stale)) {
        error_log('[dolinews-feed] serving the last known answer instead');

        return $stale;
    }

    return null;
}

/**
 * The feed address carrying the filters of the shortcode.
 *
 * Only named maturities travel: left out, the service lists stable
 * announcements alone, which is its default and the right one for a
 * site in production. There is deliberately no "everything" value.
 *
 * @param array<string, string> $atts
 *
 * @return string
 */
function dolinews_feed_url($atts)
{
    $query = array();

    foreach (array('editor', 'project', 'focus', 'dolibarr', 'locale', 'q') as $name) {
        $value = trim((string) $atts[$name]);

        if ($value !== '') {
            $query[$name] = $value;
        }
    }

    $maturities = array_filter(array_map('trim', explode(',', (string) $atts['maturity'])));

    if (! empty($maturities)) {
        $query['maturity'] = array_values($maturities);
    }

    $url = rtrim((string) $atts['base'], '/') . '/feeds.json';

    if (empty($query)) {
        return $url;
    }

    return $url . '?' . http_build_query($query);
}

/**
 * The block, as HTML.
 *
 * Everything printed is escaped, and no image travels: an illustration
 * of an announcement belongs to its editor and is hosted by the
 * service, which is also where the licence of the text is stated.
 *
 * @param array<string, mixed>  $feed
 * @param array<string, string> $atts
 *
 * @return string
 */
function dolinews_feed_render($feed, $atts)
{
    $limit = min(50, max(1, (int) $atts['limit']));
    $items = array_slice(is_array($feed['items']) ? $feed['items'] : array(), 0, $limit);
    $cards = $atts['layout'] === 'cards';
    $with_summary = $atts['summary'] !== 'no';

    // Whatever the shortcode did not spell out is taken from the feed,
    // which answered in the language that was asked for. The English
    // fallbacks below are only reached by a service too old to carry
    // them.
    $atts['link_text'] = dolinews_feed_label(
        $atts['link_text'],
        $feed,
        'read_more',
        __('Read the announcement', 'dolinews-feed')
    );
    $atts['empty_text'] = dolinews_feed_label(
        $atts['empty_text'],
        $feed,
        'empty',
        __('No announcement for now.', 'dolinews-feed')
    );

    $html = dolinews_feed_style();
    $html .= '<div class="dolinews-feed' . ($cards ? ' dolinews-feed--cards' : '') . '">';

    if (trim((string) $atts['title']) !== '') {
        $html .= '<h2 class="dolinews-feed__title">' . esc_html($atts['title']) . '</h2>';
    }

    if (empty($items)) {
        $html .= '<p class="dolinews-feed__empty">' . esc_html($atts['empty_text']) . '</p>';
        $html .= '</div>';

        return $html;
    }

    $html .= '<ul class="dolinews-feed__list">';

    foreach ($items as $item) {
        $html .= dolinews_feed_item($item, $atts, $with_summary);
    }

    $html .= '</ul>';
    $html .= dolinews_feed_credit($feed, $atts);
    $html .= '</div>';

    return $html;
}

/**
 * One wording: what the shortcode said, else what the feed says, else
 * the English fallback of this file.
 *
 * @param string               $chosen   value given to the shortcode
 * @param array<string, mixed> $feed     decoded feed
 * @param string               $name     key under _dolinews.labels
 * @param string               $fallback English wording of last resort
 *
 * @return string
 */
function dolinews_feed_label($chosen, $feed, $name, $fallback)
{
    if (trim((string) $chosen) !== '') {
        return (string) $chosen;
    }

    if (isset($feed['_dolinews']['labels'][$name]) && $feed['_dolinews']['labels'][$name] !== '') {
        return (string) $feed['_dolinews']['labels'][$name];
    }

    return $fallback;
}

/**
 * One announcement.
 *
 * @param array<string, mixed>  $item
 * @param array<string, string> $atts
 * @param bool                  $with_summary
 *
 * @return string
 */
function dolinews_feed_item($item, $atts, $with_summary)
{
    $extra = isset($item['_dolinews']) && is_array($item['_dolinews']) ? $item['_dolinews'] : array();
    $labels = isset($extra['labels']) && is_array($extra['labels']) ? $extra['labels'] : array();
    $url = isset($item['url']) ? (string) $item['url'] : '';
    $title = isset($item['title']) ? (string) $item['title'] : '';

    $html = '<li class="dolinews-feed__item">';

    $html .= '<h3 class="dolinews-feed__item-title">';
    $html .= '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>';
    $html .= '</h3>';

    $html .= dolinews_feed_meta($item, $extra, $labels);

    if ($with_summary && isset($item['content_text'])) {
        $html .= '<p class="dolinews-feed__summary">' . esc_html((string) $item['content_text']) . '</p>';
    }

    $html .= '<p class="dolinews-feed__more">';
    $html .= '<a href="' . esc_url($url) . '">' . esc_html($atts['link_text']) . '</a>';
    $html .= '</p>';

    $html .= '</li>';

    return $html;
}

/**
 * The meta line: date, editor, version, and the badges the service
 * requires - a maturity always with its age, the Dolibarr range as the
 * feed already worded it, the language when the announcement is not in
 * the one being read.
 *
 * A security focus is the one badge given a class of its own: it is
 * the announcement an integrator must not miss.
 *
 * @param array<string, mixed> $item
 * @param array<string, mixed> $extra
 * @param array<string, mixed> $labels
 *
 * @return string
 */
function dolinews_feed_meta($item, $extra, $labels)
{
    $html = '<p class="dolinews-feed__meta">';

    if (isset($item['date_published'])) {
        $stamp = strtotime((string) $item['date_published']);

        if ($stamp !== false) {
            $html .= '<time class="dolinews-feed__date" datetime="' . esc_attr(gmdate('c', $stamp)) . '">';
            $html .= esc_html(date_i18n(get_option('date_format'), $stamp));
            $html .= '</time>';
        }
    }

    if (isset($extra['editor']['name'])) {
        $html .= '<span class="dolinews-feed__editor">' . esc_html((string) $extra['editor']['name']) . '</span>';
    }

    if (isset($extra['version']) && $extra['version'] !== null && $extra['version'] !== '') {
        $html .= '<span class="dolinews-feed__version">' . esc_html((string) $extra['version']) . '</span>';
    }

    if (isset($labels['dolibarr']) && $labels['dolibarr'] !== null) {
        $html .= '<span class="dolinews-feed__badge">' . esc_html((string) $labels['dolibarr']) . '</span>';
    }

    // The maturity and its age form one badge: the spec forbids showing
    // the first without the second, so they are never split here.
    if (isset($labels['maturity']) && isset($extra['maturity']) && $extra['maturity'] !== 'stable') {
        $maturity = (string) $labels['maturity'];

        if (isset($labels['announced_age']) && $labels['announced_age'] !== null) {
            $maturity .= ' - ' . (string) $labels['announced_age'];
        }

        $html .= '<span class="dolinews-feed__badge dolinews-feed__badge--maturity">' . esc_html($maturity) . '</span>';
    }

    if (isset($labels['focus']) && $labels['focus'] !== null) {
        $class = 'dolinews-feed__badge';

        if (isset($extra['focus']) && $extra['focus'] === 'security') {
            $class .= ' dolinews-feed__badge--security';
        }

        $html .= '<span class="' . esc_attr($class) . '">' . esc_html((string) $labels['focus']) . '</span>';
    }

    if (isset($labels['language']) && $labels['language'] !== null) {
        $html .= '<span class="dolinews-feed__badge">' . esc_html((string) $labels['language']) . '</span>';
    }

    $html .= '</p>';

    return $html;
}

/**
 * Attribution and licence, which are not decoration: the announcements
 * are published under CC BY-SA 4.0, and a copy that names neither its
 * source nor its licence is not covered by it. The feed states both.
 *
 * @param array<string, mixed>  $feed
 * @param array<string, string> $atts
 *
 * @return string
 */
function dolinews_feed_credit($feed, $atts)
{
    $home = isset($feed['home_page_url']) ? (string) $feed['home_page_url'] : (string) $atts['base'];
    $licence_name = isset($feed['_license']['name']) ? (string) $feed['_license']['name'] : '';
    $licence_url = isset($feed['_license']['url']) ? (string) $feed['_license']['url'] : '';

    $html = '<p class="dolinews-feed__credit">';
    $html .= '<a href="' . esc_url($home) . '">' . esc_html__('Announcements from DoliNews', 'dolinews-feed') . '</a>';

    if ($licence_name !== '' && $licence_url !== '') {
        $html .= ' - <a href="' . esc_url($licence_url) . '" rel="license">' . esc_html($licence_name) . '</a>';
    }

    $html .= '</p>';

    return $html;
}

/**
 * The stylesheet, printed once per page and inline on purpose: a single
 * file has no asset to enqueue, and a theme overrides any of these
 * rules by declaring the same class.
 *
 * No colour is set beyond the security badge: the block inherits the
 * typography and the palette of the site it sits in, which is what
 * makes it look like a section of that site rather than a widget.
 *
 * @return string
 */
function dolinews_feed_style()
{
    static $printed = false;

    if ($printed) {
        return '';
    }

    $printed = true;

    return '<style>'
        . '.dolinews-feed__list{list-style:none;margin:0;padding:0}'
        . '.dolinews-feed__item{padding:0 0 1.25em;margin:0 0 1.25em;border-bottom:1px solid rgba(0,0,0,.1)}'
        . '.dolinews-feed__item:last-child{border-bottom:0;margin-bottom:0}'
        . '.dolinews-feed__item-title{margin:0 0 .35em;font-size:1.05em}'
        . '.dolinews-feed__meta{margin:0 0 .5em;font-size:.85em;opacity:.8;display:flex;flex-wrap:wrap;gap:.5em;align-items:center}'
        . '.dolinews-feed__summary{margin:0 0 .5em}'
        . '.dolinews-feed__more{margin:0;font-size:.9em}'
        . '.dolinews-feed__badge{border:1px solid currentColor;border-radius:.25em;padding:0 .4em;font-size:.9em;line-height:1.6}'
        . '.dolinews-feed__badge--security{color:#b42318;font-weight:600}'
        . '.dolinews-feed__credit{margin:1em 0 0;font-size:.8em;opacity:.7}'
        . '.dolinews-feed--cards .dolinews-feed__list{display:grid;gap:1.25em;grid-template-columns:repeat(auto-fill,minmax(16em,1fr))}'
        . '.dolinews-feed--cards .dolinews-feed__item{border:1px solid rgba(0,0,0,.12);border-radius:.5em;padding:1em;margin:0}'
        . '</style>';
}

/**
 * The same block from a theme template, where a shortcode would have to
 * be built as a string:
 *
 *   dolinews_feed(array('editor' => 'cap-rel', 'limit' => '3'));
 *
 * @param array<string, string> $args
 *
 * @return void
 */
function dolinews_feed($args = array())
{
    echo dolinews_feed_shortcode($args); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped field by field above.
}
