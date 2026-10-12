<?php
/* QCR prescreen form handler — emails each submission to the QCR team and keeps a copy outside the public web folder. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

$TO      = 'info@qc-research.com';
$FROM    = 'QCR Website <no-reply@qc-research.com>';
$STORE   = dirname($_SERVER['DOCUMENT_ROOT']) . '/qcr_leads';
$TESTKEY = 'qcr-test-7f3a91';           // used only for delivery tests
$TESTTO  = 'outreach@qc-research.com';

function f($k, $max = 200) { $v = isset($_POST[$k]) ? trim((string)$_POST[$k]) : ''; $v = str_replace(["\r", "\n"], ' ', $v); return mb_substr($v, 0, $max); }

// spam guards: honeypot field, too-fast submits, simple per-IP rate limit
if (f('company') !== '') { echo '{"ok":true}'; exit; }
$elapsed = (int) f('elapsed', 10);
if ($elapsed > 0 && $elapsed < 2500) { echo '{"ok":true}'; exit; }
if (!is_dir($STORE)) { @mkdir($STORE, 0700, true); }
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$rl = $STORE . '/rate_' . md5($ip) . '.txt';
$hits = array_filter(array_map('intval', @file($rl) ?: []), fn($t) => $t > time() - 3600);
if (count($hits) >= 8) { http_response_code(429); echo '{"ok":false,"error":"rate"}'; exit; }
$hits[] = time(); @file_put_contents($rl, implode("\n", $hits));

$d = [
  'time'      => date('Y-m-d H:i:s T'),
  'condition' => f('cond_label'),
  'cond_id'   => f('cond', 40),
  'zip'       => f('zip', 10),
  'role'      => f('who', 60),
  'name'      => f('name', 120),
  'phone'     => f('phone', 40),
  'email'     => f('email', 160),
  'language'  => f('lang', 8),
  'consent'   => f('consent', 5),
  'page'      => f('page', 200),
];
if ($d['name'] === '' || $d['phone'] === '' || !preg_match('/^\d{5}$/', $d['zip']) || $d['consent'] !== 'yes') {
  http_response_code(422); echo '{"ok":false,"error":"invalid"}'; exit;
}
if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { $d['email'] = ''; }

// 1) keep a copy (outside public_html, not reachable from the web)
$csv = $STORE . '/prescreen_leads.csv';
$new = !file_exists($csv);
if ($fh = @fopen($csv, 'a')) {
  if ($new) fputcsv($fh, array_keys($d));
  fputcsv($fh, array_values($d));
  fclose($fh); @chmod($csv, 0600);
}

// 2) email the team
$test = f('testkey', 40) === $TESTKEY;
$to = $test ? $TESTTO : $TO;
$langName = ['en' => 'English', 'es' => 'Spanish', 'zh' => 'Chinese'][$d['language']] ?? 'English';
$subject = ($test ? '[TEST] ' : '') . 'New prescreen: ' . ($d['condition'] ?: 'General interest') . ' (' . $d['zip'] . ')';
$body = "A new prescreen was submitted on qc-research.com.\n\n"
  . "Study / condition: {$d['condition']}\n"
  . "I am a: {$d['role']}\n"
  . "Name: {$d['name']}\n"
  . "Phone: {$d['phone']}\n"
  . "Email: " . ($d['email'] ?: '(not given)') . "\n"
  . "ZIP: {$d['zip']}\n"
  . "Preferred language: {$langName}\n"
  . "Consent to eligibility review: yes\n"
  . "Submitted: {$d['time']}\n\n"
  . "Please call within one business day. A copy of every submission is also saved on the web server (qcr_leads/prescreen_leads.csv, outside the public site).\n";
$headers = "From: {$FROM}\r\n" . ($d['email'] ? "Reply-To: {$d['email']}\r\n" : '') . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: QCR-Prescreen";
$sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-fno-reply@qc-research.com');

echo json_encode(['ok' => true, 'mailed' => (bool)$sent]);
