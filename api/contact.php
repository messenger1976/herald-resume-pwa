<?php
/**
 * POST api/contact.php
 *
 * Contact-form handler. Ported from bodarepensionhouse's `api/inquiry/submit`
 * controller + `Coop_mail`, so the message delivery behaviour matches that
 * project:
 *
 *   1. honeypot     — silently accept, never send
 *   2. rate limit   — per-IP throttle + block window
 *   3. CSRF token   — signed, session-bound, single use, TTL + minimum age
 *   4. CAPTCHA      — self-hosted image/math check and/or reCAPTCHA v3
 *   5. validation   — sanitize every field, reject obvious spam
 *   6. store        — always write the inquiry to api/storage/inquiries
 *   7. email        — notify the owner and acknowledge the sender
 *
 * The message is persisted before any email is attempted, so a broken SMTP
 * password never loses an inquiry.
 *
 * The business logic lives in hfolio_handle_contact_request() so it can also be
 * driven from the command line (see tools/test-contact.php).
 */

declare(strict_types=1);

$runtime = require __DIR__ . '/bootstrap.php';

// When included from a diagnostic script (e.g. tools/test-contact.php) the
// request handling below must not run.
$hfolioIsDirectRequest = PHP_SAPI !== 'cli'
	|| (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__));

if ($hfolioIsDirectRequest) {
	$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

	if ($method === 'OPTIONS') {
		http_response_code(204);
		exit;
	}

	if ($method !== 'POST') {
		http_response_code(405);
		header('Allow: POST, OPTIONS');
		api_send_json(array('success' => false, 'message' => 'Method not allowed'));
	}

	$payload = api_read_json_body();

	$outcome = hfolio_handle_contact_request($runtime['config'], $payload);

	api_send_json($outcome['body'], $outcome['status']);
}

// ---------------------------------------------------------------------------
// Request handling
// ---------------------------------------------------------------------------

/**
 * Validate and process one contact submission.
 *
 * @param array<string,mixed> $config
 * @param array<string,mixed> $payload
 * @return array{status:int,body:array<string,mixed>}
 */
function hfolio_handle_contact_request(array $config, array $payload): array
{
	$security = new Security($config);

	// 1) Honeypot — pretend success so bots do not learn they were filtered.
	if (!$security->check_honeypot($payload)) {
		return hfolio_ok(array(
			'success' => true,
			'message' => 'Thank you! Your message has been sent.',
			'ticket_id' => '',
			'email_sent' => false,
		));
	}

	// 2) Rate limiting
	$rate = $security->check_rate_limit();
	if (empty($rate['ok'])) {
		$retry = isset($rate['retry_after']) ? (int) $rate['retry_after'] : 900;
		if (!headers_sent()) {
			header('Retry-After: ' . $retry);
		}

		return array(
			'status' => 429,
			'body' => array(
				'success' => false,
				'message' => isset($rate['message']) ? $rate['message'] : 'Too many submissions. Please try again later.',
				'retry_after' => $retry,
			),
		);
	}

	// 3) CSRF
	$csrfToken = '';
	if (!empty($payload['csrf_token']) && is_string($payload['csrf_token'])) {
		$csrfToken = $payload['csrf_token'];
	} elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
		$csrfToken = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
	}

	$csrf = $security->verify_csrf($csrfToken);
	if (empty($csrf['ok'])) {
		return array(
			'status' => 403,
			'body' => array(
				'success' => false,
				'message' => isset($csrf['message']) ? $csrf['message'] : 'Invalid security token.',
				'refresh_security' => true,
			),
		);
	}

	// 4) CAPTCHA — self-hosted check, then reCAPTCHA v3 when configured
	$captcha = $security->verify_captcha(isset($payload['captcha_answer']) ? $payload['captcha_answer'] : '');
	if (empty($captcha['ok'])) {
		return array(
			'status' => 403,
			'body' => array(
				'success' => false,
				'message' => isset($captcha['message']) ? $captcha['message'] : 'Security code verification failed.',
				'refresh_security' => true,
				'captcha_failed' => true,
			),
		);
	}

	$recaptcha = $security->verify_recaptcha(isset($payload['recaptcha_token']) ? $payload['recaptcha_token'] : '');
	if (empty($recaptcha['ok'])) {
		return array(
			'status' => 403,
			'body' => array(
				'success' => false,
				'message' => isset($recaptcha['message']) ? $recaptcha['message'] : 'CAPTCHA verification failed.',
				'refresh_security' => true,
				'captcha_failed' => true,
			),
		);
	}

	// 5) Validation + sanitization
	$clean = $security->sanitize_inquiry_fields($payload);
	if (empty($clean['ok'])) {
		return array(
			'status' => 400,
			'body' => array(
				'success' => false,
				'message' => isset($clean['message']) ? $clean['message'] : 'Invalid form data.',
			),
		);
	}

	$fields = $clean['data'];

	// Security checks passed — count this against the IP's budget.
	$security->record_attempt();

	return hfolio_deliver_inquiry($config, $security, $fields, isset($recaptcha['score']) ? $recaptcha['score'] : null);
}

/**
 * @return array{status:int,body:array<string,mixed>}
 */
function hfolio_ok(array $body, int $status = 200): array
{
	return array('status' => $status, 'body' => $body);
}

// ---------------------------------------------------------------------------
// Storage + delivery
// ---------------------------------------------------------------------------

/**
 * Persist the inquiry, then email the owner and acknowledge the sender.
 *
 * @param array<string,mixed>  $config
 * @param array<string,string> $fields sanitized name/email/subject/phone/message
 * @return array{status:int,body:array<string,mixed>}
 */
function hfolio_deliver_inquiry(array $config, Security $security, array $fields, ?float $recaptchaScore): array
{
	$name = $fields['name'];
	$email = $fields['email'];
	$subject = $fields['subject'];
	$phone = $fields['phone'];
	$message = $fields['message'];
	$now = date('Y-m-d H:i:s');

	$siteName = (string) $config['site_name'];
	$ownerEmail = (string) $config['to_email'];
	$smtp = is_array($config['smtp']) ? $config['smtp'] : array();
	$smtpMailbox = isset($smtp['from_email']) ? (string) $smtp['from_email'] : '';
	$mailTimeout = 8;

	$ticketId = hfolio_next_ticket_id();

	$record = array(
		'ticket_id' => $ticketId,
		'name' => $name,
		'email' => $email,
		'phone' => $phone,
		'subject' => $subject,
		'message' => $message,
		'status' => 'new',
		'ip_address' => api_client_ip(),
		'user_agent' => api_client_ua(),
		'created_at' => $now,
		'recaptcha_score' => $recaptchaScore,
		'page' => 'contact.html',
	);

	// Store first — never lose a message to an SMTP problem.
	$stored = hfolio_store_inquiry($record);
	if (!$stored && !empty($config['log_all_submissions'])) {
		$security->log_event('inquiry_store_failed', array('ticket_id' => $ticketId));
	}

	$security->log_event('inquiry_accepted', array(
		'ticket_id' => $ticketId,
		'ip' => api_client_ip(),
		'email' => $email,
		'stored' => $stored,
		'recaptcha_score' => $recaptchaScore,
	));

	// Email the owner, then acknowledge the sender.
	$mailer = new Mailer($smtp);
	$ownerNotified = false;
	$senderAcknowledged = false;
	$mailError = '';
	$headers = array('X-HFolio-Ticket' => $ticketId);

	if ($ownerEmail !== '') {
		$ownerNotified = $mailer->send(
			$ownerEmail,
			'[' . $ticketId . '] New Contact Message: ' . $subject,
			hfolio_owner_email_html($siteName, $name, $email, $subject, $message, $ticketId, $phone, $now),
			$smtpMailbox !== '' ? $smtpMailbox : null,
			null,
			$email,
			$name,
			$mailTimeout,
			$headers
		);
		if (!$ownerNotified) {
			$mailError = $mailer->get_last_error();
		}
	} else {
		$mailError = 'No owner address (to_email) is configured in api/config.php.';
	}

	// Acknowledge only when the sender is not the owner's own address (avoids loops).
	if ($ownerNotified && strcasecmp($email, $ownerEmail) !== 0) {
		$senderAcknowledged = $mailer->send(
			$email,
			'[' . $ticketId . '] I received your message: ' . $subject,
			hfolio_ack_email_html($siteName, $name, $subject, $ticketId, $now),
			$smtpMailbox !== '' ? $smtpMailbox : null,
			null,
			$ownerEmail,
			$siteName,
			$mailTimeout,
			$headers
		);
		if (!$senderAcknowledged && $mailError === '') {
			$mailError = $mailer->get_last_error();
		}
	}

	if (!$ownerNotified) {
		$security->log_event('inquiry_email_failed', array(
			'ticket_id' => $ticketId,
			'error' => $mailError,
		));
	}

	$security->log_event('inquiry_mail_dispatched', array(
		'ticket_id' => $ticketId,
		'owner_notified' => $ownerNotified,
		'sender_acknowledged' => $senderAcknowledged,
	));

	$body = array(
		'success' => true,
		'message' => 'Thank you! Your message has been sent.',
		'ticket_id' => $ticketId,
		'email_sent' => $ownerNotified,
		'acknowledgement_sent' => $senderAcknowledged,
		'stored' => (bool) $stored,
	);

	if (!$ownerNotified) {
		// The inquiry is safe in storage, but show the visitor how to reach out
		// directly so the message still gets through.
		$fallbackAddress = $ownerEmail !== '' ? $ownerEmail : (string) $smtpMailbox;
		$body['message'] = 'Thank you! Your message has been saved, but the email notification could not be delivered right now.';
		$body['mail_error'] = $mailError;
		$body['mailto'] = hfolio_mailto_link($fallbackAddress, $subject, $name, $email, $message, $ticketId);
		$body['fallback_email'] = $fallbackAddress;
	}

	return hfolio_ok($body);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Human-readable, mail-friendly ticket id: HF-20250519-0007.
 */
function hfolio_next_ticket_id(): string
{
	$day = date('Ymd');
	$file = api_storage_dir() . '/ticket_counter.json';
	$state = array('day' => $day, 'count' => 0);

	$handle = @fopen($file, 'c+');
	if ($handle !== false) {
		@flock($handle, LOCK_EX);
		$raw = stream_get_contents($handle);
		$decoded = json_decode((string) $raw, true);
		if (is_array($decoded) && isset($decoded['day'], $decoded['count']) && (string) $decoded['day'] === $day) {
			$state['count'] = (int) $decoded['count'];
		}
		$state['count']++;
		@ftruncate($handle, 0);
		@rewind($handle);
		@fwrite($handle, json_encode($state));
		@fflush($handle);
		@flock($handle, LOCK_UN);
		@fclose($handle);
	} else {
		// Counter unavailable: still produce a unique, sortable id.
		$state['count'] = random_int(1, 9999);
	}

	return 'HF-' . $day . '-' . str_pad((string) $state['count'], 4, '0', STR_PAD_LEFT)
		. '-' . strtolower(substr(bin2hex(random_bytes(2)), 0, 4));
}

/**
 * Persist the inquiry as JSON on disk (api/storage/inquiries).
 *
 * @param array<string,mixed> $record
 */
function hfolio_store_inquiry(array $record): bool
{
	$directory = api_storage_dir() . '/inquiries';
	if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
		return false;
	}

	$safeId = (string) preg_replace('/[^A-Za-z0-9\-]/', '', (string) $record['ticket_id']);
	$file = $directory . '/' . date('Y-m-d') . '_' . $safeId . '.json';
	$payload = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	if ($payload === false || @file_put_contents($file, $payload . PHP_EOL, LOCK_EX) === false) {
		return false;
	}

	// Append-only JSONL ledger: easy to grep, survives a corrupted single file.
	@file_put_contents(
		api_storage_dir() . '/inquiries.log',
		json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
		FILE_APPEND | LOCK_EX
	);

	return true;
}

function hfolio_e(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function hfolio_owner_email_html(
	string $siteName,
	string $name,
	string $email,
	string $subject,
	string $message,
	string $ticketId,
	string $phone,
	string $receivedAt
): string {
	$rows = array(
		'Name' => $name,
		'Email' => $email,
		'Subject' => $subject,
		'Phone' => $phone !== '' ? $phone : '—',
		'Ticket' => $ticketId,
		'Received' => $receivedAt,
	);

	$table = '';
	foreach ($rows as $label => $value) {
		$table .= '<tr><td style="padding:6px 0;font-weight:bold;width:110px;">' . hfolio_e($label) . '</td>'
			. '<td>' . hfolio_e($value) . '</td></tr>';
	}

	return '
		<div style="font-family:Arial,Helvetica,sans-serif;line-height:1.6;color:#333;max-width:640px;margin:0 auto;">
			<h2 style="color:#1e40af;margin-bottom:8px;">New Contact Message</h2>
			<p>Someone submitted a message through the ' . hfolio_e($siteName) . ' contact form.</p>
			<table style="width:100%;border-collapse:collapse;margin:16px 0;">' . $table . '</table>
			<p style="font-weight:bold;margin-bottom:4px;">Message</p>
			<div style="padding:12px 14px;background:#f8fafc;border-left:3px solid #1e40af;white-space:pre-wrap;">'
				. nl2br(hfolio_e($message)) . '</div>
			<p style="color:#666;font-size:13px;margin-top:16px;">Hit Reply to answer ' . hfolio_e($name) . ' directly at '
				. hfolio_e($email) . '. Keep "' . hfolio_e($ticketId) . '" in the subject for your records.</p>
		</div>
	';
}

function hfolio_ack_email_html(string $siteName, string $name, string $subject, string $ticketId, string $receivedAt): string
{
	return '
		<div style="font-family:Arial,Helvetica,sans-serif;line-height:1.6;color:#333;max-width:640px;margin:0 auto;">
			<h2 style="color:#1e40af;margin-bottom:8px;">We received your message</h2>
			<p>Hi ' . hfolio_e($name) . ',</p>
			<p>Thank you for contacting <strong>' . hfolio_e($siteName) . '</strong>. Your message has been received and I will get back to you soon.</p>
			<table style="border-collapse:collapse;margin:16px 0;">
				<tr><td style="padding:6px 0;font-weight:bold;width:110px;">Reference</td><td>' . hfolio_e($ticketId) . '</td></tr>
				<tr><td style="padding:6px 0;font-weight:bold;">Subject</td><td>' . hfolio_e($subject) . '</td></tr>
				<tr><td style="padding:6px 0;font-weight:bold;">Received</td><td>' . hfolio_e($receivedAt) . '</td></tr>
			</table>
			<p style="color:#666;font-size:13px;">You can reply to this email if you need to add more details — please keep the subject line so your message stays on the same thread.</p>
		</div>
	';
}

/**
 * Prefilled mailto: fallback, used only when SMTP delivery fails.
 */
function hfolio_mailto_link(
	string $address,
	string $subject,
	string $name,
	string $email,
	string $message,
	string $ticketId
): string {
	if ($address === '' || strpos($address, '@') === false) {
		return '';
	}

	$body = "Name: " . $name . "\r\n"
		. "Email: " . $email . "\r\n"
		. "Reference: " . $ticketId . "\r\n\r\n"
		. $message;

	return 'mailto:' . $address
		. '?subject=' . rawurlencode('[' . $ticketId . '] ' . $subject)
		. '&body=' . rawurlencode($body);
}
