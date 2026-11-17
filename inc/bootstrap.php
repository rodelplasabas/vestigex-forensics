<?php
// Shared helpers for the download form and the stats page. Never served on its own:
// .htaccess denies /inc, and this file does nothing unless included.
if (!defined('VX_APP')) { http_response_code(404); exit; }

// Dates on the stats page follow Rodel's time zone, not the server's php.ini default.
date_default_timezone_set('Asia/Manila');

// Where submissions, stats and the admin password live. Must be outside every web root.
// Default: a "vestigex-data" folder beside the site folder (C:\www\vestigex-data when the
// site is C:\www\vestigex.app). Set VESTIGEX_DATA_DIR (Apache SetEnv) to put it elsewhere.
function vx_data_dir(): string {
    $dir = getenv('VESTIGEX_DATA_DIR');
    if (!$dir && isset($_SERVER['VESTIGEX_DATA_DIR'])) $dir = $_SERVER['VESTIGEX_DATA_DIR'];
    if (!$dir) $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vestigex-data';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        throw new RuntimeException('Data folder is missing and cannot be created');
    }
    return rtrim($dir, '/\\');
}

function vx_path(string $name): string {
    return vx_data_dir() . DIRECTORY_SEPARATOR . $name;
}

// Read and write small JSON files under an exclusive lock.
function vx_json_read(string $name, $default) {
    $p = vx_path($name);
    if (!is_file($p)) return $default;
    $fh = fopen($p, 'rb');
    if (!$fh) return $default;
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $v = json_decode($raw, true);
    return is_array($v) ? $v : $default;
}

// Update a JSON file atomically: $fn receives the current value and returns the new one.
function vx_json_update(string $name, $default, callable $fn) {
    $p = vx_path($name);
    $fh = fopen($p, 'c+b');
    if (!$fh) throw new RuntimeException('Cannot open ' . $name);
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $cur = json_decode($raw === '' ? 'null' : $raw, true);
    if (!is_array($cur)) $cur = $default;
    $new = $fn($cur);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $new;
}

function vx_config(): array {
    return vx_json_read('config.json', []);
}

// The visitor's address. The main site sits behind Cloudflare, so REMOTE_ADDR is a
// Cloudflare edge; CF-Connecting-IP carries the visitor. Used only for rate limiting
// and login lockout, and only ever stored as a salted hash.
function vx_client_ip(): string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function vx_secret(): string {
    $s = vx_json_update('secret.json', [], function ($c) {
        if (empty($c['salt'])) $c['salt'] = bin2hex(random_bytes(32));
        return $c;
    });
    return $s['salt'];
}

function vx_hash_ip(string $ip): string {
    return substr(hash_hmac('sha256', $ip, vx_secret()), 0, 16);
}

// Sliding-window limiter. Returns false when $key has used up $max hits in $window seconds.
function vx_rate_ok(string $bucket, string $key, int $max, int $window, bool $record = true): bool {
    $now = time();
    $ok = true;
    vx_json_update('ratelimit.json', [], function ($all) use ($bucket, $key, $max, $window, $now, $record, &$ok) {
        foreach ($all as $b => $keys) {
            foreach ($keys as $k => $hits) {
                $hits = array_values(array_filter($hits, function ($t) use ($now) { return $t > $now - 86400; }));
                if ($hits) $all[$b][$k] = $hits; else unset($all[$b][$k]);
            }
        }
        $hits = array_values(array_filter($all[$bucket][$key] ?? [], function ($t) use ($now, $window) { return $t > $now - $window; }));
        if (count($hits) >= $max) { $ok = false; return $all; }
        if ($record) { $hits[] = $now; $all[$bucket][$key] = $hits; }
        return $all;
    });
    return $ok;
}

function vx_rate_clear(string $bucket, string $key): void {
    vx_json_update('ratelimit.json', [], function ($all) use ($bucket, $key) {
        unset($all[$bucket][$key]);
        return $all;
    });
}

// Downloads the form may hand out: release packages and the online updater on cdn.vestigex.app.
function vx_allowed_download(string $url): bool {
    return (bool)preg_match('#^https://cdn\.vestigex\.app/(?:\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?|update)/[A-Za-z0-9._-]+\.(?:zip|tar\.gz)$#', $url);
}

function vx_h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Submissions: one JSON object per line, appended under a lock.
function vx_submissions_file(): string {
    return vx_path('submissions.jsonl');
}

function vx_append_submission(array $row): void {
    $fh = fopen(vx_submissions_file(), 'ab');
    if (!$fh) throw new RuntimeException('Cannot write submissions');
    flock($fh, LOCK_EX);
    fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

function vx_read_submissions(): array {
    $p = vx_submissions_file();
    if (!is_file($p)) return [];
    $fh = fopen($p, 'rb');
    flock($fh, LOCK_SH);
    $rows = [];
    while (($line = fgets($fh)) !== false) {
        $r = json_decode($line, true);
        if (is_array($r)) $rows[] = $r;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $rows;
}

// Rewrite the file without the rows whose id is in $ids (erasure requests, retention).
function vx_delete_submissions(callable $drop): int {
    $p = vx_submissions_file();
    if (!is_file($p)) return 0;
    $fh = fopen($p, 'c+b');
    flock($fh, LOCK_EX);
    $keep = '';
    $n = 0;
    while (($line = fgets($fh)) !== false) {
        $r = json_decode($line, true);
        if (is_array($r) && $drop($r)) { $n++; continue; }
        $keep .= $line;
    }
    if ($n) { ftruncate($fh, 0); rewind($fh); fwrite($fh, $keep); fflush($fh); }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $n;
}

// Submissions older than the retention period are removed. Default 24 months.
function vx_apply_retention(): int {
    $months = (int)(vx_config()['retention_months'] ?? 24);
    if ($months <= 0) return 0;
    $cutoff = gmdate('Y-m-d\TH:i:s\Z', strtotime('-' . $months . ' months'));
    return vx_delete_submissions(function ($r) use ($cutoff) { return ($r['time'] ?? '') < $cutoff; });
}
