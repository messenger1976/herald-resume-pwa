<?php
/**
 * GET api/csrf.php
 *
 * Public security bootstrap for the contact form. Mirrors
 * bodarepensionhouse's `api/inquiry/csrf` endpoint: hands out a CSRF token plus
 * the honeypot field name and the CAPTCHA/reCAPTCHA capabilities the server has.
 * Also rotates the CAPTCHA challenge so the widget starts with a fresh puzzle.
 */

declare(strict_types=1);

$runtime = require __DIR__ . '/bootstrap.php';

$security = new Security($runtime['config']);

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
if ($method === 'OPTIONS') {
	http_response_code(204);
	exit;
}
if ($method !== 'GET') {
	http_response_code(405);
	header('Allow: GET, OPTIONS');
	api_send_json(array('success' => false, 'message' => 'Method not allowed'));
}

$bootstrap = $security->public_bootstrap();

$captcha = array(
	'enabled' => (bool) $bootstrap['captcha_enabled'],
	'type' => '',
	'prompt' => '',
	'image_url' => '',
	'length' => 0,
);

if ($bootstrap['captcha_enabled']) {
	try {
		$captcha = array_merge(array('enabled' => true), $security->create_captcha());
	} catch (Throwable $e) {
		$security->log_event('captcha_issue_failed', array('error' => $e->getMessage()));
		$captcha = array(
			'enabled' => false,
			'type' => '',
			'prompt' => '',
			'image_url' => '',
			'length' => 0,
		);
	}
}

api_send_json(array(
	'success' => true,
	'csrf_token' => $bootstrap['csrf_token'],
	'honeypot_field' => $bootstrap['honeypot_field'],
	'captcha' => $captcha,
	'recaptcha_enabled' => $bootstrap['recaptcha_enabled'],
	'recaptcha_site_key' => $bootstrap['recaptcha_site_key'],
	'recaptcha_action' => $bootstrap['recaptcha_action'],
	// Kept for parity with the bodarepensionhouse response shape.
	'recaptcha_site_key_configured' => $bootstrap['recaptcha_site_key'] !== '',
));
