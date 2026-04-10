# Raw Connection Access

For advanced use cases or commands not wrapped by the high-level API, drop down to the `Transceiver` layer and send IMAP commands directly.

```php
use D4ry\ImapClient\Connection\SocketConnection;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Protocol\Transceiver;

// Low-level socket
$connection = new SocketConnection();
$connection->open('imap.example.com', 993, Encryption::Tls, 30.0);

// Protocol layer
$transceiver = new Transceiver($connection);
$greeting = $transceiver->readGreeting();

// Send any IMAP command
$response = $transceiver->command('SELECT', 'INBOX');
$response = $transceiver->commandRaw('FETCH 1:* (FLAGS)');

// Check capabilities
$transceiver->capabilities();
$transceiver->hasCapability(\D4ry\ImapClient\Enum\Capability::Move);

// Or access it from a connected Mailbox
$transceiver = $mailbox->getTransceiver();
```

## Connection Wrappers

Every connection in `src/Connection/` implements `ConnectionInterface`, so you can wrap the base `SocketConnection` in decorators that add logging, recording, or replay without the upper layers noticing:

```php
use D4ry\ImapClient\Connection\LoggingConnection;
use D4ry\ImapClient\Connection\RecordingConnection;
use D4ry\ImapClient\Connection\SocketConnection;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Protocol\Transceiver;

$base = new SocketConnection();
$base->open('imap.example.com', 993, Encryption::Tls, 30.0);

// Wrap in a logger, then a recorder — decorators compose.
$logged    = new LoggingConnection($base, '/tmp/imap.log');
$recording = new RecordingConnection($logged, '/tmp/session.jsonl');

$transceiver = new Transceiver($recording);
```

The same decorators are wired automatically when you set `Config::logPath` or `Config::recordPath`, or call `Mailbox::connectFromRecording()`. Assembling them by hand is only necessary when you need a custom stack (e.g. recording a session that also goes through a custom proxy connection). See [Recording & Replay](recording-replay.md) for the high-level API.

## When to Reach for This

- Calling extensions not wrapped by the high-level API (SORT, THREAD, METADATA, ACL, …)
- Building custom fetch profiles or response-parsing logic
- Implementing experimental or provider-specific commands
- Writing integration tests that need to inspect raw protocol state

For normal mailbox workflows, prefer the `Mailbox` / `Folder` / `Message` API instead.

## See also

- [Architecture](../architecture.md)
- [Supported Extensions](../extensions.md)
