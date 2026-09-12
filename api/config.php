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
		'smtp_host' => 'smtp.mail.yahoo.com',
		'smtp_port' => 587,
		'smtp_user' => 'herald_felisilda@yahoo.com',
		'smtp_pass' => '', // set this in api/config.local.php, never here
		'smtp_crypto' => 'tls',
		'smtp_timeout' => 30,
		'from_email' => 'herald_felisilda@yahoo.com',
		'from_name' => 'Herald Felisilda Contact Form',
		'mailtype' => 'html',
		'charset' => 'utf-8',
		'is_active' => true,
	),

	'recaptcha_enabled' => true,
	'recaptcha_site_key' => '', // set this in api/config.local.php
	'recaptcha_secret_key' => '', // set this in api/config.local.php
	'recaptcha_min_score' => 0.5,
	'recaptcha_expected_action' => 'contact_submit',

	'csrf_ttl' => 7200,
	'min_submit_seconds' => 1,
	'rate_limit_max' => 5,
	'rate_limit_window' => 900,
	'rate_limit_block_seconds' => 1800,
	'honeypot_field' => 'company_url',
);
