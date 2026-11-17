<?php
// Run on the server, from the site folder:
//   C:\xampp\php\php.exe tools\set-admin-password.php
// Sets the stats page password and, optionally, the CDN access log to count.
// Writes config.json in the data folder (outside the web root). Command line only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('VX_APP', 1);
require __DIR__ . '/../inc/bootstrap.php';

function ask(string $q, bool $hidden = false): string {
    echo $q;
    if ($hidden && DIRECTORY_SEPARATOR === '\\') {
        $cmd = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $v = rtrim((string)shell_exec($cmd), "\r\n");
        return $v;
    }
    if ($hidden) system('stty -echo');
    $v = rtrim((string)fgets(STDIN), "\r\n");
    if ($hidden) { system('stty echo'); echo "\n"; }
    return $v;
}

echo "Data folder: " . vx_data_dir() . "\n";
$p1 = ask('New stats password (at least 12 characters): ', true);
if (strlen($p1) < 12) { fwrite(STDERR, "Too short.\n"); exit(1); }
$p2 = ask('Again: ', true);
if ($p1 !== $p2) { fwrite(STDERR, "The passwords differ.\n"); exit(1); }

$cur = vx_config();
$default = $cur['cdn_logs'][0] ?? 'C:/xampp/apache/logs/cdn-access.log';
$log = ask('CDN access log to count' . ($default ? " [$default]" : ' (blank to skip)') . ': ');
if ($log === '') $log = $default;
if ($log !== '' && strpbrk($log, '*?[') === false && !is_readable($log)) echo "Warning: cannot read $log yet.\n";

vx_json_update('config.json', [], function ($c) use ($p1, $log) {
    $c['admin_password_hash'] = password_hash($p1, PASSWORD_DEFAULT);
    if ($log !== '') $c['cdn_logs'] = [$log];
    $c += ['retention_months' => 24, 'privacy_notice_version' => '2026-11-17'];
    return $c;
});
echo "Saved " . vx_path('config.json') . "\n";
