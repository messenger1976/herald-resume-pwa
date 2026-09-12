<?php
/**
 * Mailer — plain-PHP port of bodarepensionhouse's `Coop_mail` library.
 *
 * Differences from the CodeIgniter original: it speaks SMTP directly instead of
 * going through CI's Email library, and it keeps a transcript so failures can be
 * diagnosed from the SMTP self-test page. The public API is intentionally the
 * same shape:
 *
 *   $mail->send($to, $subject, $html, $fromEmail, $fromName, $replyTo, $replyToName, $timeout, $headers);
 *
 * Supported transports, chosen from config:
 *   smtp_crypto = 'tls' -> STARTTLS on 587 (typical for Yahoo / Gmail / cPanel)
 *   smtp_crypto = 'ssl' -> implicit TLS on 465
 *   smtp_crypto = ''    -> plain SMTP, usually port 25, local relays only
 */

declare(strict_types=1);

final class Mailer
{
	/** @var array<string,mixed> */
	protected $settings = array();

	protected $last_error = '';
	protected $transcript = array();
	protected $used_fallback = false;
	protected $configured_timeout = 20;

	/** @param array<string,mixed> $settings SMTP settings array from config.php */
	public function __construct(array $settings)
	{
		$this->settings = $settings;
		$this->last_error = '';
		$this->configured_timeout = max(5, (int) $this->value('smtp_timeout', 20));
	}

	// -----------------------------------------------------------------------
	// Introspection
	// -----------------------------------------------------------------------

	public function get_last_error(): string
	{
		return $this->last_error;
	}

	/** @return array<int,string> */
	public function get_transcript(): array
	{
		return $this->transcript;
	}

	public function used_fallback(): bool
	{
		return $this->used_fallback;
	}

	/**
	 * Is there enough configuration to attempt a send?
	 */
	public function is_configured(): bool
	{
		if (empty($this->settings['is_active'])) {
			$this->last_error = 'Email sending is currently disabled in api/config.php (is_active = false).';

			return false;
		}

		if ($this->value('smtp_host') === '' || $this->value('smtp_user') === '') {
			$this->last_error = 'SMTP host/user are not configured yet. Fill in api/config.php.';

			return false;
		}

		if ($this->value('smtp_pass') === '') {
			$this->last_error = 'SMTP password is empty. Add your mailbox app password to api/config.php.';

			return false;
		}

		return true;
	}

	/** @return array<string,mixed> */
	public function get_settings(): array
	{
		$copy = $this->settings;
		unset($copy['smtp_pass']);

		return $copy;
	}

	// -----------------------------------------------------------------------
	// Sending
	// -----------------------------------------------------------------------

	/**
	 * Send one message. Returns true on success; inspect get_last_error() otherwise.
	 *
	 * @param string                  $to
	 * @param string                  $subject
	 * @param string                  $message      HTML or plain text per mailtype
	 * @param string|null             $fromEmail
	 * @param string|null             $fromName
	 * @param string|null             $replyTo
	 * @param string|null             $replyToName
	 * @param int|null                $timeout      override for this send only
	 * @param array<string,string>    $headers      extra headers, e.g. X-HFolio-Ticket
	 */
	public function send(
		$to,
		$subject,
		$message,
		$fromEmail = null,
		$fromName = null,
		$replyTo = null,
		$replyToName = null,
		$timeout = null,
		array $headers = array()
	): bool {
		$this->last_error = '';
		$this->transcript = array();
		$this->used_fallback = false;

		if (!$this->is_configured()) {
			return false;
		}

		$timeout = $timeout !== null ? max(5, (int) $timeout) : $this->configured_timeout;
		$fromEmail = ($fromEmail !== null && $fromEmail !== '') ? $fromEmail : (string) $this->value('from_email');
		$fromName = ($fromName !== null && $fromName !== '') ? $fromName : (string) $this->value('from_name');

		if (strpos($fromEmail, '@') === false) {
			$this->last_error = 'The From address in api/config.php is not a valid email address.';

			return false;
		}

		// Header-injection guard: never let caller data reach raw headers.
		$subject = (string) preg_replace('/[\r\n]+/', ' ', $subject);
		$fromName = (string) preg_replace('/[\r\n]+/', ' ', $fromName);
		$replyToName = $replyToName !== null ? (string) preg_replace('/[\r\n]+/', ' ', $replyToName) : null;

		$recipients = array();
		foreach (preg_split('/[,;]/', (string) $to) as $candidate) {
			$candidate = trim($candidate);
			if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
				$recipients[] = $candidate;
			}
		}

		if (empty($recipients)) {
			$this->last_error = 'No valid recipient address was supplied.';

			return false;
		}

		if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
			$replyTo = null;
			$replyToName = null;
		}

		$data = $this->build_message($fromEmail, $fromName, $recipients, $subject, $message, $replyTo, $replyToName, $headers);

		$attempt = $this->deliver($this->settings, $data, $timeout);
		if ($attempt === true) {
			return true;
		}

		$this->last_error = $attempt;

		// A second transport (e.g. the domain's own mail server) often succeeds
		// when a free-mail provider rejects the relay.
		$fallback = isset($this->settings['smtp_fallback']) && is_array($this->settings['smtp_fallback'])
			? $this->settings['smtp_fallback']
			: array();

		if (!empty($fallback['smtp_host']) && !empty($fallback['smtp_user']) && !empty($fallback['smtp_pass'])) {
			$merged = array_merge($this->settings, $fallback);
			$retry = $this->deliver($merged, $data, $timeout);
			if ($retry === true) {
				$this->used_fallback = true;
				$this->last_error = '';

				return true;
			}
			$this->last_error = $attempt . ' | Fallback transport: ' . $retry;
			$this->used_fallback = true;
		}

		return false;
	}

	/**
	 * Send a diagnostic message, returning structured detail for the self-test page.
	 *
	 * @return array{success:bool,message:string,transcript:array<int,string>,used_fallback:bool}
	 */
	public function send_test(string $to): array
	{
		$siteName = (string) $this->value('from_name', 'HFolio Contact Form');
		$sentAt = date('F j, Y g:i A');
		$subject = 'SMTP Test Email - ' . $siteName;
		$html = '
			<div style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:560px;margin:0 auto;">
				<h2 style="color:#1e40af;margin-bottom:8px;">SMTP test successful</h2>
				<p>This is a test message from <strong>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>
				<p>If you received this email, the contact form can deliver messages.</p>
				<p style="color:#666;font-size:13px;">Sent at: ' . htmlspecialchars($sentAt, ENT_QUOTES, 'UTF-8') . '</p>
			</div>
		';

		$ok = $this->send($to, $subject, $html);

		return array(
			'success' => $ok,
			'message' => $ok ? 'Test email accepted by the SMTP server.' : $this->last_error,
			'transcript' => $this->get_transcript(),
			'used_fallback' => $this->used_fallback(),
		);
	}

	// -----------------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------------

	private function value(string $key, $default = '')
	{
		return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
	}

	/**
	 * @param array<int,string>    $recipients
	 * @param array<string,string> $headers
	 * @return array<string,mixed>
	 */
	private function build_message(
		string $fromEmail,
		string $fromName,
		array $recipients,
		string $subject,
		string $body,
		?string $replyTo,
		?string $replyToName,
		array $headers
	): array {
		$charset = (string) $this->value('charset', 'utf-8');
		$mailtype = strtolower((string) $this->value('mailtype', 'html'));
		$isHtml = $mailtype === 'html';
		$boundary = '=_HFolio_' . bin2hex(random_bytes(12));

		$plain = $isHtml ? $this->html_to_text($body) : $body;

		$lines = array();
		$lines[] = 'Date: ' . date('r');
		$lines[] = 'From: ' . $this->format_address($fromEmail, $fromName);
		$lines[] = 'To: ' . implode(', ', array_map(function ($address) {
			return $this->format_address($address, '');
		}, $recipients));
		$lines[] = 'Subject: ' . $this->encode_header($subject, $charset);
		$lines[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->message_id_host() . '>';

		if ($replyTo !== null) {
			$lines[] = 'Reply-To: ' . $this->format_address($replyTo, (string) $replyToName);
		}

		$lines[] = 'MIME-Version: 1.0';
		$lines[] = 'X-Mailer: HFolio-Contact (PHP/' . PHP_VERSION . ')';

		foreach ($headers as $name => $headerValue) {
			$name = trim((string) preg_replace('/[^A-Za-z0-9\-]/', '', (string) $name));
			if ($name === '' || $headerValue === '' || $headerValue === null) {
				continue;
			}
			$lines[] = $name . ': ' . (string) preg_replace('/[\r\n]+/', ' ', (string) $headerValue);
		}

		$headersBlock = implode("\r\n", $lines) . "\r\n";

		if ($isHtml) {
			$bodyBlock = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n\r\n"
				. '--' . $boundary . "\r\n"
				. 'Content-Type: text/plain; charset=' . $charset . "\r\n"
				. "Content-Transfer-Encoding: 8bit\r\n\r\n"
				. $this->dot_stuff($this->normalize_newlines($plain)) . "\r\n"
				. '--' . $boundary . "\r\n"
				. 'Content-Type: text/html; charset=' . $charset . "\r\n"
				. "Content-Transfer-Encoding: 8bit\r\n\r\n"
				. $this->dot_stuff($this->normalize_newlines($body)) . "\r\n"
				. '--' . $boundary . "--\r\n";
		} else {
			$bodyBlock = 'Content-Type: text/plain; charset=' . $charset . "\r\n"
				. "Content-Transfer-Encoding: 8bit\r\n\r\n"
				. $this->dot_stuff($this->normalize_newlines($body)) . "\r\n";
		}

		return array(
			'headers' => $headersBlock,
			'body' => $bodyBlock,
			'recipients' => $recipients,
			'sender' => $fromEmail,
		);
	}

	/**
	 * Run one SMTP conversation.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $data
	 * @return true|string true on success, error message on failure
	 */
	private function deliver(array $settings, array $data, int $timeout)
	{
		$host = (string) $settings['smtp_host'];
		$port = (int) ($settings['smtp_port'] ?? 587);
		$user = (string) $settings['smtp_user'];
		$pass = (string) $settings['smtp_pass'];
		$crypto = strtolower(trim((string) ($settings['smtp_crypto'] ?? '')));
		$isTls = ($crypto === 'tls' || $crypto === 'starttls');

		if ($crypto !== '' && $crypto !== 'ssl' && $crypto !== 'tls' && $crypto !== 'starttls') {
			return 'Unsupported smtp_crypto value "' . $crypto . '". Use "tls", "ssl", or leave it empty.';
		}

		$context = stream_context_create(array(
			'ssl' => array(
				'verify_peer' => true,
				'verify_peer_name' => true,
				'allow_self_signed' => false,
				'peer_name' => $host,
			),
		));

		if ($crypto === 'ssl') {
			$endpoint = 'ssl://' . $host . ':' . $port;
		} else {
			$endpoint = 'tcp://' . $host . ':' . $port;
		}

		$this->transcript[] = 'CONNECT ' . $endpoint . ' (' . ($crypto === '' ? 'plain' : $crypto) . ')';

		$errno = 0;
		$errstr = '';
		$socket = @stream_socket_client($endpoint, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

		if ($socket === false) {
			$this->transcript[] = 'FAILED: ' . $errstr . ' (' . $errno . ')';

			return 'Could not connect to ' . $host . ':' . $port . ' — ' . ($errstr !== '' ? $errstr : 'connection failed') . '. Check smtp_host, smtp_port and smtp_crypto.';
		}

		stream_set_timeout($socket, $timeout);

		$greeting = $this->read_response($socket);
		if ($greeting['code'] !== 220) {
			$this->close($socket);

			return 'The SMTP server did not greet us properly' . $this->response_hint($greeting) . '.';
		}

		$ehloHost = $this->message_id_host();
		$ehlo = $this->command($socket, 'EHLO ' . $ehloHost);

		// Older servers may only speak HELO.
		if ($ehlo['code'] !== 250) {
			$ehlo = $this->command($socket, 'HELO ' . $ehloHost);
			if ($ehlo['code'] !== 250) {
				$this->close($socket);

				return 'The SMTP server rejected our greeting' . $this->response_hint($ehlo) . '.';
			}
		}

		$capabilities = strtoupper($ehlo['text']);

		if ($isTls) {
			if (strpos($capabilities, 'STARTTLS') === false) {
				$this->close($socket);

				return 'The server does not offer STARTTLS on this port. Try smtp_port 465 with smtp_crypto "ssl", or port 25 with an empty smtp_crypto.';
			}

			$starttls = $this->command($socket, 'STARTTLS');
			if ($starttls['code'] !== 220) {
				$this->close($socket);

				return 'STARTTLS was refused' . $this->response_hint($starttls) . '.';
			}

			$this->transcript[] = 'TLS negotiation started';
			$cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
			if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
				$cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
			}
			if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
				$cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
			}

			$enabled = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
			if ($enabled !== true) {
				$this->close($socket);

				return 'Could not establish a TLS session with ' . $host . '. Your PHP/OpenSSL build may not support the cipher the server requires.';
			}

			$this->transcript[] = 'TLS active: ' . (string) (stream_get_meta_data($socket)['crypto']['protocol'] ?? 'unknown');
			$this->command($socket, 'EHLO ' . $ehloHost);
		}

		// ---- Authentication ----
		$authOk = false;
		$authError = '';

		if (!empty($capabilities) && strpos($capabilities, 'AUTH LOGIN') !== false) {
			$authError = $this->auth_login($socket, $user, $pass);
			$authOk = ($authError === '');
		} elseif (!empty($capabilities) && strpos($capabilities, 'AUTH PLAIN') !== false) {
			$authError = $this->auth_plain($socket, $user, $pass);
			$authOk = ($authError === '');
		} else {
			// No advertised mechanism: try LOGIN, which is the most widely accepted.
			$authError = $this->auth_login($socket, $user, $pass);
			$authOk = ($authError === '');
		}

		if (!$authOk) {
			$this->close($socket);

			if (stripos($authError, '535') !== false || stripos($authError, 'authentication') !== false) {
				return 'SMTP login failed (535). Host and port are reachable, but the username or password was rejected. Use an app password, not your normal account password.';
			}

			return 'SMTP authentication failed: ' . $authError;
		}

		// ---- Envelope ----
		$mailFrom = $this->command($socket, 'MAIL FROM:<' . $data['sender'] . '>');
		if ($mailFrom['code'] !== 250) {
			$this->close($socket);

			return 'The server rejected the sender address ' . $data['sender'] . $this->response_hint($mailFrom) . '.';
		}

		foreach ($data['recipients'] as $recipient) {
			$rcpt = $this->command($socket, 'RCPT TO:<' . $recipient . '>');
			if ($rcpt['code'] !== 250 && $rcpt['code'] !== 251) {
				$this->close($socket);

				return 'The server rejected the recipient ' . $recipient . $this->response_hint($rcpt) . '.';
			}
		}

		$dataCommand = $this->command($socket, 'DATA');
		if ($dataCommand['code'] !== 354) {
			$this->close($socket);

			return 'The server refused to accept message data' . $this->response_hint($dataCommand) . '.';
		}

		$payload = $data['headers'] . $data['body'];
		$this->write($socket, $this->dot_stuff($this->normalize_newlines($payload)) . "\r\n.\r\n");

		$accepted = $this->read_response($socket);
		$this->command($socket, 'QUIT');
		$this->close($socket);

		if ($accepted['code'] !== 250) {
			return 'The server did not accept the message' . $this->response_hint($accepted) . '.';
		}

		$this->transcript[] = 'ACCEPTED for ' . implode(', ', $data['recipients']);

		return true;
	}

	/**
	 * @return string '' on success, error detail otherwise
	 */
	private function auth_login($socket, string $user, string $pass): string
	{
		$start = $this->command($socket, 'AUTH LOGIN');
		if ($start['code'] !== 334) {
			return 'server replied ' . $start['code'] . $this->response_hint($start);
		}

		$userStep = $this->command($socket, base64_encode($user));
		if ($userStep['code'] !== 334) {
			return 'username rejected (' . $userStep['code'] . ')';
		}

		$passStep = $this->command($socket, base64_encode($pass));
		if ($passStep['code'] !== 235) {
			return 'password rejected (' . $passStep['code'] . ')' . $this->response_hint($passStep);
		}

		return '';
	}

	/**
	 * @return string '' on success, error detail otherwise
	 */
	private function auth_plain($socket, string $user, string $pass): string
	{
		$payload = base64_encode("\0" . $user . "\0" . $pass);
		$response = $this->command($socket, 'AUTH PLAIN ' . $payload);
		if ($response['code'] !== 235) {
			return 'credentials rejected (' . $response['code'] . ')' . $this->response_hint($response);
		}

		return '';
	}

	/**
	 * @return array{code:int,text:string}
	 */
	private function command($socket, string $command): array
	{
		$safe = (strpos($command, 'AUTH ') === 0) ? substr($command, 0, 12) . ' ***' : $command;
		$this->transcript[] = '> ' . $safe;
		$this->write($socket, $command . "\r\n");

		return $this->read_response($socket);
	}

	private function write($socket, string $data): void
	{
		$length = strlen($data);
		$written = 0;
		while ($written < $length) {
			$chunk = @fwrite($socket, substr($data, $written));
			if ($chunk === false || $chunk === 0) {
				break;
			}
			$written += $chunk;
		}
	}

	/**
	 * @return array{code:int,text:string}
	 */
	private function read_response($socket): array
	{
		$text = '';
		$code = 0;

		while (($line = @fgets($socket, 2048)) !== false) {
			$line = rtrim($line, "\r\n");
			$this->transcript[] = '< ' . $line;

			if (preg_match('/^(\d{3})([\s\-])(.*)$/', $line, $matches)) {
				$code = (int) $matches[1];
				$text .= $matches[3] . "\n";
				if ($matches[2] === ' ') {
					break;
				}
			} else {
				$text .= $line . "\n";
			}
		}

		return array('code' => $code, 'text' => trim($text));
	}

	/**
	 * @param array{code:int,text:string} $response
	 */
	private function response_hint(array $response): string
	{
		$text = substr($response['text'], 0, 240);

		return $text !== '' ? ' — ' . $text : ' (' . $response['code'] . ')';
	}

	private function close($socket): void
	{
		if (is_resource($socket)) {
			@fclose($socket);
		}
	}

	private function dot_stuff(string $value): string
	{
		// RFC 5321: a line starting with "." must be doubled.
		return (string) preg_replace('/^\./m', '..', $value);
	}

	private function normalize_newlines(string $value): string
	{
		return (string) preg_replace("/\r\n|\r|\n/", "\r\n", $value);
	}

	private function message_id_host(): string
	{
		$host = (string) $this->value('smtp_host');
		if ($host !== '') {
			return $host;
		}

		$server = isset($_SERVER['SERVER_NAME']) ? (string) $_SERVER['SERVER_NAME'] : 'localhost';

		return $server !== '' ? $server : 'localhost';
	}

	private function format_address(string $email, string $name = ''): string
	{
		if ($name === '') {
			return '<' . $email . '>';
		}

		$charset = (string) $this->value('charset', 'utf-8');

		return $this->encode_header($name, $charset) . ' <' . $email . '>';
	}

	private function encode_header(string $value, string $charset): string
	{
		if ($value === '') {
			return '';
		}

		if (preg_match('/^[\x20-\x7E]*$/', $value)) {
			return $value;
		}

		return '=?UTF-8?B?' . base64_encode($value) . '?=';
	}

	private function html_to_text(string $html): string
	{
		$text = (string) preg_replace('/<(br|\/p|\/tr|\/h[1-6]|\/div)\s*\/?>/i', "\n", $html);
		$text = (string) preg_replace('/<li[^>]*>/i', "\n - ", $text);
		$text = (string) preg_replace('/<\/(td|th)>/i', "\t", $text);
		$text = strip_tags($text);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = (string) preg_replace('/[ \t]+/', ' ', $text);
		$text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

		return trim($text);
	}
}
