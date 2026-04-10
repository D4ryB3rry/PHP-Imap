# Testing

The test suite is split into two suites (see `phpunit.xml`):

```bash
# All tests
composer test

# Unit tests only (fast, no network)
composer test:unit

# Integration tests only (real IMAP providers — see below)
composer test:integration

# Coverage report (HTML in build/coverage/html, text summary on stdout)
composer test:coverage
```

## Unit Tests

Cover the protocol parser, MIME decoder, search builder, connection decorators and value objects. Fully offline — this is the suite that runs in CI on PHP 8.4 and 8.5 (see `.github/workflows/tests.yml`) and uploads coverage to Codecov.

## Integration Tests

Live under `tests/Integration/Provider/` and connect to **real IMAP servers** (Gmail, Fastmail, iCloud, Outlook, ProtonBridge, Yahoo, GMX, Web.de). There is currently **no bundled Dovecot-in-Docker setup** — tests skip automatically when credentials are not set.

Credentials come from a `tests/.env` file (or real environment variables, which take precedence — see `tests/bootstrap.php`). Example `tests/.env`:

```bash
IMAP_GMAIL_HOST=imap.gmail.com
IMAP_GMAIL_PORT=993
IMAP_GMAIL_USER=you@gmail.com
IMAP_GMAIL_PASS=app-password
IMAP_GMAIL_ENCRYPTION=tls
```

Each provider has its own `IMAP_<PROVIDER>_*` block (see the commented examples in `phpunit.xml`).
