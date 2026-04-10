# Recording & Replay

The library can capture a full IMAP session to disk and replay it deterministically afterwards — useful for fixture-driven tests, debugging provider quirks, or reproducing bugs without a live server or credentials. Both modes are wired into the high-level `Mailbox` API; you don't have to assemble connections by hand.

## Recording

Set `Config::recordPath` and connect normally. Every I/O frame is appended to the JSONL file at that path:

```php
use D4ry\ImapClient\Auth\PlainCredential;
use D4ry\ImapClient\Config;
use D4ry\ImapClient\Mailbox;

$mailbox = Mailbox::connect(new Config(
    host: 'imap.example.com',
    credential: new PlainCredential('user@example.com', 'password'),
    recordPath: '/tmp/session.jsonl',
));

// Use the mailbox normally — every byte gets recorded.
foreach ($mailbox->inbox()->messages() as $message) {
    echo $message->envelope()->subject . "\n";
}

$mailbox->disconnect();
```

By default `LOGIN` and `AUTHENTICATE` payloads are redacted, so credentials never end up in committed fixtures. If the recording must later drive an authentication exchange via replay, opt out:

```php
new Config(
    host: 'imap.example.com',
    credential: new PlainCredential('user@example.com', 'password'),
    recordPath: '/tmp/session.jsonl',
    recordRedactCredentials: false,
);
```

## Replay

Use `Mailbox::connectFromRecording()` to drive a Mailbox from a recorded session — no network, no real socket:

```php
use D4ry\ImapClient\Auth\PlainCredential;
use D4ry\ImapClient\Config;
use D4ry\ImapClient\Mailbox;

$mailbox = Mailbox::connectFromRecording('/tmp/session.jsonl', new Config(
    host: 'imap.example.com',                                       // ignored, but kept for readability
    credential: new PlainCredential('user@example.com', 'password'), // must match what was recorded
));

foreach ($mailbox->inbox()->messages() as $message) {
    echo $message->envelope()->subject . "\n";
}
```

The full connect lifecycle still runs (greeting → optional STARTTLS → authenticate → ENABLE → ID), but every outbound write is validated against the recording instead of a socket. The supplied `Config` must match the credentials and feature flags used when the session was recorded — otherwise you get a `ReplayMismatchException` pointing at the first divergent frame. The `host`, `port`, `encryption`, `timeout` and `sslOptions` fields are accepted but unused, since no real connection is opened.

## How it Works

Under the hood there are three `ConnectionInterface` decorators in `src/Connection/`:

- **`LoggingConnection`** — human-readable trace log. Wired automatically by `Config::logPath`.
- **`RecordingConnection`** — JSONL capture of every I/O frame. Wired automatically by `Config::recordPath`.
- **`ReplayConnection`** — reads a JSONL recording back and validates outbound writes. Wired automatically by `Mailbox::connectFromRecording()`.

You can also use them directly via the `Transceiver` layer if you need full control over the connection stack.

## See also

- [Raw Connection Access](raw-connection.md)
- [Architecture](../architecture.md)
