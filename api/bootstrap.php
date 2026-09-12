<?php
/**
 * Shared bootstrap for the HFolio contact API.
 *
 * Mirrors the role of bodarepensionhouse's `Form_security` + `Coop_mail` setup:
 * a plain-PHP equivalent (no framework) so it can run as-is under XAMPP.
 *
 * Usage from an endpoint:
 *   $runtime = require __DIR__ . '/bootstrap.php';
 *   $security = new Security($runtime['config']);
 */

declare(strict_types=1);

if (defined('HFOLIO_API_BOOTSTRAPPED')) {
	return $GLOBALS['HFOLIO_RUNTIME'];
}
define('HFOLIO_API_BOOTSTRAPPED', 1);

const HFOLIO_API_DIR = __DIR__;
define('HFOLIO_STORAGE_DIR', HFOLIO_API_DIR . DIRECTORY_SEPARATOR . 'storage');

// ---------------------------------------------------------------------------
// JSON responses + safe error handling (never leak paths/secrets to clients)
// ---------------------------------------------------------------------------

if (!function_exists('api_send_json')) {
	/**
	 * Emit a JSON response and stop.
	 *
	 * @param array<string,mixed> $payload
	 */
	function api_send_json(array $payload, int $status = 200): void
	{
		if (!headers_sent()) {
			http_response_code($status);
			header('Content-Type: application/json; charset=utf-8');
			header('X-Content-Type-Options: nosniff');
			header('Cache-Control: no-store, no-cache, must-revalidate');
			header('Pragma: no-cache');
		}

		echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}
}

if (!function_exists('api_read_json_body')) {
	/**
	 * Decode the request body as JSON, falling back to form-encoded input.
	 *
	 * @return array<string,mixed>
	 */
	function api_read_json_body(): array
	{
		$raw = file_get_contents('php://input');
		$data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

		if (!is_array($data)) {
			$data = $_POST;
		}

		return is_array($data) ? $data : array();
	}
}

if (!function_exists('api_client_ip')) {
	/**
	 * Client IP for rate limiting.
	 *
	 * REMOTE_ADDR is used on purpose — X-Forwarded-For is spoofable and must
	 * only be trusted behind a proxy we control.
	 */
	function api_client_ip(): string
	{
		return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
	}
}

if (!function_exists('api_client_ua')) {
	function api_client_ua(): string
	{
		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

		return substr($ua, 0, 255);
	}
}

if (!function_exists('api_is_local_request')) {
	/**
	 * True for localhost / private-range callers. Used to gate the SMTP
	 * self-test endpoint and its diagnostics.
	 */
	function api_is_local_request(): bool
	{
		$ip = api_client_ip();

		return in_array($ip, array('127.0.0.1', '::1', 'localhost'), true)
			|| strpos($ip, '192.168.') === 0
			|| strpos($ip, '10.') === 0
			|| (bool) preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip);
	}
}

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

if (!function_exists('api_merge_config')) {
	/**
	 * Recursively overlay $override on top of $base (later wins).
	 *
	 * @param array<string,mixed> $base
	 * @param array<string,mixed> $override
	 * @return array<string,mixed>
	 */
	function api_merge_config(array $base, array $override): array
	{
		foreach ($override as $key => $value) {
			if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
				$base[$key] = api_merge_config($base[$key], $value);
				continue;
			}
			$base[$key] = $value;
		}

		return $base;
	}
}

if (!function_exists('api_load_config')) {
	/**
	 * @return array<string,mixed>
	 */
	function api_load_config(): array
	{
		$configFile = HFOLIO_API_DIR . '/config.php';
		$config = array();

		if (is_file($configFile)) {
			$loaded = require $configFile;
			if (is_array($loaded)) {
				$config = $loaded;
			}
		}

		// Optional per-machine override. config.php is committed and deployed, so
		// this file is where real secrets live: it is gitignored and excluded from
		// the FTP sync, meaning a deploy can never overwrite a live password.
		$localFile = HFOLIO_API_DIR . '/config.local.php';
		if (is_file($localFile)) {
			$local = require $localFile;
			if (is_array($local)) {
				$config = api_merge_config($config, $local);
			}
		}

		// Defaults keep the form functional even before config.php is filled in.
		$defaults = array(
			'to_email' => '',
			'to_name' => '',
			'site_name' => 'HFolio',
			'site_url' => '',
			'mail_profile_name' => 'Contact',

			'smtp' => array(),
			'smtp_fallback' => array(),

			'captcha' => array(
				'enabled' => true,
				'type' => 'both',
				'length' => 5,
				'ttl' => 900,
				'case_sensitive' => false,
			),

			'recaptcha_enabled' => false,
			'recaptcha_site_key' => '',
			'recaptcha_secret_key' => '',
			'recaptcha_min_score' => 0.5,
			'recaptcha_expected_action' => 'contact_submit',

			'csrf_ttl' => 7200,
			'min_submit_seconds' => 2,
			'rate_limit_max' => 5,
			'rate_limit_window' => 900,
			'rate_limit_block_seconds' => 1800,
			'honeypot_field' => 'company_url',
			'log_all_submissions' => true,
		);

		$config = array_merge($defaults, $config);

		$smtpDefaults = array(
			'protocol' => 'smtp',
			'smtp_host' => 'smtp.mail.yahoo.com',
			'smtp_port' => 587,
			'smtp_user' => '',
			'smtp_pass' => '',
			'smtp_crypto' => 'tls',
			'smtp_timeout' => 20,
			'from_email' => '',
			'from_name' => '',
			'mailtype' => 'html',
			'charset' => 'utf-8',
			'is_active' => true,
		);
		$config['smtp'] = array_merge($smtpDefaults, is_array($config['smtp']) ? $config['smtp'] : array());
		$config['smtp_fallback'] = array_merge(array(), is_array($config['smtp_fallback']) ? $config['smtp_fallback'] : array());

		$captchaDefaults = array(
			'enabled' => true,
			'type' => 'both',
			'length' => 5,
			'ttl' => 900,
			'case_sensitive' => false,
		);
		$config['captcha'] = array_merge($captchaDefaults, is_array($config['captcha']) ? $config['captcha'] : array());

		$length = (int) $config['captcha']['length'];
		$config['captcha']['length'] = ($length >= 4 && $length <= 8) ? $length : 5;

		if ($config['to_email'] === '' && !empty($config['smtp']['smtp_user'])) {
			$config['to_email'] = $config['smtp']['smtp_user'];
		}
		if ($config['smtp']['from_email'] === '') {
			$config['smtp']['from_email'] = (string) $config['smtp']['smtp_user'];
		}
		if ($config['smtp']['from_name'] === '') {
			$config['smtp']['from_name'] = (string) $config['site_name'];
		}

		return $config;
	}
}

// ---------------------------------------------------------------------------
// Storage
// ---------------------------------------------------------------------------

if (!function_exists('api_storage_dir')) {
	function api_storage_dir(): string
	{
		if (!is_dir(HFOLIO_STORAGE_DIR)) {
			@mkdir(HFOLIO_STORAGE_DIR, 0775, true);
		}

		return HFOLIO_STORAGE_DIR;
	}
}

if (!function_exists('api_storage_writable')) {
	function api_storage_writable(): bool
	{
		return is_dir(api_storage_dir()) && is_writable(api_storage_dir());
	}
}

if (!function_exists('api_integrity_secret')) {
	/**
	 * Stateless CSRF/replay signing key. Generated once and stored outside the
	 * web root when possible, otherwise inside api/storage (which .htaccess
	 * denies over HTTP).
	 */
	function api_integrity_secret(): string
	{
		$file = api_storage_dir() . '/.secret';
		if (is_file($file)) {
			$existing = trim((string) @file_get_contents($file));
			if (strlen($existing) >= 32) {
				return $existing;
			}
		}

		try {
			$secret = bin2hex(random_bytes(32));
		} catch (Throwable $e) {
			$secret = hash('sha256', __FILE__ . microtime(true) . mt_rand());
		}

		@file_put_contents($file, $secret, LOCK_EX);
		@chmod($file, 0600);

		return $secret;
	}
}

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

$hfConfig = api_load_config();
$hfRuntime = array(
	'config' => $hfConfig,
	'storage' => api_storage_dir(),
	'storage_writable' => api_storage_writable(),
);

// Fatal errors must still answer JSON instead of dumping HTML into the
// response body (same reason bodarepensionhouse wraps sends in ob_start()).
$hfPreviousHandler = set_exception_handler(function ($e) use ($hfRuntime) {
	error_log('[hfolio-contact] Uncaught: ' . $e->getMessage());
	api_send_json(array(
		'success' => false,
		'message' => 'We encountered an unexpected issue. Please email me directly at the address on the contact page.',
	), 500);
});
unset($hfPreviousHandler);

$hfStorageWritable = $hfRuntime['storage_writable'];
register_shutdown_function(function () use ($hfStorageWritable) {
	$error = error_get_last();
	if (!$error || !in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
		return;
	}
	error_log('[hfolio-contact] Fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
	if (!headers_sent()) {
		http_response_code(500);
		header('Content-Type: application/json; charset=utf-8');
	}
	echo json_encode(array(
		'success' => false,
		'message' => 'We encountered an unexpected issue. Please email me directly at the address on the contact page.',
	));
});

// Session cookie is the CSRF anchor: strict same-site, http-only.
if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
	if (PHP_SAPI === 'cli') {
		// CLI diagnostics have no cookies and may print before bootstrapping, so
		// a session failure here is harmless: $_SESSION stays usable in memory.
		@session_start();
	} else {
		session_name('HFOLIOSESSID');
		session_set_cookie_params(array(
			'lifetime' => 0,
			'path' => '/',
			'domain' => '',
			'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
			'httponly' => true,
			'samesite' => 'Lax',
		));
		@session_start();
	}
}

foreach (array('Security.php', 'Mailer.php') as $hfLibrary) {
	$hfPath = HFOLIO_API_DIR . '/lib/' . $hfLibrary;
	if (is_file($hfPath)) {
		require_once $hfPath;
	}
}

$GLOBALS['HFOLIO_RUNTIME'] = $hfRuntime;

return $hfRuntime;
