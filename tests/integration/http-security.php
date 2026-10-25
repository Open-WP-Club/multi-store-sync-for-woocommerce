<?php
// Disposable integration environment only; invoked by run.sh via WP-CLI.
if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    exit;
}

function mss_http_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// The fixture is a stand-in for a public image origin. Exempt only its exact
// origin from private-IP validation; redirects to its literal Docker IP must
// pass the normal WordPress checks without any exemption.
$allow_fixture = static function (bool $external, string $host, string $url): bool {
    $parts = wp_parse_url($url);
    return $host === 'http-fixture' && ($parts['scheme'] ?? '') === 'http'
        && ($parts['port'] ?? 80) === 80 && !isset($parts['user']) && !isset($parts['pass'])
        ? true : $external;
};
add_filter('http_request_host_is_external', $allow_fixture, 10, 3);
$stats = static function (): array {
    $response = wp_safe_remote_get('http://http-fixture/stats', ['timeout' => 10]);
    mss_http_check(!is_wp_error($response), 'HTTP fixture stats unavailable');
    mss_http_check(wp_remote_retrieve_response_code($response) === 200, 'HTTP fixture stats failed');
    $data = json_decode(wp_remote_retrieve_body($response), true);
    mss_http_check(is_array($data), 'HTTP fixture stats were not JSON');
    return $data;
};
try {
    $before = $stats();
    $client = new WC_Multi_Store_API_Client('http://http-fixture', 'fixture-key', 'fixture-secret', 'basic_auth');
    foreach (['image.png', 'redirect-image'] as $source) {
        $result = $client->upload_image([
            'url' => 'http://http-fixture/' . $source,
            'filename' => 'image.png', 'mime_type' => 'image/png',
        ]);
        mss_http_check(!is_wp_error($result) && !empty($result['id']), 'Allowed image transfer failed: ' . $source);
    }
    $allowed = $stats();
    mss_http_check($allowed['images'] === $before['images'] + 2 && $allowed['uploads'] === $before['uploads'] + 2, 'Allowed transfers did not complete real HTTP fetches and uploads');

    $blocked = $client->upload_image([
        'url' => 'http://http-fixture/redirect-private',
        'filename' => 'private.png', 'mime_type' => 'image/png',
    ]);
    mss_http_check(is_wp_error($blocked) && $blocked->get_error_code() === 'image_fetch_error', 'Private redirect was not rejected at the image fetch');
    $after = $stats();
    mss_http_check($after['redirects'] === $allowed['redirects'] + 1, 'Initial redirecting origin was never reached');
    mss_http_check($after['private_hits'] === $before['private_hits'], 'A real HTTP request reached the private redirect target');
    mss_http_check($after['uploads'] === $allowed['uploads'], 'Rejected private response reached media upload');
} finally {
    remove_filter('http_request_host_is_external', $allow_fixture, 10);
}
WP_CLI::success('HTTP image fetch: real allowed redirects and blocked private redirect');
