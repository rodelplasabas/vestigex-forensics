<?php
// Receives the download form, stores it outside the web root and returns the file URL.
define('VX_APP', 1);
require __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function vx_reply(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    vx_reply(405, ['ok' => false, 'error' => 'Method not allowed']);
}

// Only accept posts from our own pages.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = vx_config()['allowed_origins'] ?? ['https://vestigex.app', 'https://www.vestigex.app'];
if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
    vx_reply(403, ['ok' => false, 'error' => 'Forbidden']);
}

$raw = file_get_contents('php://input', false, null, 0, 8192);
$in = json_decode($raw ?: '', true);
if (!is_array($in)) $in = $_POST;

$clean = function ($v, int $max): string {
    $v = is_string($v) ? $v : '';
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);   // no control characters or line breaks
    $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
};

$name  = $clean($in['name'] ?? '', 120);
$org   = $clean($in['organization'] ?? '', 160);
$email = $clean($in['email'] ?? '', 254);
$file  = is_string($in['file'] ?? null) ? $in['file'] : '';
$consent = ($in['consent'] ?? false) === true || ($in['consent'] ?? '') === 'yes';
$honeypot = trim((string)($in['website'] ?? ''));

$errors = [];
if ($name === '') $errors['name'] = 'Enter your name.';
if ($org === '') $errors['organization'] = 'Enter your organization.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
if (!$consent) $errors['consent'] = 'Please agree to the privacy notice to continue.';
if (!vx_allowed_download($file)) vx_reply(400, ['ok' => false, 'error' => 'Unknown download']);
if ($errors) vx_reply(422, ['ok' => false, 'errors' => $errors]);

// A filled hidden field means a bot: answer as if it worked, store nothing.
if ($honeypot !== '') vx_reply(200, ['ok' => true, 'url' => $file]);

try {
    $ipKey = vx_hash_ip(vx_client_ip());
    if (!vx_rate_ok('form', $ipKey, 20, 3600)) {
        vx_reply(429, ['ok' => false, 'error' => 'Too many requests. Please try again in an hour.']);
    }
    vx_append_submission([
        'id' => bin2hex(random_bytes(8)),
        'time' => gmdate('Y-m-d\TH:i:s\Z'),
        'name' => $name,
        'organization' => $org,
        'email' => $email,
        'file' => basename(parse_url($file, PHP_URL_PATH)),
        'version' => explode('/', trim(parse_url($file, PHP_URL_PATH), '/'))[0],
        'privacy_notice' => (string)(vx_config()['privacy_notice_version'] ?? '2026-11-17'),
    ]);
} catch (Throwable $e) {
    error_log('vestigex download form: ' . $e->getMessage());
    vx_reply(500, ['ok' => false, 'error' => 'Could not save your details', 'url' => $file]);
}

vx_reply(200, ['ok' => true, 'url' => $file]);
