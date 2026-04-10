# Getting Started

## Installation

```bash
composer require d4ry/imap-client
```

**Requirements:** PHP 8.4+, `ext-openssl`, `ext-mbstring`

No other runtime dependencies — the library talks IMAP directly over sockets.

## First Connection

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

## Other Ways to Connect

`Mailbox::connect($config)` is the normal entry point, but it's not the only one. The connection stack is layered and each layer is usable on its own:

```php
use D4ry\ImapClient\Connection\SocketConnection;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Protocol\Transceiver;

// 1. Drop straight to the socket + protocol layer and send raw IMAP commands.
$connection = new SocketConnection();
$connection->open('imap.example.com', 993, Encryption::Tls, 30.0);
$transceiver = new Transceiver($connection);

// 2. Wrap a connection in a decorator for logging, recording, or replay.
//    These are also wired automatically by Config::logPath, Config::recordPath,
//    and Mailbox::connectFromRecording().
```

Use these when you need to send a command the high-level API doesn't wrap, write fixture-driven tests without a live server, or inspect the raw protocol exchange. See [Raw Connection Access](advanced/raw-connection.md) and [Recording & Replay](advanced/recording-replay.md) for the details.

## A Slightly Bigger Example

Fetch unread messages from the last week, print sender + subject, and save the text body:

```php
use D4ry\ImapClient\Search\Search;

$search = (new Search())
    ->unread()
    ->after(new DateTime('-7 days'));

foreach ($mailbox->inbox()->messages($search) as $message) {
    $envelope = $message->envelope();

    printf(
        "[%s] %s — %s\n",
        $envelope->date->format('Y-m-d H:i'),
        $envelope->from[0],
        $envelope->subject,
    );

    if ($message->hasHtml()) {
        file_put_contents("/tmp/{$message->uid()}.html", $message->html());
    } else {
        file_put_contents("/tmp/{$message->uid()}.txt", $message->text());
    }
}
```

## Next Steps

- Configure TLS, timeouts, and extensions — see [Configuration](configuration.md)
- Use OAuth2 instead of password auth — see [Authentication](authentication.md)
- Build complex queries — see [Search](search.md)
- React to new mail in real time — see [IDLE](idle.md)
