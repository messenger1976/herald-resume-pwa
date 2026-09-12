<?php
/**
 * CLI diagnostic for the contact pipeline.
 *
 *   php tools/test-contact.php                 # run all checks
 *   php tools/test-contact.php --send          # also attempt a real SMTP send
 *   php tools/test-contact.php --to=you@x.com  # override the recipient
 *
 * Verifies, without a browser: config loading, the CSRF token, the CAPTCHA
 * answer check, field validation, spam heuristics, ticket ids, on-disk storage,
 * and (optionally) actual email delivery.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('CLI only');
}

$apiDir = dirname(__DIR__) . '/api';

// CLI session handling: XAMPP's default session.save_path is not always writable
// by the CLI user, so keep diagnostic sessions inside api/storage (which is).
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');
if (is_dir($apiDir . '/storage') || @mkdir($apiDir . '/storage', 0775, true)) {
	ini_set('session.save_path', $apiDir . '/storage');
}

$runtime = require $apiDir . '/bootstrap.php';
require_once $apiDir . '/contact.php';

$config = $runtime['config'];
$security = new Security($config);

$send = in_array('--send', $argv, true);
$toOverride = '';
foreach ($argv as $argument) {
	if (strpos($argument, '--to=') === 0) {
		$toOverride = substr($argument, 5);
	}
}

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
	global $pass, $fail;
	if ($ok) {
		$pass++;
		echo "  [PASS] " . $label . ($detail !== '' ? " — " . $detail : "") . PHP_EOL;

		return;
	}
	$fail++;
	echo "  [FAIL] " . $label . ($detail !== '' ? " — " . $detail : "") . PHP_EOL;
}

echo PHP_EOL . "HFolio contact API diagnostics" . PHP_EOL;
echo str_repeat('-', 62) . PHP_EOL;

// ---------------------------------------------------------------------------
echo PHP_EOL . "Configuration" . PHP_EOL;
// ---------------------------------------------------------------------------
check('api/config.php loaded', is_file($apiDir . '/config.php'), is_file($apiDir . '/config.php') ? 'present' : 'missing — copy config.sample.php');
check('owner address (to_email)', trim((string) $config['to_email']) !== '', (string) $config['to_email']);
check('SMTP host', trim((string) $config['smtp']['smtp_host']) !== '', (string) $config['smtp']['smtp_host'] . ':' . $config['smtp']['smtp_port']);
check('SMTP password set', trim((string) $config['smtp']['smtp_pass']) !== '', $config['smtp']['smtp_pass'] === '' ? 'empty — email will not send yet' : 'configured');
check('storage writable', !empty($runtime['storage_writable']), $runtime['storage']);
check('built-in CAPTCHA enabled', $security->is_captcha_enabled(), implode(', ', (array) $config['captcha']['type']));
check('reCAPTCHA v3 configured', $security->is_recaptcha_configured(), $security->is_recaptcha_configured() ? 'active' : 'not configured (optional)');
check('GD available for CAPTCHA images', function_exists('imagecreatetruecolor'), function_exists('imagecreatetruecolor') ? 'yes' : 'no — SVG fallback will be used');

// ---------------------------------------------------------------------------
echo PHP_EOL . "CSRF token" . PHP_EOL;
// ---------------------------------------------------------------------------
// CSRF tokens are issued and verified in the same second during a CLI run, so the
// min_submit_seconds guard is exercised on its own instance below. The timestamp
// used by that guard comes from the signed token, not from $_SESSION.
$configNoDelay = $config;
$configNoDelay['min_submit_seconds'] = 0;
$fastSecurity = new Security($configNoDelay);

$token = $fastSecurity->issue_csrf_token();
check('token issued', $token !== '' && strlen($token) > 40);
$verify = $fastSecurity->verify_csrf($token);
check('round trip accepted', !empty($verify['ok']), isset($verify['message']) ? $verify['message'] : '');
check('single use (replay rejected)', empty($fastSecurity->verify_csrf($token)['ok']));

$tokenTwo = $fastSecurity->issue_csrf_token();
$tampered = substr($tokenTwo, 0, -4) . 'AAAA';
check('tampered token rejected', empty($fastSecurity->verify_csrf($tampered)['ok']));

// The real guard: a token submitted faster than min_submit_seconds is refused.
$tokenThree = $security->issue_csrf_token();
$tooFast = $security->verify_csrf($tokenThree);
check('too-fast submit rejected', empty($tooFast['ok']), 'min_submit_seconds=' . $config['min_submit_seconds'] . ' — ' . ($tooFast['message'] ?? ''));
check('too-fast attempt does not consume the token', isset($_SESSION['inquiry_csrf']), 'visitor can retry');

// ---------------------------------------------------------------------------
echo PHP_EOL . "CAPTCHA" . PHP_EOL;
// ---------------------------------------------------------------------------
$challenge = $security->create_captcha();
check('challenge created', !empty($challenge['success']), 'type=' . $challenge['type'] . ' prompt="' . $challenge['prompt'] . '"');
$stored = $_SESSION['inquiry_captcha']['answer'] ?? '';
$wrong = empty($security->verify_captcha('ZZZZZZ')['ok']);
check('wrong answer rejected', $wrong);
check('correct answer accepted', !empty($security->verify_captcha($stored)['ok']));
check('answer is single-use', empty($security->verify_captcha($stored)['ok']));

// A math challenge must still be drawable as an image.
$configMath = $config;
$configMath['captcha']['type'] = 'math';
$mathSecurity = new Security($configMath);
$mathSecurity->create_captcha();
$imageCode = $mathSecurity->captcha_image_code();
check('math challenge falls back to a drawable image code', strlen($imageCode) >= 4, 'code length ' . strlen($imageCode));

// ---------------------------------------------------------------------------
echo PHP_EOL . "Field validation" . PHP_EOL;
// ---------------------------------------------------------------------------
$valid = array(
	'name' => 'Juan Dela Cruz',
	'email' => 'Juan@Example.com',
	'subject' => 'Consulting inquiry',
	'message' => "Hello Herald,\nI would like to discuss a project.",
);
$clean = $security->sanitize_inquiry_fields($valid);
check('valid payload accepted', !empty($clean['ok']), isset($clean['message']) ? $clean['message'] : '');
check('email lower-cased', ($clean['data']['email'] ?? '') === 'juan@example.com');

$cases = array(
	'empty name' => array_merge($valid, array('name' => '')),
	'name with digits' => array_merge($valid, array('name' => 'Juan 123')),
	'bad email' => array_merge($valid, array('email' => 'not-an-email')),
	'empty subject' => array_merge($valid, array('subject' => '')),
	'empty message' => array_merge($valid, array('message' => '')),
	'oversized message' => array_merge($valid, array('message' => str_repeat('a', 5001))),
	'link spam' => array_merge($valid, array('message' => 'http://a.com http://b.com http://c.com www.d.com')),
	'header injection in subject' => array_merge($valid, array('subject' => "Hi\r\nBcc: evil@example.com")),
);
foreach ($cases as $label => $payload) {
	$result = $security->sanitize_inquiry_fields($payload);
	if ($label === 'header injection in subject') {
		// Accepted, but the CRLF must be stripped from the accepted value.
		$safeSubject = $result['ok'] ? $result['data']['subject'] : '';
		check($label . ' neutralised', $result['ok'] === true && strpos($safeSubject, "\n") === false && strpos($safeSubject, "\r") === false, '"' . $safeSubject . '"');
		continue;
	}
	check($label . ' rejected', empty($result['ok']), isset($result['message']) ? $result['message'] : '');
}

// ---------------------------------------------------------------------------
echo PHP_EOL . "Rate limiting" . PHP_EOL;
// ---------------------------------------------------------------------------
$limitIp = '203.0.113.250';
$rateFile = api_storage_dir() . '/rate_' . md5($limitIp) . '.json';
@unlink($rateFile);

$max = (int) $config['rate_limit_max'];
$allowed = 0;
for ($i = 0; $i < $max; $i++) {
	if (!empty($security->check_rate_limit($limitIp)['ok'])) {
		$allowed++;
		$security->record_attempt($limitIp);
	}
}
check('allows ' . $max . ' submissions then blocks', $allowed === $max, 'allowed=' . $allowed);
$blocked = $security->check_rate_limit($limitIp);
check('next submission blocked', empty($blocked['ok']), isset($blocked['message']) ? $blocked['message'] : '');
check('Retry-After provided', !empty($blocked['retry_after']), 'retry_after=' . ($blocked['retry_after'] ?? 0) . 's');
@unlink($rateFile);

$pendingIp = '203.0.113.251';
$pendingFile = api_storage_dir() . '/rate_' . md5($pendingIp) . '.json';
@unlink($pendingFile);
$security->record_attempt($pendingIp);
$stillAllowed = $security->check_rate_limit($pendingIp);
check('recorded attempts count toward the limit', !empty($stillAllowed['ok']) && $stillAllowed['remaining'] === $max - 1, 'remaining=' . ($stillAllowed['remaining'] ?? 'n/a'));
@unlink($pendingFile);

// ---------------------------------------------------------------------------
echo PHP_EOL . "Honeypot" . PHP_EOL;
// ---------------------------------------------------------------------------
check('empty honeypot passes', $security->check_honeypot(array($config['honeypot_field'] => '')));
$honeypotFilled = array($config['honeypot_field'] => 'http://spam.example');
check('filled honeypot caught', !$security->check_honeypot($honeypotFilled));

$honeypotOutcome = hfolio_handle_contact_request($config, array_merge($valid, array(
	$config['honeypot_field'] => 'http://spam.example',
)));
check('honeypot submit silently succeeds (no email)', !empty($honeypotOutcome['body']['success']) && $honeypotOutcome['body']['email_sent'] === false);

// ---------------------------------------------------------------------------
echo PHP_EOL . "Ticket ids + storage" . PHP_EOL;
// ---------------------------------------------------------------------------
$idOne = hfolio_next_ticket_id();
$idTwo = hfolio_next_ticket_id();
check('ticket id format', (bool) preg_match('/^HF-\d{8}-\d{4}-[0-9a-f]{4}$/', $idOne), $idOne);
check('ticket ids unique', $idOne !== $idTwo, $idTwo);

$stored = hfolio_store_inquiry(array(
	'ticket_id' => 'HF-DIAGNOSTIC-0001',
	'name' => 'Diagnostic',
	'email' => 'diagnostic@example.com',
	'subject' => 'Storage probe',
	'message' => 'Written by tools/test-contact.php',
	'created_at' => date('Y-m-d H:i:s'),
));
check('inquiry written to storage', $stored);
$storageFile = api_storage_dir() . '/inquiries/' . date('Y-m-d') . '_HF-DIAGNOSTIC-0001.json';
check('inquiry file exists', is_file($storageFile), $storageFile);
check('inquiry file is valid JSON', is_array(json_decode((string) @file_get_contents($storageFile), true)));

// ---------------------------------------------------------------------------
echo PHP_EOL . "Email rendering" . PHP_EOL;
// ---------------------------------------------------------------------------
$ownerHtml = hfolio_owner_email_html('HFolio', 'Juan Dela Cruz', 'juan@example.com', 'Consulting', "<script>alert(1)</script>\nLine two", $idOne, '', date('Y-m-d H:i:s'));
check('owner email escapes HTML', strpos($ownerHtml, '<script>') === false);
check('owner email keeps newlines', strpos($ownerHtml, 'Line two') !== false);
$ackHtml = hfolio_ack_email_html('HFolio', 'Juan Dela Cruz', 'Consulting', $idOne, date('Y-m-d H:i:s'));
check('ack email includes ticket id', strpos($ackHtml, $idOne) !== false);
$mailto = hfolio_mailto_link('herald@example.com', 'Consulting', 'Juan', 'juan@example.com', 'Body', $idOne);
check('mailto fallback built', strpos($mailto, 'mailto:herald@example.com') === 0 && strpos($mailto, rawurlencode($idOne)) !== false);

// ---------------------------------------------------------------------------
echo PHP_EOL . "Delivery pipeline" . PHP_EOL;
// ---------------------------------------------------------------------------
$deliverySecurity = new Security($config);
$outcome = hfolio_deliver_inquiry(
	$config,
	$deliverySecurity,
	array(
		'name' => 'Diagnostic Runner',
		'email' => 'diagnostic@example.com',
		'subject' => 'Pipeline probe',
		'phone' => '',
		'message' => 'This submission came from tools/test-contact.php.',
	),
	null
);
$body = $outcome['body'];
check('submission accepted', !empty($body['success']), 'ticket=' . ($body['ticket_id'] ?? ''));
check('message stored before email', !empty($body['stored']));
if (!empty($body['email_sent'])) {
	echo '  [PASS] email delivered through SMTP' . PHP_EOL;
	$pass++;
} else {
	echo '  [INFO] email not delivered yet: ' . ($body['mail_error'] ?? 'unknown reason') . PHP_EOL;
	echo '         The inquiry was still stored, and the API returned a mailto: fallback.' . PHP_EOL;
	check('mailto fallback offered to the visitor', !empty($body['mailto']));
}

if ($send) {
	echo PHP_EOL . "Live SMTP send" . PHP_EOL;
	$smtp = $config['smtp'];
	if ($toOverride !== '') {
		$smtp['smtp_user'] = $toOverride;
	}
	$mailer = new Mailer($smtp);
	$testTo = $toOverride !== '' ? $toOverride : (string) $config['to_email'];
	$result = $mailer->send_test($testTo);
	check('SMTP test to ' . $testTo, !empty($result['success']), $result['message']);
	if (empty($result['success'])) {
		foreach ($result['transcript'] as $line) {
			echo '         ' . $line . PHP_EOL;
		}
	}
}

// ---------------------------------------------------------------------------
echo PHP_EOL . str_repeat('-', 62) . PHP_EOL;
echo "Result: {$pass} passed, {$fail} failed" . PHP_EOL;
echo PHP_EOL;

exit($fail > 0 ? 1 : 0);
