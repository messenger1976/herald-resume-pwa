<?php
/**
 * Machine-specific overrides — OPTIONAL.
 *
 * Copy this file to api/config.local.php to override anything in api/config.php
 * without editing the committed file. Values are merged recursively, so an array
 * here (for example the smtp block) is merged key by key, not replaced.
 *
 * Why this file exists:
 *   api/config.php is committed to git AND deployed over FTP, so it must never
 *   contain a real password. api/config.local.php is gitignored and excluded
 *   from the FTP sync, so:
 *     - your local password stays out of git history, and
 *     - the live server's password is never overwritten by a deploy.
 *
 * On the live server, create this file once in the web root (cPanel File
 * Manager works) with the real mailbox password.
 */

return array(
	'smtp' => array(
		// Required for email delivery. Use a mailbox APP PASSWORD, never your
		// normal account password. Generate one in:
		//   Yahoo : Account Info > Security > Generate app password
		//   Gmail : Google Account > Security > App passwords
		'smtp_pass' => '',
	),

	// Optional: only needed if you enable Google reCAPTCHA v3.
	'recaptcha_site_key' => '',
	'recaptcha_secret_key' => '',
);
