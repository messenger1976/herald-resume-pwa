<?php
/**
 * SMTP diagnostic: prints the full SMTP transcript, including the fallback
 * attempt when one is configured.
 *
 *   php tools/smtp-probe.php                # sends to the configured to_email
 *   php tools/smtp-probe.php --to=you@x.com
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('CLI only');
}

$runtime = require dirname(__DIR__) . '/api/bootstrap.php';
$config = $runtime['config'];

$to = (string) $config['to_email'];
foreach ($argv as $argument) {
	if (strpos($argument, '--to=') === 0) {
		$to = substr($argument, 5);
	}
}

$smtp = $config['smtp'];
$fallback = isset($smtp['smtp_fallback']) && is_array($smtp['smtp_fallback']) ? $smtp['smtp_fallback'] : array();
$fallbackReady = !empty($fallback['smtp_host']) && !empty($fallback['smtp_user']) && !empty($fallback['smtp_pass']);

echo 'Primary  : ' . $smtp['smtp_host'] . ':' . $smtp['smtp_port'] . ' (' . $smtp['smtp_crypto'] . ') user=' . $smtp['smtp_user'] . PHP_EOL;
echo 'Fallback : ' . ($fallbackReady
	? $fallback['smtp_host'] . ':' . $fallback['smtp_port'] . ' (' . $fallback['smtp_crypto'] . ') user=' . $fallback['smtp_user']
	: 'not configured (smtp_pass is empty) — set it in api/config.local.php') . PHP_EOL;
echo 'Recipient: ' . $to . PHP_EOL . PHP_EOL;

$mailer = new Mailer($smtp);
$ok = $mailer->send($to, 'SMTP transcript probe', '<p>Diagnostic message.</p>');

echo 'send() returned : ' . ($ok ? 'true' : 'false') . PHP_EOL;
echo 'transport used  : ' . ($ok ? ($mailer->used_fallback() ? 'fallback' : 'primary') : 'none') . PHP_EOL;
echo 'last_error      : ' . ($mailer->get_last_error() !== '' ? $mailer->get_last_error() : '(none)') . PHP_EOL;
echo '--- transcript ---' . PHP_EOL;
foreach ($mailer->get_transcript() as $line) {
	echo $line . PHP_EOL;
}

