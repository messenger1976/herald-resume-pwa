<?php
/**
 * SMTP self-test — localhost only.
 *
 * Replaces bodarepensionhouse's admin "Email Settings > Send test email" screen:
 * answers whether api/config.php can actually deliver mail through the configured
 * mailbox, and shows the raw SMTP conversation when it cannot.
 *
 *   GET  api/test-mail.php            — settings + readiness report (HTML)
 *   GET  api/test-mail.php?format=json — same report as JSON
 *   POST api/test-mail.php  {"to":"you@example.com"} — send a test message
 *
 * Nothing here is reachable from the public internet: only localhost and private
 * LAN addresses are allowed through.
 */

declare(strict_types=1);

$runtime = require __DIR__ . '/bootstrap.php';

$config = $runtime['config'];

if (!api_is_local_request()) {
	http_response_code(403);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'This diagnostic endpoint is only available from localhost.';
	exit;
}

$smtp = is_array($config['smtp']) ? $config['smtp'] : array();
$mailer = new Mailer($smtp);

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
$wantsJson = isset($_GET['format']) && $_GET['format'] === 'json';

$checks = array();
$checks[] = array(
	'label' => 'api/config.php exists',
	'ok' => is_file(__DIR__ . '/config.php'),
	'hint' => 'Copy api/config.sample.php to api/config.php.',
);
$checks[] = array(
	'label' => 'SMTP host configured',
	'ok' => trim((string) ($smtp['smtp_host'] ?? '')) !== '',
	'hint' => 'Set smtp_host (e.g. smtp.mail.yahoo.com).',
);
$checks[] = array(
	'label' => 'SMTP user configured',
	'ok' => trim((string) ($smtp['smtp_user'] ?? '')) !== '',
	'hint' => 'Set smtp_user to the full mailbox address.',
);
$checks[] = array(
	'label' => 'SMTP password set',
	'ok' => trim((string) ($smtp['smtp_pass'] ?? '')) !== '',
	'hint' => 'Paste the mailbox app password — never your normal account password.',
);
$checks[] = array(
	'label' => 'Owner address (to_email) configured',
	'ok' => trim((string) $config['to_email']) !== '',
	'hint' => 'Set to_email to the inbox that should receive inquiries.',
);
$checks[] = array(
	'label' => 'Storage writable',
	'ok' => !empty($runtime['storage_writable']),
	'hint' => 'Give the web server write access to api/storage.',
);
$checks[] = array(
	'label' => 'OpenSSL available (TLS)',
	'ok' => extension_loaded('openssl'),
	'hint' => 'Enable extension=openssl in php.ini for STARTTLS/SSL.',
);
$checks[] = array(
	'label' => 'reCAPTCHA configured',
	'ok' => (new Security($config))->is_recaptcha_configured(),
	'hint' => 'Optional. Leave the keys empty to rely on the built-in image CAPTCHA.',
);

$result = null;

if ($method === 'POST') {
	$body = api_read_json_body();
	$to = isset($body['to']) && trim((string) $body['to']) !== ''
		? trim((string) $body['to'])
		: (string) $config['to_email'];

	$result = $mailer->send_test($to);
	$result['to'] = $to;
}

if ($wantsJson || $method === 'POST' && isset($_SERVER['HTTP_ACCEPT']) && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
	api_send_json(array(
		'success' => true,
		'checks' => $checks,
		'smtp' => $mailer->get_settings(),
		'send_result' => $result,
	));
}

$safe = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Contact API — SMTP self-test</title>
	<style>
		body { font-family: -apple-system, "Segoe UI", Arial, sans-serif; margin: 0; background: #f6f9fe; color: #10213f; }
		main { max-width: 860px; margin: 0 auto; padding: 32px 20px 64px; }
		h1 { font-size: 1.5rem; margin: 0 0 4px; }
		p.lead { color: #435d7a; margin: 0 0 24px; }
		.card { background: #fff; border: 1px solid #dbeafe; border-radius: 14px; padding: 20px; margin-bottom: 20px; box-shadow: 0 10px 24px rgba(30,64,175,.07); }
		table { width: 100%; border-collapse: collapse; font-size: .92rem; }
		td { padding: 7px 0; border-bottom: 1px solid #eef3fb; vertical-align: top; }
		td:first-child { width: 46%; font-weight: 600; }
		.pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: .75rem; font-weight: 700; }
		.ok { background: #e7f6ec; color: #16653a; }
		.bad { background: #fdecec; color: #9b1c1c; }
		.hint { color: #758aa6; font-size: .82rem; }
		code { background: #f1f5fb; padding: 2px 6px; border-radius: 6px; font-size: .85rem; }
		pre { background: #0f172a; color: #dbeafe; padding: 14px; border-radius: 10px; overflow: auto; font-size: .78rem; line-height: 1.5; }
		button { background: linear-gradient(90deg,#ca8a04,#eab308); border: 0; color: #fff; font-weight: 700; padding: 10px 18px; border-radius: 10px; cursor: pointer; }
		.alert { border-radius: 12px; padding: 14px 16px; margin-bottom: 20px; font-weight: 600; }
		.alert.ok { background: #e7f6ec; color: #16653a; border: 1px solid #b7e2c6; }
		.alert.bad { background: #fdecec; color: #9b1c1c; border: 1px solid #f3c2c2; }
	</style>
</head>
<body>
<main>
	<h1>Contact API — SMTP self-test</h1>
	<p class="lead">Diagnostic for <code>api/config.php</code>. Only reachable from localhost.</p>

	<?php if ($result !== null): ?>
		<div class="alert <?php echo $result['success'] ? 'ok' : 'bad'; ?>">
			<?php if ($result['success']): ?>
				Test email accepted for <?php echo $safe($result['to']); ?>. Check that inbox (and the spam folder).
				<?php if (!empty($result['used_fallback'])): ?>
					<br><span class="hint">Delivered through the fallback SMTP transport.</span>
				<?php endif; ?>
			<?php else: ?>
				<?php echo $safe($result['message']); ?>
				<br><span class="hint">Sent for <?php echo $safe($result['to']); ?>.</span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="card">
		<h2 style="font-size:1.05rem;margin:0 0 12px;">Readiness</h2>
		<table>
			<?php foreach ($checks as $check): ?>
				<tr>
					<td>
						<?php echo $safe($check['label']); ?>
						<?php if (!$check['ok']): ?>
							<div class="hint"><?php echo $safe($check['hint']); ?></div>
						<?php endif; ?>
					</td>
					<td><span class="pill <?php echo $check['ok'] ? 'ok' : 'bad'; ?>"><?php echo $check['ok'] ? 'OK' : 'Action needed'; ?></span></td>
				</tr>
			<?php endforeach; ?>
		</table>
	</div>

	<div class="card">
		<h2 style="font-size:1.05rem;margin:0 0 12px;">Effective SMTP settings</h2>
		<table>
			<?php foreach ($mailer->get_settings() as $key => $value): ?>
				<?php if (is_array($value)) { continue; } ?>
				<tr>
					<td><?php echo $safe($key); ?></td>
					<td><code><?php
						echo $safe(is_bool($value) ? ($value ? 'true' : 'false') : ($value === '' ? '(empty)' : $value));
					?></code></td>
				</tr>
			<?php endforeach; ?>
		</table>
	</div>

	<div class="card">
		<h2 style="font-size:1.05rem;margin:0 0 12px;">Send a test message</h2>
		<form method="post">
			<label for="to" style="display:block;font-weight:600;margin-bottom:6px;">Recipient</label>
			<input id="to" name="to" type="email" required
				value="<?php echo $safe($config['to_email']); ?>"
				style="width:100%;max-width:420px;padding:10px 12px;border:1px solid #cfe0f5;border-radius:10px;margin-bottom:14px;">
			<button type="submit">Send test email</button>
		</form>
		<p class="hint" style="margin:14px 0 0;">
			Yahoo and Gmail reject your normal account password here — generate an
			<strong>app password</strong> and put that in <code>smtp_pass</code>.
		</p>
	</div>

	<?php if ($result !== null && !empty($result['transcript'])): ?>
		<div class="card">
			<h2 style="font-size:1.05rem;margin:0 0 12px;">SMTP conversation</h2>
			<pre><?php echo $safe(implode("\n", $result['transcript'])); ?></pre>
		</div>
	<?php endif; ?>
</main>
</body>
</html>
