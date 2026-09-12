<?php
/**
 * GET api/health.php
 *
 * Quick diagnostic for the contact form backend. Answers "is the API ready?"
 * without exposing secrets: no passwords, keys, or stored messages are echoed.
 *
 * Blocked by api/.htaccess, so it is only reachable from this machine — remove
 * the `health.php` entry from that FilesMatch rule to publish it.
 */

declare(strict_types=1);

$runtime = require __DIR__ . '/bootstrap.php';

$config = $runtime['config'];
$security = new Security($config);
$smtp = is_array($config['smtp']) ? $config['smtp'] : array();

$checks = array();

$checks['config_file'] = array(
	'ok' => is_file(__DIR__ . '/config.php'),
	'detail' => is_file(__DIR__ . '/config.php') ? 'api/config.php present' : 'copy api/config.sample.php to api/config.php',
);
$checks['smtp_host'] = array(
	'ok' => trim((string) ($smtp['smtp_host'] ?? '')) !== '',
	'detail' => (string) ($smtp['smtp_host'] ?? '') . ':' . (int) ($smtp['smtp_port'] ?? 0) . ' (' . ((string) ($smtp['smtp_crypto'] ?? '')) . ')',
);
$checks['smtp_credentials'] = array(
	'ok' => trim((string) ($smtp['smtp_user'] ?? '')) !== '' && trim((string) ($smtp['smtp_pass'] ?? '')) !== '',
	'detail' => trim((string) ($smtp['smtp_pass'] ?? '')) === ''
		? 'smtp_pass is empty — messages will be stored but not emailed'
		: 'credentials set',
);
$checks['owner_address'] = array(
	'ok' => trim((string) $config['to_email']) !== '',
	'detail' => (string) $config['to_email'],
);
$checks['storage'] = array(
	'ok' => !empty($runtime['storage_writable']),
	'detail' => (string) $runtime['storage'],
);
$checks['captcha'] = array(
	'ok' => $security->is_captcha_enabled(),
	'detail' => 'built-in ' . (string) $config['captcha']['type'] . ' challenge'
		. (function_exists('imagecreatetruecolor') ? ' (PNG)' : ' (GD missing — SVG fallback)'),
);
$checks['recaptcha'] = array(
	'ok' => true, // optional
	'detail' => $security->is_recaptcha_configured() ? 'reCAPTCHA v3 active' : 'reCAPTCHA v3 not configured (optional)',
);

$ready = true;
foreach ($checks as $key => $check) {
	if ($key === 'recaptcha') {
		continue;
	}
	if (empty($check['ok'])) {
		$ready = false;
	}
}

api_send_json(array(
	'success' => true,
	'ready' => $ready,
	'php_version' => PHP_VERSION,
	'checks' => $checks,
	'endpoints' => array(
		'csrf' => 'api/csrf.php',
		'captcha' => 'api/captcha.php',
		'contact' => 'api/contact.php',
		'self_test' => 'api/test-mail.php',
	),
	'next_step' => $ready
		? 'Contact form is ready. Send a test message from contact.html.'
		: 'Add the missing values in api/config.php, then reload this page.',
));
