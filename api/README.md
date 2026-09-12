# Contact API

Backend for the **Quick Message** form on [`contact.html`](../contact.html).
The flow and protections mirror the `bodarepensionhouse` project (`Form_security`
library + `Coop_mail` SMTP library + `api/inquiry/submit` controller), rewritten
as framework-free PHP so it runs as-is on XAMPP.

## Pipeline

| # | Step | Detail |
|---|------|--------|
| 1 | Honeypot | `company_url` must stay empty; bots get a fake success and no email |
| 2 | Rate limit | 5 submissions per IP per 15 min, then a 30 min block (`429` + `Retry-After`) |
| 3 | CSRF | Signed, session-bound, single-use token with a 2 h TTL and a 2 s minimum age |
| 4 | CAPTCHA | Self-hosted image/math challenge, plus Google reCAPTCHA v3 when configured |
| 5 | Validation | Name/email/subject/message sanitised; URL-spam and header-injection guards |
| 6 | Storage | Saved to `api/storage/inquiries/*.json` **before** any email is attempted |
| 7 | Email | Notification to the owner (reply-to = sender) and an acknowledgement to the sender |

A submission is therefore never lost when SMTP is down: the visitor gets a
ticket reference, and the API returns a prefilled `mailto:` link as a fallback.

## Files

```
api/
  bootstrap.php          config loading (config.php + config.local.php), JSON responses, session
  config.php             committable settings — NEVER put a secret here
  config.sample.php      template for config.php
  config.local.php       your real secrets (gitignored, excluded from FTP deploy)
  config.local.sample.php template for config.local.php
  csrf.php               GET  security bootstrap: CSRF token + CAPTCHA challenge
  captcha.php            GET  renders the CAPTCHA image (PNG, or SVG without GD)
  contact.php            POST validates + stores + emails a message
  test-mail.php          SMTP self-test, localhost only
  lib/Security.php       CSRF, honeypot, rate limit, CAPTCHA, reCAPTCHA, sanitising
  lib/Mailer.php         SMTP client (TLS/SSL/plain, AUTH LOGIN/PLAIN, MIME)
  storage/               runtime state (denied over HTTP)
tools/
  test-contact.php       CLI diagnostics for the whole pipeline
```

## Where secrets live

Two config files are merged at boot, and the second one wins:

| File | In git? | Deployed over FTP? | Purpose |
|------|---------|--------------------|---------|
| `api/config.php` | **yes** | **yes** | Addresses, subjects, security tuning. No secrets. |
| `api/config.local.php` | no (gitignored) | no (excluded) | SMTP password, reCAPTCHA keys. |

That split is deliberate: because `config.php` is deployed, a real password
inside it would both land in git history and be overwritten on the server by
every push. `config.local.php` shields it from both.

Overrides merge recursively, so a partial block is fine:

```php
// api/config.local.php
return array(
	'smtp' => array('smtp_pass' => 'your-app-password'),
);
```

## Setup

### 1. Local development

```powershell
Copy-Item api\config.local.sample.php api\config.local.php
```

Then edit `api/config.local.php`:

- `smtp_pass` — a mailbox **app password**, never your normal login password.

Yahoo: Account Info → Security → *Generate app password*.
Gmail: Google Account → Security → 2-Step Verification → *App passwords*.

Check addresses and subjects in `api/config.php` (`to_email`, `smtp_user`,
`from_email`) and leave the reCAPTCHA keys empty — the self-hosted CAPTCHA
already protects the form and works offline.

### 2. Verify

```powershell
php tools\test-contact.php          # full pipeline, no email sent
php tools\test-contact.php --send   # also attempt a real SMTP send
```

Or open <http://localhost/resume/api/test-mail.php> for a browser report with
the raw SMTP conversation when something fails.

### 3. Optional reCAPTCHA v3

Create a *score based (v3)* key pair at <https://www.google.com/recaptcha/admin>,
register every domain that serves the form (including `localhost`), then add both
keys to `api/config.local.php`. reCAPTCHA activates automatically once both keys
are present; the image CAPTCHA keeps running alongside it.

## Deployment

`.github/workflows/deploy.yml` syncs the site over FTP and skips
`api/config.local.php`, `api/storage/**`, `**/.secret` and `**/*.log` — so a push
can never overwrite the live secrets or submission log.

After the first deploy, create the live-only override once, in the web root:

1. Create `api/config.local.php` on the server (cPanel File Manager works) with
   the real `smtp_pass`.
2. Make `api/storage/` writable by the web server user (`755` is normally fine).

`api/.htaccess` denies HTTP access to `config.php`, `config.local.php`,
`bootstrap.php`, `.secret`, `health.php`, `lib/` and `storage/`.

## Storage files

| File | Purpose |
|------|---------|
| `inquiries/*.json` | One file per accepted message |
| `inquiries.log` | Append-only JSONL ledger of every message |
| `events.log` | Security events (honeypot, rate limit, CAPTCHA failures, mail errors) |
| `rate_<hash>.json` | Per-IP submission counters |
| `used_tokens.json` | Spent CSRF signatures (replay protection) |
| `ticket_counter.json` | Daily ticket sequence |
| `.secret` | HMAC key for CSRF signatures |
