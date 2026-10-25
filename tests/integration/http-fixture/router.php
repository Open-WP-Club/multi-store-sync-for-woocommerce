<?php
// Mounted only in the disposable built-in HTTP fixture; never run via Apache.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state_file = '/tmp/wc-mss-http-fixture.json';
$state = is_file($state_file) ? json_decode(file_get_contents($state_file), true) : [];
$state += ['images' => 0, 'redirects' => 0, 'private_hits' => 0, 'uploads' => 0];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1ZkAAAAASUVORK5CYII=');
if ($path === '/redirect-private') {
    $state['redirects']++;
    file_put_contents($state_file, json_encode($state));
    header('Location: http://' . gethostbyname('http-fixture') . '/private-image.png', true, 302);
} elseif ($path === '/redirect-image') {
    $state['redirects']++;
    file_put_contents($state_file, json_encode($state));
    header('Location: http://http-fixture/image.png', true, 302);
} elseif ($path === '/image.png' || $path === '/private-image.png') {
    $state[$path === '/image.png' ? 'images' : 'private_hits']++;
    file_put_contents($state_file, json_encode($state));
    header('Content-Type: image/png');
    echo $png;
} elseif ($path === '/wp-json/wp/v2/media' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (file_get_contents('php://input') !== $png) {
        http_response_code(400);
        echo '{"message":"Unexpected image bytes"}';
        return;
    }
    $state['uploads']++;
    file_put_contents($state_file, json_encode($state));
    http_response_code(201);
    header('Content-Type: application/json');
    echo json_encode(['id' => $state['uploads'], 'source_url' => 'http://http-fixture/image.png']);
} elseif ($path === '/stats') {
    header('Content-Type: application/json');
    echo json_encode($state);
} elseif ($path === '/health') {
    echo 'ok';
} else {
    http_response_code(404);
}
