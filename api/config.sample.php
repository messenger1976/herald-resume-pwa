<?php
/**
 * Contact API configuration.
 *
 * api/config.php is the live copy of this file. It IS committed and IS deployed
 * over FTP, so keep every secret out of it — put the SMTP password and any
 * reCAPTCHA keys in api/config.local.php instead (copy config.local.sample.php).
 *
 * api/config.local.php is gitignored and excluded from the FTP sync, so your
 * password never reaches git history and never gets overwritten on the server.
 *
 * Everything else is optional: the built-in visual CAPTCHA, CSRF token,
 * honeypot and IP rate limiting work with no third-party keys at all.
 */

return array(

	// -----------------------------------------------------------------------
	// Who receives the notification and what the emails look like
	// -----------------------------------------------------------------------
	'to_email' => 'herald_felisilda@yahoo.com',
	'to_name' => 'Herald Felisilda',
	'site_name' => 'HFolio / Herald Felisilda',
	'site_url' => '', // optional, e.g. https://yourdomain.com — used to link back

	// -----------------------------------------------------------------------
	// SMTP (same shape as bodarepensionhouse's Email_mail profile config)
	//
	// Gmail:   smtp.gmail.com      587 tls  + 16-char App Password
	// Zoho:    smtp.zoho.com       587 tls
	// Hosting: mail.yourdomain.com 587 tls (or 465 ssl)
	// -----------------------------------------------------------------------
	'smtp' => array(
		'protocol' => 'smtp',
		'smtp_host' => 'smtp.mail.yahoo.com',
		'smtp_port' => 587,
		'smtp_user' => 'herald_felisilda@yahoo.com',
		'smtp_pass' => '', // put the app password in api/config.local.php, not here
		'smtp_crypto' => 'tls', // tls (587) | ssl (465) | '' (25, plain)
		'smtp_timeout' => 20,
		'from_email' => 'herald_felisilda@yahoo.com',
		'from_name' => 'Herald Felisilda Contact Form',
		'mailtype' => 'html',
		'charset' => 'utf-8',
		'is_active' => true,

		// Optional second transport, tried only when the block above fails.
		// It must be nested inside 'smtp': the Mailer is built from this array,
		// so a 'smtp_fallback' declared beside it is never read.
		// Leave smtp_pass empty to disable. For a provider on another server,
		// set the port and crypto mode explicitly (465 => ssl, 587 => tls); 0/''
		// makes the Mailer infer them from the port.
		'smtp_fallback' => array(
			'smtp_host' => '',
			'smtp_port' => 0,
			'smtp_user' => '',
			'smtp_pass' => '', // put this in api/config.local.php, not here
			'smtp_crypto' => '',
		),
	),

	// Kept for backwards compatibility: if a top-level 'smtp_fallback' is set,
	// bootstrap.php copies it into $config['smtp']['smtp_fallback'].

	// -----------------------------------------------------------------------
	// CAPTCHA
	//
	// The built-in image CAPTCHA is self-hosted: no keys, no third-party call,
	// works offline on localhost. 'type' picks the challenge:
	//   'image'  — distorted characters (default)
	//   'math'   — "what is 7 + 5?"
	//   'both'   — the server randomly picks one per challenge
	// -----------------------------------------------------------------------
	'captcha' => array(
		'enabled' => true,
		'type' => 'both',
		'length' => 5,      // image characters (4-8)
		'ttl' => 900,       // seconds a challenge stays valid
		'case_sensitive' => false,
	),

	// -----------------------------------------------------------------------
	// Google reCAPTCHA v3 (optional, invisible, runs alongside the image CAPTCHA)
	//
	// Get keys at https://www.google.com/recaptcha/admin — choose "Score based
	// (v3)" and add every domain that serves the form, including localhost.
	// reCAPTCHA only activates when BOTH keys are filled in AND enabled is true.
	// -----------------------------------------------------------------------
	'recaptcha_enabled' => true,
	'recaptcha_site_key' => '',
	'recaptcha_secret_key' => '',
	'recaptcha_min_score' => 0.5,
	'recaptcha_expected_action' => 'contact_submit',

	// -----------------------------------------------------------------------
	// Abuse protection
	// -----------------------------------------------------------------------
	'csrf_ttl' => 7200,              // CSRF token lifetime (seconds)
	'min_submit_seconds' => 1,       // reject instant bot submits after token issue
	'rate_limit_max' => 5,           // submissions allowed per window per IP
	'rate_limit_window' => 900,      // window length (seconds)
	'rate_limit_block_seconds' => 1800, // block duration once the limit is hit
	'honeypot_field' => 'company_url',

	// Store every accepted submission in api/storage/inquiries/*.json even when
	// email delivery fails, so a message is never lost.
	'log_all_submissions' => true,
);
