<?php
// Counts downloads and updater activity from the Apache access log of cdn.vestigex.app.
// Reads only the new part of each log on every call and keeps running totals in
// logstats.json. Addresses are reduced to a salted hash for de-duplication and never stored raw.
if (!defined('VX_APP')) { http_response_code(404); exit; }

const VX_MONTHS = ['Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12];

// Turns one access-log line (common or combined format) into [kind, name, date, ip], or null.
//   kind "download": a release package or the online updater zip  (name = file name)
//   kind "check":    the app or updater fetched update/latest.json  (name = '')
//   kind "update":   an update manifest, i.e. an update was started (name = manifest)
function vx_classify_line(string $line): ?array {
    if (!preg_match('#^(\S+) \S+ \S+ \[(\d{2})/(\w{3})/(\d{4}):[^\]]*\] "([A-Z]+) (\S+)[^"]*" (\d{3}) #', $line, $m)) return null;
    [, $ip, $d, $mon, $y, $method, $path, $status] = $m;
    if ($method !== 'GET' || !isset(VX_MONTHS[$mon])) return null;
    $status = (int)$status;
    $path = rawurldecode(strtok($path, '?'));
    $date = sprintf('%04d-%02d-%02d', $y, VX_MONTHS[$mon], $d);

    if ($path === '/update/latest.json') {
        return ($status === 200 || $status === 304) ? ['check', '', $date, $ip] : null;
    }
    if (preg_match('#^/update/manifests/([A-Za-z0-9._-]+)$#', $path, $mm)) {
        return $status === 200 ? ['update', $mm[1], $date, $ip] : null;
    }
    if (preg_match('#^/(?:\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?|update)/([A-Za-z0-9._-]+\.(?:zip|tar\.gz))$#', $path, $mm)) {
        // 206 is a range request: download managers and resumed downloads.
        return ($status === 200 || $status === 206) ? ['download', $mm[1], $date, $ip] : null;
    }
    return null;
}

function vx_logstats_refresh(int $budget = 67108864): array {
    $logs = vx_config()['cdn_logs'] ?? [];
    if (is_string($logs)) $logs = [$logs];
    $salt = vx_secret();

    return vx_json_update('logstats.json', [], function ($st) use ($logs, $salt, $budget) {
        $st += ['files' => [], 'download' => [], 'update' => [], 'check' => [], 'seen' => [], 'check_ips' => [], 'errors' => []];
        $st['errors'] = [];
        foreach ($logs as $path) {
            if (!is_file($path) || !is_readable($path)) { $st['errors'][] = "Cannot read $path"; continue; }
            $size = filesize($path);
            $pos = $st['files'][$path]['offset'] ?? 0;
            if ($size < $pos) $pos = 0;   // the log was rotated or truncated: start over
            $fh = fopen($path, 'rb');
            fseek($fh, $pos);
            $read = 0;
            while ($read < $budget && ($line = fgets($fh)) !== false) {
                if (substr($line, -1) !== "\n") break;   // a line still being written
                $read += strlen($line);
                $pos += strlen($line);
                $hit = vx_classify_line($line);
                if (!$hit) continue;
                [$kind, $name, $date, $ip] = $hit;
                $h = substr(hash_hmac('sha256', $ip, $salt), 0, 12);
                $k = $kind . '|' . $name . '|' . $h;
                if (isset($st['seen'][$date][$k])) continue;   // one per address, item and day
                $st['seen'][$date][$k] = 1;
                if ($kind === 'check') {
                    $st['check'][$date] = ($st['check'][$date] ?? 0) + 1;
                    $st['check_ips'][$date][] = $h;
                } else {
                    $st[$kind][$name][$date] = ($st[$kind][$name][$date] ?? 0) + 1;
                }
            }
            fclose($fh);
            $st['files'][$path] = ['offset' => $pos, 'size' => $size];
            if ($pos < $size) $st['errors'][] = 'Still reading ' . basename($path) . ' (' . round($pos / max($size, 1) * 100) . '%), reload to continue';
        }
        // De-duplication only needs recent days; per-day check addresses are kept 35 days
        // so the page can count distinct installs over 7 and 30 days.
        $days = array_keys($st['seen']);
        if ($days) {
            $newest = max($days);
            $cut3 = date('Y-m-d', strtotime($newest . ' -3 days'));
            $cut35 = date('Y-m-d', strtotime($newest . ' -35 days'));
            foreach ($st['seen'] as $d => $_) if ($d < $cut3) unset($st['seen'][$d]);
            foreach ($st['check_ips'] as $d => $_) if ($d < $cut35) unset($st['check_ips'][$d]);
        }
        $st['updated'] = gmdate('Y-m-d H:i') . ' UTC';
        return $st;
    });
}

// Distinct addresses that checked for updates in the $days days up to $today.
function vx_active_installs(array $st, int $days, string $today): int {
    $from = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days'));
    $set = [];
    foreach ($st['check_ips'] ?? [] as $d => $hs) {
        if ($d >= $from && $d <= $today) foreach ($hs as $h) $set[$h] = 1;
    }
    return count($set);
}
