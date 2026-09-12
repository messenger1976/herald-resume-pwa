<?php
/**
 * Security — plain-PHP port of bodarepensionhouse's `Form_security` library.
 *
 * Provides, for the public contact form:
 *   - CSRF tokens (signed, session-bound, single use, TTL + minimum age)
 *   - honeypot field check
 *   - per-IP rate limiting with a block window
 *   - self-hosted image / math CAPTCHA (no third-party keys required)
 *   - Google reCAPTCHA v3 verification (optional, skipped when unconfigured)
 *   - field sanitization + light spam heuristics
 *   - JSONL event logging
 */

declare(strict_types=1);

final class Security
{
	/** @var array<string,mixed> */
	protected $settings = array();

	protected $storage;
	protected $captcha = array('image', 'math');
	protected $last_captcha_type = 'image';

	/** @param array<string,mixed> $settings */
	public function __construct(array $settings)
	{
		$this->settings = $settings;
		$this->storage = api_storage_dir();

		$type = isset($settings['captcha']['type']) ? (string) $settings['captcha']['type'] : 'both';
		if ($type === 'image') {
			$this->captcha = array('image');
		} elseif ($type === 'math') {
			$this->captcha = array('math');
		} else {
			$this->captcha = array('image', 'math');
		}
	}

	/** @return array<string,mixed> */
	public function get_settings(): array
	{
		return $this->settings;
	}

	public function honeypot_field(): string
	{
		return (string) $this->settings['honeypot_field'];
	}

	private function setting(string $key, $default = null)
	{
		return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
	}

	// -----------------------------------------------------------------------
	// Capability bootstrap (mirrors Form_security::public_bootstrap)
	// -----------------------------------------------------------------------

	public function is_recaptcha_configured(): bool
	{
		return !empty($this->settings['recaptcha_enabled'])
			&& (string) $this->settings['recaptcha_site_key'] !== ''
			&& (string) $this->settings['recaptcha_secret_key'] !== '';
	}

	public function is_captcha_enabled(): bool
	{
		return !empty($this->settings['captcha']['enabled']);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function public_bootstrap(?string $action = null): array
	{
		$action = ($action !== null && $action !== '')
			? $action
			: (string) $this->settings['recaptcha_expected_action'];

		return array(
			'success' => true,
			'csrf_token' => $this->issue_csrf_token(),
			'honeypot_field' => $this->honeypot_field(),
			'captcha_enabled' => $this->is_captcha_enabled(),
			'captcha_types' => $this->captcha,
			'captcha_image_url' => $this->is_captcha_enabled() ? 'api/captcha.php' : '',
			'recaptcha_enabled' => $this->is_recaptcha_configured(),
			'recaptcha_site_key' => $this->is_recaptcha_configured() ? (string) $this->settings['recaptcha_site_key'] : '',
			'recaptcha_action' => $action,
		);
	}

	// -----------------------------------------------------------------------
	// CSRF
	// -----------------------------------------------------------------------

	public function issue_csrf_token(): string
	{
		try {
			$token = bin2hex(random_bytes(32));
		} catch (Throwable $e) {
			$token = hash('sha256', uniqid('csrf', true) . microtime(true));
		}

		$issuedAt = time();
		$_SESSION['inquiry_csrf'] = array(
			'token' => $token,
			'issued_at' => $issuedAt,
			'sid' => $this->session_fingerprint(),
		);

		$payload = $issuedAt . '.' . $token;

		return $this->base64url_encode($payload . '.' . $this->sign($payload));
	}

	/**
	 * @return array{ok:bool,message?:string}
	 */
	public function verify_csrf($token): array
	{
		$session = isset($_SESSION['inquiry_csrf']) ? $_SESSION['inquiry_csrf'] : null;
		if (!is_array($session) || empty($session['token']) || empty($session['issued_at'])) {
			return $this->fail('Security token missing or expired. Please refresh the page and try again.');
		}

		// Token must belong to this browser session.
		if (!isset($session['sid']) || !hash_equals((string) $session['sid'], $this->session_fingerprint())) {
			unset($_SESSION['inquiry_csrf']);

			return $this->fail('Security token does not match this session. Please refresh the page and try again.');
		}

		$decoded = $this->base64url_decode((string) $token);
		$parts = $decoded !== null ? explode('.', $decoded) : array();
		if (count($parts) !== 3) {
			return $this->fail('Invalid security token. Please refresh the page and try again.');
		}

		list($issuedAt, $rawToken, $signature) = $parts;
		$issuedAt = (int) $issuedAt;

		$ttl = (int) $this->setting('csrf_ttl', 7200);
		if ((time() - $issuedAt) > $ttl) {
			unset($_SESSION['inquiry_csrf']);

			return $this->fail('Security token expired. Please refresh the page and try again.');
		}

		// Blocks "submit-in-0.1s" bots. The front end issues a fresh token on load,
		// so a human never hits this.
		$minSeconds = (int) $this->setting('min_submit_seconds', 2);
		if ($minSeconds > 0 && (time() - $issuedAt) < $minSeconds) {
			$this->log_event('csrf_too_fast', array('ip' => api_client_ip()));

			return $this->fail('Please wait a moment and try again.');
		}

		if (!hash_equals((string) $session['token'], (string) $rawToken)) {
			$this->log_event('csrf_mismatch', array('ip' => api_client_ip()));

			return $this->fail('Invalid security token. Please refresh the page and try again.');
		}

		if (!hash_equals($this->sign($issuedAt . '.' . $rawToken), (string) $signature)) {
			$this->log_event('csrf_bad_signature', array('ip' => api_client_ip()));

			return $this->fail('Invalid security token. Please refresh the page and try again.');
		}

		if ($this->token_was_used($signature)) {
			$this->log_event('csrf_replay', array('ip' => api_client_ip()));

			return $this->fail('This form was already submitted. Please refresh the page to send another message.');
		}

		// One-time use: consume the token and remember the signature.
		unset($_SESSION['inquiry_csrf']);
		$this->remember_used_token($signature);

		return array('ok' => true);
	}

	public function check_honeypot($data): bool
	{
		$field = $this->honeypot_field();
		$value = isset($data[$field]) ? trim((string) $data[$field]) : '';
		if ($value !== '') {
			$this->log_event('honeypot_triggered', array('ip' => api_client_ip(), 'field' => $field));

			return false;
		}

		return true;
	}

	// -----------------------------------------------------------------------
	// CAPTCHA
	// -----------------------------------------------------------------------

	/**
	 * Create a fresh challenge, store its answer in the session and return the
	 * client-facing instructions.
	 *
	 * @return array{success:bool,type:string,prompt:string,image_url:string,length:int}
	 */
	public function create_captcha(): array
	{
		$type = $this->captcha[array_rand($this->captcha)];
		$this->last_captcha_type = $type;
		$ttl = isset($this->settings['captcha']['ttl']) ? (int) $this->settings['captcha']['ttl'] : 900;

		if ($type === 'math') {
			$left = random_int(2, 9);
			$right = random_int(2, 9);
			$answer = (string) ($left + $right);
			$prompt = 'What is ' . $left . ' + ' . $right . '?';
			$challenge = array('type' => 'math', 'answer' => $answer);
		} else {
			$code = $this->random_code((int) $this->settings['captcha']['length']);
			$prompt = 'Type the characters shown in the image.';
			$challenge = array('type' => 'image', 'answer' => $code);
		}

		$challenge['issued_at'] = time();
		$challenge['expires_at'] = time() + $ttl;
		$challenge['attempts'] = 0;
		$_SESSION['inquiry_captcha'] = $challenge;

		return array(
			'success' => true,
			'type' => $type,
			'prompt' => $prompt,
			'image_url' => $type === 'image' ? 'api/captcha.php' : '',
			'length' => $type === 'image' ? (int) $this->settings['captcha']['length'] : 0,
		);
	}

	/**
	 * The code currently stored for this session, used while rendering the PNG.
	 */
	public function captcha_image_code(): string
	{
		if (!isset($_SESSION['inquiry_captcha'])) {
			$this->create_captcha();
		}

		$challenge = isset($_SESSION['inquiry_captcha']) ? $_SESSION['inquiry_captcha'] : null;
		if (is_array($challenge) && $challenge['type'] === 'image' && !empty($challenge['answer'])) {
			return (string) $challenge['answer'];
		}

		// The stored challenge is a math question (or missing): replace it with a
		// drawable image challenge so the <img> always renders something solvable.
		$code = $this->random_code((int) $this->settings['captcha']['length']);
		$_SESSION['inquiry_captcha'] = array(
			'type' => 'image',
			'answer' => $code,
			'issued_at' => time(),
			'expires_at' => time() + (int) $this->settings['captcha']['ttl'],
			'attempts' => 0,
		);

		return $code;
	}

	/**
	 * @return array{ok:bool,message?:string}
	 */
	public function verify_captcha($answer): array
	{
		if (!$this->is_captcha_enabled()) {
			return array('ok' => true);
		}

		$challenge = isset($_SESSION['inquiry_captcha']) ? $_SESSION['inquiry_captcha'] : null;
		if (!is_array($challenge) || empty($challenge['answer'])) {
			return $this->fail('Please reload the security check and try again.');
		}

		if (!empty($challenge['expires_at']) && (int) $challenge['expires_at'] < time()) {
			unset($_SESSION['inquiry_captcha']);

			return $this->fail('The security check expired. Please reload it and try again.');
		}

		$attempts = isset($challenge['attempts']) ? (int) $challenge['attempts'] + 1 : 1;
		$_SESSION['inquiry_captcha']['attempts'] = $attempts;

		if ($attempts > 6) {
			unset($_SESSION['inquiry_captcha']);
			$this->log_event('captcha_too_many_attempts', array('ip' => api_client_ip()));

			return $this->fail('Too many incorrect security codes. Please reload the check and try again.');
		}

		$expected = (string) $challenge['answer'];
		$given = trim((string) $answer);

		if (!empty($this->settings['captcha']['case_sensitive'])) {
			$matches = hash_equals($expected, $given);
		} else {
			$matches = hash_equals(strtolower($expected), strtolower($given));
		}

		if (!$matches) {
			$this->log_event('captcha_failed', array('ip' => api_client_ip(), 'attempts' => $attempts));

			return $this->fail('Incorrect security code. Please try again.');
		}

		// Answered correctly — burn it so the same code cannot be replayed.
		unset($_SESSION['inquiry_captcha']);

		return array('ok' => true);
	}

	private function random_code(int $length): string
	{
		// Ambiguous glyphs (O/0, I/1/L) are excluded so humans can read the image.
		$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		$max = strlen($alphabet) - 1;
		$code = '';
		for ($i = 0; $i < $length; $i++) {
			$code .= $alphabet[random_int(0, $max)];
		}

		return $code;
	}

	// -----------------------------------------------------------------------
	// Google reCAPTCHA v3
	// -----------------------------------------------------------------------

	/**
	 * @return array{ok:bool,message?:string,score?:float,skipped?:bool}
	 */
	public function verify_recaptcha($token, ?string $remoteIp = null, ?string $expectedAction = null): array
	{
		if (!$this->is_recaptcha_configured()) {
			return array('ok' => true, 'skipped' => true);
		}

		if (!is_string($token) || $token === '') {
			return $this->fail('CAPTCHA verification failed. Please refresh and try again.');
		}

		$remoteIp = $remoteIp !== null ? $remoteIp : api_client_ip();
		$payload = http_build_query(array(
			'secret' => (string) $this->settings['recaptcha_secret_key'],
			'response' => $token,
			'remoteip' => $remoteIp,
		));

		$response = $this->http_post('https://www.google.com/recaptcha/api/siteverify', $payload);
		if ($response === false) {
			$this->log_event('recaptcha_unreachable', array('ip' => $remoteIp));

			return $this->fail('CAPTCHA verification is temporarily unavailable. Please try again.');
		}

		$result = json_decode($response, true);
		if (!is_array($result) || empty($result['success'])) {
			$this->log_event('recaptcha_failed', array('ip' => $remoteIp, 'raw' => $result));

			return $this->fail('CAPTCHA verification failed. Please try again.');
		}

		$score = isset($result['score']) ? (float) $result['score'] : 0.0;
		$action = isset($result['action']) ? (string) $result['action'] : '';
		$minScore = (float) $this->settings['recaptcha_min_score'];
		$expectedAction = $expectedAction !== null
			? $expectedAction
			: (string) $this->settings['recaptcha_expected_action'];

		if ($expectedAction !== '' && $action !== $expectedAction) {
			$this->log_event('recaptcha_bad_action', array(
				'ip' => $remoteIp,
				'action' => $action,
				'expected' => $expectedAction,
				'score' => $score,
			));

			return $this->fail('CAPTCHA verification failed. Please try again.');
		}

		if ($score < $minScore) {
			$this->log_event('recaptcha_low_score', array('ip' => $remoteIp, 'score' => $score));

			return $this->fail('Unable to verify you are human. Please try again later.');
		}

		return array('ok' => true, 'score' => $score);
	}

	// -----------------------------------------------------------------------
	// Rate limiting
	// -----------------------------------------------------------------------

	/**
	 * Charge one attempt against this IP. Called only after every security check
	 * has passed, so a visitor who mistypes the CAPTCHA is never throttled while
	 * a scripted attacker still runs out of budget.
	 */
	public function record_attempt(?string $ip = null): void
	{
		$this->store_attempt($ip !== null ? $ip : api_client_ip());
	}

	/**
	 * @return array{ok:bool,retry_after?:int,message?:string,remaining?:int}
	 */
	public function check_rate_limit(?string $ip = null): array
	{
		$ip = $ip !== null ? $ip : api_client_ip();
		$ip = (string) preg_replace('/[^a-zA-Z0-9\.:_-]/', '_', $ip);
		$file = $this->storage . '/rate_' . md5($ip) . '.json';
		$now = time();
		$window = (int) $this->setting('rate_limit_window', 900);
		$max = (int) $this->setting('rate_limit_max', 5);
		$blockSeconds = (int) $this->setting('rate_limit_block_seconds', 1800);

		$state = array('attempts' => array(), 'blocked_until' => 0);
		if (is_file($file)) {
			$decoded = json_decode((string) @file_get_contents($file), true);
			if (is_array($decoded)) {
				$state = array_merge($state, $decoded);
			}
		}

		if (!empty($state['blocked_until']) && (int) $state['blocked_until'] > $now) {
			$retry = (int) $state['blocked_until'] - $now;
			$this->log_event('rate_limit_blocked', array('ip' => $ip, 'retry_after' => $retry));

			return array(
				'ok' => false,
				'retry_after' => $retry,
				'message' => 'Too many submissions. Please try again in ' . $this->human_duration($retry) . '.',
			);
		}

		$attempts = $this->recent_attempts($state, $now - $window);

		if ($max > 0 && count($attempts) >= $max) {
			$state['attempts'] = $attempts;
			$state['blocked_until'] = $now + $blockSeconds;
			@file_put_contents($file, json_encode($state), LOCK_EX);
			$this->log_event('rate_limit_exceeded', array('ip' => $ip, 'count' => count($attempts)));

			return array(
				'ok' => false,
				'retry_after' => $blockSeconds,
				'message' => 'Too many submissions. Please try again in ' . $this->human_duration($blockSeconds) . '.',
			);
		}

		return array('ok' => true, 'remaining' => $max > 0 ? max(0, $max - count($attempts)) : null);
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<int,int>
	 */
	private function recent_attempts(array $state, int $since): array
	{
		$attempts = array();
		if (!empty($state['attempts']) && is_array($state['attempts'])) {
			foreach ($state['attempts'] as $timestamp) {
				$timestamp = (int) $timestamp;
				if ($timestamp >= $since) {
					$attempts[] = $timestamp;
				}
			}
		}

		return $attempts;
	}

	private function store_attempt(string $ip): void
	{
		$ip = (string) preg_replace('/[^a-zA-Z0-9\.:_-]/', '_', $ip);
		$file = $this->storage . '/rate_' . md5($ip) . '.json';
		$now = time();
		$window = (int) $this->setting('rate_limit_window', 900);

		$state = array('attempts' => array(), 'blocked_until' => 0);
		if (is_file($file)) {
			$decoded = json_decode((string) @file_get_contents($file), true);
			if (is_array($decoded)) {
				$state = array_merge($state, $decoded);
			}
		}

		$attempts = $this->recent_attempts($state, $now - $window);
		$attempts[] = $now;

		// Opportunistic cleanup so one hot IP cannot grow the file forever.
		if (count($attempts) > 500) {
			$attempts = array_slice($attempts, -500);
		}

		$state['attempts'] = $attempts;
		$state['blocked_until'] = 0;
		@file_put_contents($file, json_encode($state), LOCK_EX);
	}

	// -----------------------------------------------------------------------
	// Field sanitization
	// -----------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $data
	 * @return array{ok:bool,message?:string,data?:array<string,string>}
	 */
	public function sanitize_inquiry_fields(array $data): array
	{
		$name = isset($data['name']) ? trim(strip_tags((string) $data['name'])) : '';
		$email = isset($data['email']) ? trim(strtolower(strip_tags((string) $data['email']))) : '';
		$subject = isset($data['subject']) ? trim(strip_tags((string) $data['subject'])) : '';
		$phone = isset($data['phone']) ? trim(strip_tags((string) $data['phone'])) : '';
		$message = isset($data['message']) ? trim(strip_tags((string) $data['message'])) : '';

		// Normalize newlines and strip control characters that break mail headers.
		$message = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message);
		$subject = (string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $subject);
		$name = (string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $name);

		if ($name === '' || $this->str_len($name) > 150) {
			return $this->fail('Please enter a valid name.');
		}
		if (!preg_match("/^[\\p{L}\\p{M}'\\-\\.\\s]+$/u", $name)) {
			return $this->fail('Name contains invalid characters.');
		}
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
			return $this->fail('Please enter a valid email address.');
		}
		if ($subject === '' || $this->str_len($subject) > 255) {
			return $this->fail('Please enter a valid subject.');
		}
		if ($message === '' || $this->str_len($message) > 5000) {
			return $this->fail('Please enter a valid message.');
		}
		if ($phone !== '' && ($this->str_len($phone) > 50 || !preg_match('/^[0-9+()\s.\-]{7,50}$/', $phone))) {
			return $this->fail('Please enter a valid phone number.');
		}

		$urlCount = preg_match_all('/https?:\/\/|www\./i', $message . ' ' . $subject);
		if ($urlCount !== false && $urlCount > 3) {
			$this->log_event('spam_url_density', array('ip' => api_client_ip(), 'urls' => $urlCount));

			return $this->fail('Your message looks like spam. Please reduce links and try again.');
		}

		return array(
			'ok' => true,
			'data' => array(
				'name' => $name,
				'email' => $email,
				'subject' => $subject,
				'phone' => $phone,
				'message' => $message,
			),
		);
	}

	// -----------------------------------------------------------------------
	// Logging
	// -----------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $context
	 */
	public function log_event(string $event, array $context = array()): void
	{
		$line = array(
			'time' => date('c'),
			'event' => $event,
			'context' => $context,
			'ip' => api_client_ip(),
			'ua' => api_client_ua(),
		);

		error_log('[hfolio-contact] ' . $event . ' ' . json_encode($context));
		@file_put_contents(
			$this->storage . '/events.log',
			json_encode($line, JSON_UNESCAPED_SLASHES) . PHP_EOL,
			FILE_APPEND | LOCK_EX
		);
	}

	// -----------------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------------

	private function str_len(string $value): int
	{
		return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	private function fail(string $message): array
	{
		return array('ok' => false, 'message' => $message);
	}

	private function human_duration(int $seconds): string
	{
		if ($seconds < 60) {
			return $seconds . ' seconds';
		}
		$minutes = (int) ceil($seconds / 60);
		if ($minutes < 60) {
			return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
		}
		$hours = (int) ceil($minutes / 60);

		return $hours . ' hour' . ($hours === 1 ? '' : 's');
	}

	private function session_fingerprint(): string
	{
		return hash('sha256', api_integrity_secret() . '|' . session_id());
	}

	private function sign(string $payload): string
	{
		return hash_hmac('sha256', $payload, api_integrity_secret());
	}

	private function base64url_encode(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private function base64url_decode(string $value): ?string
	{
		$normalized = strtr($value, '-_', '+/');
		$padded = str_pad($normalized, (int) (ceil(strlen($normalized) / 4) * 4), '=');
		$decoded = base64_decode($padded, true);

		return is_string($decoded) ? $decoded : null;
	}

	private function token_was_used(string $signature): bool
	{
		$used = $this->read_used_tokens();
		$signature = substr($signature, 0, 32);

		return isset($used[$signature]);
	}

	private function remember_used_token(string $signature): void
	{
		$file = $this->storage . '/used_tokens.json';
		$used = $this->read_used_tokens();
		$used[substr($signature, 0, 32)] = time();

		// Keep the file small: only tokens from the last 2 hours matter.
		$cutoff = time() - 7200;
		foreach ($used as $key => $timestamp) {
			if ((int) $timestamp < $cutoff) {
				unset($used[$key]);
			}
		}

		@file_put_contents($file, json_encode($used), LOCK_EX);
	}

	/**
	 * @return array<string,int>
	 */
	private function read_used_tokens(): array
	{
		$file = $this->storage . '/used_tokens.json';
		if (!is_file($file)) {
			return array();
		}
		$decoded = json_decode((string) @file_get_contents($file), true);

		return is_array($decoded) ? $decoded : array();
	}

	/**
	 * POST to Google's siteverify endpoint with cURL, falling back to streams.
	 *
	 * @return string|false
	 */
	private function http_post(string $url, string $body)
	{
		if (function_exists('curl_init')) {
			$handle = curl_init($url);
			curl_setopt_array($handle, array(
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => 8,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
			));
			$result = curl_exec($handle);
			$status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
			curl_close($handle);
			if ($result === false || $status < 200 || $status >= 300) {
				return false;
			}

			return $result;
		}

		$context = stream_context_create(array(
			'http' => array(
				'method' => 'POST',
				'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
				'content' => $body,
				'timeout' => 8,
				'ignore_errors' => true,
			),
		));
		$result = @file_get_contents($url, false, $context);

		return $result === false ? false : $result;
	}
}
