# PHP IMAP Client

[![Tests](https://github.com/D4ryB3rry/PHP-Imap-/actions/workflows/tests.yml/badge.svg)](https://github.com/D4ryB3rry/PHP-Imap-/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/D4ryB3rry/PHP-Imap/graph/badge.svg?token=07Z3M6IDRR)](https://codecov.io/gh/D4ryB3rry/PHP-Imap)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FD4ryB3rry%2FPHP-Imap%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/D4ryB3rry/PHP-Imap/main)
[![Packagist Version](https://img.shields.io/packagist/v/d4ry/imap-client)](https://packagist.org/packages/d4ry/imap-client)
[![Packagist Downloads](https://img.shields.io/packagist/dt/d4ry/imap-client)](https://packagist.org/packages/d4ry/imap-client/stats)
![License Apache 2.0](https://img.shields.io/badge/License-Apache%202.0-blue)

**Raw-socket IMAP client for PHP 8.4+ — zero dependencies, full IMAPv4rev2 support.**

Designed from scratch for modern PHP: speaks IMAP directly over sockets, fetches only what you ask for, and gives you full control over the protocol when you need it.

- 🔌 Raw socket connection with TLS / STARTTLS
- 📦 Zero runtime dependencies (only `ext-mbstring` + `ext-openssl`)
- 📜 IMAPv4rev2 (RFC 9051) with 18+ extensions
- 🪶 Lazy loading — bodies and attachments fetched on demand
- 🎯 BODYSTRUCTURE-first — 50 MB email with a 12 KB text body? You download 12 KB
- 🔎 Fluent search builder, IDLE + NOTIFY push, OAuth2 with token refresh

## Installation

```bash
composer require d4ry/imap-client
```

**Requirements:** PHP 8.4+, `ext-openssl`, `ext-mbstring`

## Quick Start

```php
use D4ry\ImapClient\Auth\PlainCredential;
use D4ry\ImapClient\Config;
use D4ry\ImapClient\Mailbox;

$mailbox = Mailbox::connect(new Config(
    host: 'imap.example.com',
    credential: new PlainCredential('user@example.com', 'password'),
));

foreach ($mailbox->inbox()->messages() as $message) {
    echo $message->envelope()->subject . "\n";

    foreach ($message->attachments()->nonInline() as $attachment) {
        $attachment->save('/tmp');
    }
}

$mailbox->disconnect();
```

## Search Example

```php
use D4ry\ImapClient\Search\Search;

$search = (new Search())
    ->unread()
    ->from('notifications@github.com')
    ->after(new DateTime('-7 days'));

foreach ($mailbox->inbox()->messages($search) as $message) {
    printf("[%s] %s\n", $message->envelope()->from[0], $message->envelope()->subject);
    echo $message->hasHtml() ? $message->html() : $message->text();
}
```

More examples and the full API are in the [documentation](docs/README.md).

## Benchmarks

Head-to-head benchmarks against [`webklex/php-imap`](https://github.com/Webklex/php-imap) and [`ddeboer/imap`](https://github.com/ddeboer/imap), run against a local Dovecot in Docker with 1 warmup + 10 measured runs per scenario. Time = median ms, memory = per-scenario delta in MB. Bold marks the row winner.

| Scenario | d4ry (ms / MB) | webklex (ms / MB) | ddeboer (ms / MB) |
|---|---:|---:|---:|
| Fetch text body of large-attachment message | **40.8** / 0.50 | 9,123.8 / 374.64 | 45.2 / **0.42** |
| Search UNSEEN FROM x SINCE y | **107.6** / 0.59 | 165.8 / 5.26 | 114.5 / **0.40** |
| Cold open + read first 10 | **53.8** / 0.76 | 211.5 / 6.21 | 90.9 / **0.68** |

Results depend heavily on how each library is called — see **[docs/benchmarks.md](docs/benchmarks.md)** for the full 7-scenario table and methodology. The adapters and raw data are auditable in **[D4ryB3rry/imap-client-benchmarks](https://github.com/D4ryB3rry/imap-client-benchmarks)**; PRs that improve any adapter are welcome.

## Documentation

**Guides**
- [Getting Started](docs/getting-started.md)
- [Configuration](docs/configuration.md)
- [Authentication](docs/authentication.md) (Plain, Login, OAuth2)
- [Folders](docs/folders.md)
- [Messages](docs/messages.md)
- [Attachments](docs/attachments.md)
- [Search](docs/search.md)
- [IDLE (Push Notifications)](docs/idle.md)
- [Error Handling](docs/error-handling.md)

**Reference**
- [Architecture](docs/architecture.md)
- [Supported Extensions](docs/extensions.md)
- [Benchmarks](docs/benchmarks.md)
- [Testing](docs/testing.md)

**Advanced**
- [Raw Connection Access](docs/advanced/raw-connection.md)
- [Namespace](docs/advanced/namespace.md)
- [Recording & Replay](docs/advanced/recording-replay.md)

## License

Licensed under the [Apache License 2.0](LICENSE).
