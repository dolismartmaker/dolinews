<?php

/**
 * Just enough WordPress to run integrations/wordpress/dolinews-feed.php
 * outside WordPress, in a process of its own.
 *
 * A process of its own is not a precaution, it is a necessity: the
 * plugin calls __(), esc_html() and friends, and Laravel's __() has
 * another signature entirely. Loading both in the same process would
 * test a plugin that WordPress will never run.
 *
 * Usage: php tests/Support/wordpress-harness.php <case.json>
 *
 * The case file carries the answer the service is supposed to give and
 * the shortcode attributes:
 *
 *   {"status": 200, "body": "<the /feeds.json document>", "atts": {...}}
 *
 * What comes out on stdout is the HTML the shortcode produced. What
 * the plugin logs goes to stderr, which is where error_log writes
 * without a WordPress installation.
 */
if ($argc < 2) {
    fwrite(STDERR, "usage: wordpress-harness.php <case.json>\n");
    exit(2);
}

$case = json_decode((string) file_get_contents($argv[1]), true);

if (! is_array($case)) {
    fwrite(STDERR, "the case file is not JSON\n");
    exit(2);
}

$GLOBALS['harness'] = [
    'status' => isset($case['status']) ? (int) $case['status'] : 200,
    'body' => isset($case['body']) ? (string) $case['body'] : '',
    'error' => isset($case['error']) ? (string) $case['error'] : null,
    'transients' => [],
    'requests' => [],
];

define('ABSPATH', __DIR__);
define('WEEK_IN_SECONDS', 604800);

function __($text, $domain = null)
{
    return $text;
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_html__($text, $domain = null)
{
    return esc_html($text);
}

function esc_url($url)
{
    return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
}

function home_url($path = '/')
{
    return 'https://exemple.test'.$path;
}

function get_option($name, $default = false)
{
    return $name === 'date_format' ? 'Y-m-d' : $default;
}

function date_i18n($format, $timestamp)
{
    return gmdate((string) $format, (int) $timestamp);
}

function add_shortcode($tag, $callback)
{
    $GLOBALS['harness']['shortcodes'][$tag] = $callback;
}

function shortcode_atts($pairs, $atts, $shortcode = '')
{
    $atts = is_array($atts) ? $atts : [];
    $out = [];

    foreach ($pairs as $name => $default) {
        $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
    }

    return $out;
}

function get_transient($key)
{
    return $GLOBALS['harness']['transients'][$key] ?? false;
}

function set_transient($key, $value, $ttl = 0)
{
    $GLOBALS['harness']['transients'][$key] = $value;

    return true;
}

function wp_remote_get($url, $args = [])
{
    $GLOBALS['harness']['requests'][] = $url;

    if ($GLOBALS['harness']['error'] !== null) {
        return new WP_Error($GLOBALS['harness']['error']);
    }

    return [
        'response' => ['code' => $GLOBALS['harness']['status']],
        'body' => $GLOBALS['harness']['body'],
    ];
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? ($response['response']['code'] ?? 0) : 0;
}

function wp_remote_retrieve_body($response)
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}

class WP_Error
{
    /** @var string */
    private $message;

    public function __construct($message)
    {
        $this->message = (string) $message;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

require __DIR__.'/../../integrations/wordpress/dolinews-feed.php';

$atts = isset($case['atts']) && is_array($case['atts']) ? $case['atts'] : [];

// Second pass requested: the same call again, to show that the answer
// comes from the local cache and that the service is hit once.
$html = dolinews_feed_shortcode($atts);

if (! empty($case['twice'])) {
    $html = dolinews_feed_shortcode($atts);
}

echo json_encode([
    'html' => $html,
    'requests' => $GLOBALS['harness']['requests'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
