<?php
// Private download statistics and form submissions. Password set with tools/set-admin-password.php.
define('VX_APP', 1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/logstats.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$cfg = vx_config();
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

$sessDir = vx_path('sessions');
if (!is_dir($sessDir)) @mkdir($sessDir, 0700, true);
session_save_path($sessDir);
session_name('vxadmin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/stats.php', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

function vx_page(string $title, string $body): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow"><title>' . vx_h($title) . '</title><style>'
       . ':root{--bg:#111317;--s:#171A1F;--line:#2A3039;--fg:#E4E7EB;--mu:#8E98A5;--ac:#4A90C2;--bad:#E07A4F;color-scheme:dark}'
       . '@media (prefers-color-scheme:light){:root{--bg:#F1F3F5;--s:#fff;--line:#D3DAE1;--fg:#13171C;--mu:#59636F;--ac:#2F6F9F;--bad:#C2541F;color-scheme:light}}'
       . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 "Segoe UI",system-ui,sans-serif}'
       . 'main{max-width:1180px;margin:0 auto;padding:24px 16px 60px}h1{font-size:1.4rem;margin:0 0 4px}h2{font-size:1.05rem;margin:32px 0 10px}'
       . '.mu{color:var(--mu)}.bad{color:var(--bad)}a{color:var(--ac)}'
       . '.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-top:18px}'
       . '.tile{background:var(--s);border:1px solid var(--line);border-radius:8px;padding:14px}.tile b{display:block;font-size:1.6rem;font-weight:600;font-variant-numeric:tabular-nums}'
       . '.tile span{color:var(--mu);font-size:.85rem}.scroll{overflow-x:auto;border:1px solid var(--line);border-radius:8px;background:var(--s)}'
       . 'table{border-collapse:collapse;width:100%;font-size:.88rem}th,td{padding:7px 10px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}'
       . 'td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}tr:last-child td{border-bottom:0}th{color:var(--mu);font-weight:500}'
       . 'input,button{font:inherit;color:var(--fg);background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:7px 10px}'
       . 'button{cursor:pointer}button.pri{background:var(--ac);border-color:var(--ac);color:#fff}button.link{border:0;background:none;color:var(--bad);padding:0}'
       . '.bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}.login{max-width:340px;margin:12vh auto;display:grid;gap:12px}'
       . '</style></head><body><main>' . $body . '</main></body></html>';
    exit;
}

if (empty($cfg['admin_password_hash'])) {
    http_response_code(503);
    vx_page('Not set up', '<h1>Stats are not set up</h1><p class="mu">Set a password on the server with <code>php tools/set-admin-password.php</code>.</p>');
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($post && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    vx_page('Expired', '<p>The form expired. <a href="stats.php">Reload</a></p>');
}

// Sign in, with a lockout after 5 wrong passwords in 15 minutes from one address.
if (empty($_SESSION['admin'])) {
    $msg = '';
    if ($post && isset($_POST['password'])) {
        $ipKey = vx_hash_ip(vx_client_ip());
        if (!vx_rate_ok('login', $ipKey, 5, 900, false)) {
            $msg = 'Too many attempts. Try again in 15 minutes.';
        } elseif (password_verify((string)$_POST['password'], $cfg['admin_password_hash'])) {
            vx_rate_clear('login', $ipKey);
            session_regenerate_id(true);
            $_SESSION['admin'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            header('Location: stats.php', true, 303);
            exit;
        } else {
            vx_rate_ok('login', $ipKey, 5, 900, true);
            usleep(400000);
            $msg = 'Wrong password.';
        }
    }
    vx_page('Sign in', '<form class="login" method="post"><h1>VESTIGEX stats</h1>'
        . ($msg ? '<p class="bad">' . vx_h($msg) . '</p>' : '')
        . '<input type="hidden" name="csrf" value="' . vx_h($csrf) . '">'
        . '<input type="password" name="password" placeholder="Password" autocomplete="current-password" required autofocus>'
        . '<button class="pri">Sign in</button></form>');
}

// Signed-in sessions end after 8 hours.
if (time() - (int)$_SESSION['admin'] > 8 * 3600) { session_destroy(); header('Location: stats.php'); exit; }

$action = $post ? (string)($_POST['action'] ?? '') : '';
if ($action === 'logout') { session_destroy(); header('Location: stats.php', true, 303); exit; }
if ($action === 'delete' && !empty($_POST['id'])) {
    $id = (string)$_POST['id'];
    vx_delete_submissions(function ($r) use ($id) { return ($r['id'] ?? '') === $id; });
    header('Location: stats.php?deleted=1#submissions', true, 303);
    exit;
}

vx_apply_retention();
$subs = vx_read_submissions();

// CSV export of the submissions, with spreadsheet formula characters neutralised.
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vestigex-downloads-' . gmdate('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['time_utc', 'name', 'organization', 'email', 'file', 'version']);
    foreach ($subs as $r) {
        $row = [];
        foreach (['time', 'name', 'organization', 'email', 'file', 'version'] as $k) {
            $v = (string)($r[$k] ?? '');
            if ($v !== '' && strpos('=+-@' . "\t\r", $v[0]) !== false) $v = "'" . $v;
            $row[] = $v;
        }
        fputcsv($out, $row);
    }
    exit;
}

@set_time_limit(120);
$st = vx_logstats_refresh();
$today = date('Y-m-d');
$sum = function (array $byDate, int $days = 0) use ($today) {
    $from = $days ? date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days')) : '';
    $n = 0;
    foreach ($byDate as $d => $c) if ($d >= $from) $n += $c;
    return $n;
};

$dlTotal = 0; $dl30 = 0;
foreach ($st['download'] as $byDate) { $dlTotal += $sum($byDate); $dl30 += $sum($byDate, 30); }
$updTotal = 0;
foreach ($st['update'] as $byDate) $updTotal += $sum($byDate);
$form30 = 0;
$from30 = gmdate('Y-m-d', strtotime('-29 days'));
foreach ($subs as $r) if (substr($r['time'] ?? '', 0, 10) >= $from30) $form30++;

$b = '<div class="bar"><div><h1>VESTIGEX downloads</h1><div class="mu">CDN log read ' . vx_h($st['updated'] ?? 'never') . '</div></div>'
   . '<form method="post"><input type="hidden" name="csrf" value="' . vx_h($csrf) . '"><button name="action" value="logout">Sign out</button></form></div>';
foreach ($st['errors'] as $e) $b .= '<p class="bad">' . vx_h($e) . '</p>';
if (empty($cfg['cdn_logs'])) $b .= '<p class="bad">No CDN access log is configured (cdn_logs in config.json), so only form submissions are counted.</p>';

$b .= '<div class="tiles">'
    . '<div class="tile"><b>' . $dlTotal . '</b><span>Downloads from the CDN, all time</span></div>'
    . '<div class="tile"><b>' . $dl30 . '</b><span>Downloads, last 30 days</span></div>'
    . '<div class="tile"><b>' . count($subs) . '</b><span>Form submissions (' . $form30 . ' in 30 days)</span></div>'
    . '<div class="tile"><b>' . vx_active_installs($st, 1, $today) . '</b><span>Installs that checked for updates today</span></div>'
    . '<div class="tile"><b>' . vx_active_installs($st, 7, $today) . ' / ' . vx_active_installs($st, 30, $today) . '</b><span>Active installs, 7 / 30 days</span></div>'
    . '<div class="tile"><b>' . $updTotal . '</b><span>Updates started, all time</span></div>'
    . '</div>';

// Per file: CDN downloads next to form submissions.
$formByFile = [];
foreach ($subs as $r) { $f = $r['file'] ?? '?'; $formByFile[$f] = ($formByFile[$f] ?? 0) + 1; }
$files = array_unique(array_merge(array_keys($st['download']), array_keys($formByFile)));
usort($files, function ($a, $b) use ($st, $sum) { return $sum($st['download'][$b] ?? []) <=> $sum($st['download'][$a] ?? []) ?: strcmp($a, $b); });
$b .= '<h2>By file</h2><div class="scroll"><table><tr><th>File</th><th class="n">CDN, 7 days</th><th class="n">CDN, 30 days</th><th class="n">CDN, all time</th><th class="n">Form</th></tr>';
foreach ($files as $f) {
    $bd = $st['download'][$f] ?? [];
    $b .= '<tr><td>' . vx_h($f) . '</td><td class="n">' . $sum($bd, 7) . '</td><td class="n">' . $sum($bd, 30) . '</td><td class="n">' . $sum($bd) . '</td><td class="n">' . ($formByFile[$f] ?? 0) . '</td></tr>';
}
if (!$files) $b .= '<tr><td colspan="5" class="mu">No downloads yet</td></tr>';
$b .= '</table></div><p class="mu" style="font-size:.85rem;margin-top:8px">A CDN download is one address fetching a file on one day, so resumed and multi-part downloads count once. Form counts people who filled in the form; direct links to the CDN skip it.</p>';

// Last 14 days.
$b .= '<h2>Last 14 days</h2><div class="scroll"><table><tr><th>Date</th><th class="n">CDN downloads</th><th class="n">Form</th><th class="n">Update checks</th><th class="n">Updates started</th></tr>';
$formByDay = [];
foreach ($subs as $r) { $d = substr($r['time'] ?? '', 0, 10); $formByDay[$d] = ($formByDay[$d] ?? 0) + 1; }
for ($i = 0; $i < 14; $i++) {
    $d = date('Y-m-d', strtotime($today . ' -' . $i . ' days'));
    $dl = 0; foreach ($st['download'] as $bd) $dl += $bd[$d] ?? 0;
    $up = 0; foreach ($st['update'] as $bd) $up += $bd[$d] ?? 0;
    $b .= '<tr><td>' . $d . '</td><td class="n">' . $dl . '</td><td class="n">' . ($formByDay[$d] ?? 0) . '</td><td class="n">' . ($st['check'][$d] ?? 0) . '</td><td class="n">' . $up . '</td></tr>';
}
$b .= '</table></div>';

if ($st['update']) {
    $b .= '<h2>Updates started, by release</h2><div class="scroll"><table><tr><th>Manifest</th><th class="n">30 days</th><th class="n">All time</th></tr>';
    foreach ($st['update'] as $m => $bd) $b .= '<tr><td>' . vx_h($m) . '</td><td class="n">' . $sum($bd, 30) . '</td><td class="n">' . $sum($bd) . '</td></tr>';
    $b .= '</table></div>';
}

// Submissions, newest first, with search and delete (for erasure requests).
$q = trim((string)($_GET['q'] ?? ''));
$rows = array_reverse($subs);
if ($q !== '') $rows = array_values(array_filter($rows, function ($r) use ($q) {
    return stripos(($r['name'] ?? '') . ' ' . ($r['organization'] ?? '') . ' ' . ($r['email'] ?? ''), $q) !== false;
}));
$shown = array_slice($rows, 0, 500);
$b .= '<h2 id="submissions">Form submissions</h2><div class="bar" style="margin-bottom:10px"><form method="get"><input name="q" value="' . vx_h($q) . '" placeholder="Search name, organization or email"> <button>Search</button></form>'
    . '<a href="stats.php?export=csv">Export CSV</a></div>';
if (isset($_GET['deleted'])) $b .= '<p class="mu">Submission deleted.</p>';
$b .= '<div class="scroll"><table><tr><th>Time (UTC)</th><th>Name</th><th>Organization</th><th>Email</th><th>File</th><th></th></tr>';
foreach ($shown as $r) {
    $b .= '<tr><td>' . vx_h(str_replace(['T', 'Z'], [' ', ''], $r['time'] ?? '')) . '</td><td>' . vx_h($r['name'] ?? '') . '</td><td>' . vx_h($r['organization'] ?? '')
        . '</td><td>' . vx_h($r['email'] ?? '') . '</td><td>' . vx_h($r['file'] ?? '') . '</td><td><details><summary class="mu">Delete</summary><form method="post">'
        . '<input type="hidden" name="csrf" value="' . vx_h($csrf) . '"><input type="hidden" name="id" value="' . vx_h($r['id'] ?? '') . '">'
        . '<button class="link" name="action" value="delete">Confirm delete</button></form></details></td></tr>';
}
if (!$shown) $b .= '<tr><td colspan="6" class="mu">' . ($q !== '' ? 'No matches' : 'No submissions yet') . '</td></tr>';
$b .= '</table></div>';
if (count($rows) > 500) $b .= '<p class="mu">Showing the newest 500 of ' . count($rows) . '. Export CSV for all.</p>';

vx_page('VESTIGEX stats', $b);
