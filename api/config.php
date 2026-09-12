<?php
/**
 * Contact API configuration — SAFE TO COMMIT.
 *
 * This file is in the repository and is deployed over FTP, so it must never
 * contain a real secret. Put the SMTP password and any reCAPTCHA keys in
 * api/config.local.php instead (copy api/config.local.sample.php):
 *
 *   - it is gitignored, so the password never enters git history, and
 *   - it is excluded from the FTP sync, so a deploy never overwrites the live
 *     server's working password.
 *
 * The built-in image CAPTCHA, CSRF token, honeypot and rate limiting all work
 * with no keys at all.
 */
return array(
	'to_email' => 'herald_felisilda@yahoo.com',
	'to_name' => 'Herald Felisilda',
	'site_name' => 'HFolio / Herald Felisilda',

	'smtp' => array(
		'protocol' => 'smtp',
		'smtp_host' => 'mail.supremecluster.com',
		'smtp_port' => 465,
		'smtp_user' => 'contactus@pensionhouse.bodarempc.com',
		'smtp_pass' => 'M3ss3ng3r', // set this in api/config.local.php, never here
		'smtp_crypto' => 'ssl',
		'smtp_timeout' => 30,
		'from_email' => 'herald_felisilda@yahoo.com',
		'from_name' => 'Herald Felisilda Contact Form',
		'mailtype' => 'html',
		'charset' => 'utf-8',
		'is_active' => true,

		// Second transport, tried only when the block above fails (rejected
		// password, provider outage, refused relay). Leave smtp_pass empty to
		// disable it. Real credentials belong in api/config.local.php — this file
		// is committed and replaced on every deploy.
		'smtp_fallback' => array(
			'smtp_host' => '',
			'smtp_port' => 0, // 0 = infer from the crypto mode below (465, else 587)
			'smtp_user' => '',
			'smtp_pass' => '', // empty = fallback disabled
			'smtp_crypto' => '', // '' = infer from the port (465 => ssl, else tls)
		),
	),

	// reCAPTCHA is OFF: the keys below were a reCAPTCHA v2 pair, but the client
	// uses the v3 API (grecaptcha.execute + action/score check), so Google
	// answers api.js?render=<site key> with HTTP 400 and the widget never loads.
	// The form still runs its self-hosted CAPTCHA, CSRF token, honeypot and rate
	// limiting, none of which need keys.
	// To switch it back on: create a "Score based (v3)" key pair at
	// https://www.google.com/recaptcha/admin/create, put both keys AND
	// 'recaptcha_enabled' => true in api/config.local.php.
	'recaptcha_enabled' => false,
	'recaptcha_site_key' => '',
	'recaptcha_secret_key' => '',
	'recaptcha_min_score' => 0.5,
	'recaptcha_expected_action' => 'contact_submit',

	'csrf_ttl' => 7200,
	'min_submit_seconds' => 1,
	'rate_limit_max' => 5,
	'rate_limit_window' => 900,
	'rate_limit_block_seconds' => 1800,
	'honeypot_field' => 'company_url',
);
