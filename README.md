# PHP IMAP Client

**Raw-socket IMAP client for PHP 8.4+ — zero dependencies, full IMAPv4rev2 support.**

No `imap_*` extension. No third-party packages. Just PHP, a socket, and the RFC.

- Raw socket connection with TLS/STARTTLS
- Zero dependencies (only `ext-mbstring` + `ext-openssl`)
- IMAPv4rev2 (RFC 9051) with 18+ extensions
- Lazy loading — bodies & attachments fetched on demand
- BODYSTRUCTURE-first — downloads only the MIME part you need
- Fluent search builder, IDLE push, OAuth2 support

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
}

$mailbox->disconnect();
```

## Documentation

- [Configuration](#configuration)
- [Authentication](#authentication) (Plain, Login, OAuth2)
- [Folders](#working-with-folders)
- [Messages](#working-with-messages)
- [Attachments](#working-with-attachments)
- [Search](#search)
- [IDLE (Push Notifications)](#idle-push-notifications)
- [Appending Messages](#appending-messages)
- [Namespace](#namespace)
- [Raw Connection Access](#raw-connection-access)
- [Error Handling](#error-handling)
- [Supported Extensions](#supported-imapv4rev2-extensions)

---

## Configuration

```php
use D4ry\ImapClient\Config;
use D4ry\ImapClient\Enum\Encryption;

$config = new Config(
    host: 'imap.example.com',
    credential: $credential,
    port: 993,                    // default: 993
    encryption: Encryption::Tls,  // Tls, StartTls, or None
    timeout: 30.0,                // seconds
    enableCondstore: true,        // CONDSTORE extension
    enableQresync: true,          // QRESYNC extension
    utf8Accept: true,             // UTF8=ACCEPT extension
    clientId: [                   // ID extension
        'name' => 'MyApp',
        'version' => '1.0',
    ],
);
```

## Authentication

### Plain / Login

```php
use D4ry\ImapClient\Auth\PlainCredential;
use D4ry\ImapClient\Auth\LoginCredential;

// AUTHENTICATE PLAIN (preferred)
$credential = new PlainCredential('user@example.com', 'password');

// LOGIN command (legacy fallback)
$credential = new LoginCredential('user@example.com', 'password');
```

### OAuth2 (Google, Microsoft, etc.)

```php
use D4ry\ImapClient\Auth\XOAuth2Credential;

$credential = new XOAuth2Credential(
    email: 'user@gmail.com',
    accessToken: 'ya29.a0AfH6SM...',
);
```

With automatic token refresh:

```php
use D4ry\ImapClient\Auth\Contract\TokenRefresherInterface;
use D4ry\ImapClient\Auth\XOAuth2Credential;

class MyTokenRefresher implements TokenRefresherInterface
{
    public function refresh(string $currentToken): string
    {
        // Call your OAuth provider to get a new access token
        return $newAccessToken;
    }
}

$credential = new XOAuth2Credential(
    email: 'user@gmail.com',
    accessToken: $currentToken,
    tokenRefresher: new MyTokenRefresher(),
);
```

## Working with Folders

```php
$mailbox = Mailbox::connect($config);

// List all folders
foreach ($mailbox->folders() as $folder) {
    echo $folder->name() . "\n";
    echo $folder->path() . "\n";
    echo $folder->specialUse()?->name . "\n"; // Inbox, Sent, Trash, etc.
}

// Get specific folders
$inbox = $mailbox->inbox();
$folder = $mailbox->folder('Archive/2024');

// Find by special use
$trash = $mailbox->folders()->bySpecialUse(\D4ry\ImapClient\Enum\SpecialUse::Trash);
$sent  = $mailbox->folders()->bySpecialUse(\D4ry\ImapClient\Enum\SpecialUse::Sent);

// Folder operations
$folder->select();       // read-write
$folder->examine();      // read-only
$folder->create();
$folder->delete();
$folder->rename('New Name');
$folder->subscribe();
$folder->unsubscribe();
$folder->expunge();      // permanently remove \Deleted messages

// Child folders
foreach ($folder->children() as $child) {
    echo $child->name() . "\n";
}

// Folder status
$status = $folder->status();
echo $status->messages;      // total messages
echo $status->unseen;        // unread count
echo $status->uidNext;       // next UID
echo $status->uidValidity;
echo $status->highestModSeq; // requires CONDSTORE
echo $status->size;          // requires STATUS=SIZE
```

## Working with Messages

### Fetching Messages

```php
use D4ry\ImapClient\Enum\Flag;

// All messages
$messages = $folder->messages();

// By flag shorthand
$unread = $folder->messages(Flag::Seen);

// By search criteria (see Search section below)
$filtered = $folder->messages(
    (new \D4ry\ImapClient\Search\Search())->unread()->from('boss@example.com')
);

// Single message by UID
$message = $folder->message(new \D4ry\ImapClient\ValueObject\Uid(12345));
```

### Reading Message Content

```php
// Envelope (fetched in bulk, no extra round-trip)
$envelope = $message->envelope();
echo $envelope->subject;
echo $envelope->date->format('Y-m-d');
echo $envelope->messageId;
echo $envelope->from[0]->email();  // "sender@example.com"
echo $envelope->from[0]->name;     // "John Doe"
echo $envelope->from[0];           // "\"John Doe\" <sender@example.com>"

// Metadata
echo $message->uid();
echo $message->size();
echo $message->internalDate()->format('Y-m-d H:i:s');

// Flags
$message->flags()->has(Flag::Seen);     // bool
$message->flags()->has(Flag::Flagged);  // bool

// Body (fetched on demand)
if ($message->hasHtml()) {
    $html = $message->html();
} else {
    $text = $message->text();
}

// Headers
$headers = $message->headers();                // array<string, string[]>
$replyTo = $message->header('Reply-To');       // ?string

// Raw RFC 5322 source
$raw = $message->rawBody();

// Save as .eml file
$message->save('/path/to/emails/message.eml');
```

### Message Actions

```php
use D4ry\ImapClient\Enum\Flag;

// Flags
$message->setFlag(Flag::Seen, Flag::Flagged);
$message->clearFlag(Flag::Flagged);

// Move / Copy (MOVE extension used automatically when available)
$message->moveTo('Archive/2024');
$message->moveTo($archiveFolder);  // accepts FolderInterface too
$message->copyTo('Backup');

// Delete (sets \Deleted flag — call $folder->expunge() to permanently remove)
$message->delete();
```

## Working with Attachments

```php
foreach ($message->attachments() as $attachment) {
    // Skip inline images (embedded in HTML)
    if ($attachment->isInline()) {
        echo "Inline: cid:" . $attachment->contentId() . "\n";
        continue;
    }

    echo $attachment->filename();   // "report.pdf"
    echo $attachment->mimeType();   // "application/pdf"
    echo $attachment->size();       // bytes (from BODYSTRUCTURE, before decoding)

    // Save to disk
    $attachment->save('/path/to/downloads');  // saves as /path/to/downloads/report.pdf

    // Or get raw decoded content
    $bytes = $attachment->content();
}

// Filter collections
$regular = $message->attachments()->nonInline();  // only real attachments
$inline  = $message->attachments()->inline();      // only embedded images
```

Attachments are fetched by MIME part number — only the requested part is downloaded, not the entire message.

## Search

The fluent search builder produces IMAP SEARCH criteria:

```php
use D4ry\ImapClient\Search\Search;

$search = (new Search())
    ->unread()
    ->from('notifications@github.com')
    ->subject('Pull Request')
    ->after(new DateTime('-7 days'))
    ->before(new DateTime())
    ->smaller(1_000_000);

$messages = $folder->messages($search);
```

### Available Criteria

| Method | IMAP Criterion |
|--------|---------------|
| `unread()` | UNSEEN |
| `read()` | SEEN |
| `flagged()` | FLAGGED |
| `unflagged()` | UNFLAGGED |
| `answered()` | ANSWERED |
| `unanswered()` | UNANSWERED |
| `deleted()` | DELETED |
| `undeleted()` | UNDELETED |
| `draft()` | DRAFT |
| `recent()` | RECENT |
| `new()` | NEW |
| `before(DateTimeInterface)` | BEFORE date |
| `after(DateTimeInterface)` | SINCE date |
| `on(DateTimeInterface)` | ON date |
| `sentBefore(DateTimeInterface)` | SENTBEFORE date |
| `sentSince(DateTimeInterface)` | SENTSINCE date |
| `subject(string)` | SUBJECT string |
| `body(string)` | BODY string |
| `text(string)` | TEXT string |
| `from(string)` | FROM string |
| `to(string)` | TO string |
| `cc(string)` | CC string |
| `bcc(string)` | BCC string |
| `header(name, value)` | HEADER name value |
| `larger(int)` | LARGER n |
| `smaller(int)` | SMALLER n |
| `uid(SequenceSet)` | UID set |
| `keyword(string)` | KEYWORD flag |
| `modSeqSince(int)` | MODSEQ n (CONDSTORE) |

### Logical Operators

```php
// NOT
$search = (new Search())
    ->unread()
    ->not((new Search())->from('noreply@spam.com'));

// OR
$search = (new Search())->or(
    (new Search())->from('alice@example.com'),
    (new Search())->from('bob@example.com'),
);

// Complex nesting
$search = (new Search())
    ->unread()
    ->after(new DateTime('-30 days'))
    ->not(
        (new Search())->or(
            (new Search())->subject('Unsubscribe'),
            (new Search())->from('marketing@'),
        )
    );
```

## IDLE (Push Notifications)

Listen for real-time mailbox changes with typed events:

```php
use D4ry\ImapClient\Idle\AbstractIdleHandler;
use D4ry\ImapClient\Idle\MessageReceivedEvent;
use D4ry\ImapClient\Idle\MessageExpungedEvent;
use D4ry\ImapClient\Idle\FlagsChangedEvent;

class MyIdleHandler extends AbstractIdleHandler
{
    public function onMessageReceived(MessageReceivedEvent $event): bool
    {
        echo "New mail! Mailbox now has {$event->messageCount} messages.\n";
        return false; // stop IDLE to go fetch the new message
    }

    public function onMessageExpunged(MessageExpungedEvent $event): bool
    {
        echo "Message #{$event->sequenceNumber} was removed.\n";
        return true; // keep listening
    }

    public function onFlagsChanged(FlagsChangedEvent $event): bool
    {
        echo "Flags changed on #{$event->sequenceNumber}: ";
        echo implode(', ', $event->flags->flags) . "\n";
        return true;
    }
}

$mailbox->inbox()->select();
$mailbox->idle(new MyIdleHandler(), timeout: 600);
```

### Available Events

| Event | Trigger | Properties |
|-------|---------|------------|
| `MessageReceivedEvent` | `* N EXISTS` | `$messageCount` |
| `MessageExpungedEvent` | `* N EXPUNGE` | `$sequenceNumber` |
| `FlagsChangedEvent` | `* N FETCH (FLAGS ...)` | `$sequenceNumber`, `$flags` (FlagSet) |
| `RecentCountEvent` | `* N RECENT` | `$count` |
| `IdleHeartbeatEvent` | `* OK ...` | `$text` |

All events extend `IdleEvent` which provides `$rawLine` and `$timestamp`.

`AbstractIdleHandler` returns `true` (continue) for all events by default — override only the ones you care about. Return `false` from any handler method to stop IDLE.

### Closure-based (alternative)

```php
use D4ry\ImapClient\Idle\IdleEvent;
use D4ry\ImapClient\Idle\MessageReceivedEvent;

$mailbox->idle(function (IdleEvent $event) {
    if ($event instanceof MessageReceivedEvent) {
        echo "New mail!\n";
        return false; // stop IDLE
    }
    return true;
}, timeout: 300);
```

## Appending Messages

```php
use D4ry\ImapClient\Enum\Flag;

$raw = "From: me@example.com\r\nTo: you@example.com\r\nSubject: Test\r\n\r\nHello!";

$uid = $folder->append(
    rawMessage: $raw,
    flags: [Flag::Seen],
    internalDate: new DateTime(),
);
```

## Namespace

```php
$ns = $mailbox->namespace();

foreach ($ns->personal as $entry) {
    echo $entry['prefix'];    // e.g. "" or "INBOX."
    echo $entry['delimiter'];  // e.g. "/" or "."
}
```

## Raw Connection Access

For advanced use cases or commands not wrapped by the high-level API:

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

## Error Handling

All exceptions extend `D4ry\ImapClient\Exception\ImapException`:

```php
use D4ry\ImapClient\Exception\AuthenticationException;
use D4ry\ImapClient\Exception\CapabilityException;
use D4ry\ImapClient\Exception\CommandException;
use D4ry\ImapClient\Exception\ConnectionException;
use D4ry\ImapClient\Exception\ImapException;
use D4ry\ImapClient\Exception\ParseException;
use D4ry\ImapClient\Exception\ProtocolException;
use D4ry\ImapClient\Exception\TimeoutException;

try {
    $mailbox = Mailbox::connect($config);
} catch (ConnectionException $e) {
    // Socket/TLS failure
} catch (AuthenticationException $e) {
    // Bad credentials
}

try {
    $mailbox->namespace();
} catch (CapabilityException $e) {
    // Server doesn't support NAMESPACE
    echo $e->capability->value; // "NAMESPACE"
}

try {
    $transceiver->command('SELECT', 'NonExistent');
} catch (CommandException $e) {
    echo $e->command;      // "SELECT"
    echo $e->status;       // "NO"
    echo $e->responseText; // "Mailbox does not exist"
}
```

## Architecture

```
D4ry\ImapClient\
├── Auth\               # PLAIN, LOGIN, XOAUTH2 authentication
├── Collection\         # Lazy iterable collections (Folder, Message, Attachment)
├── Connection\         # Raw socket I/O with TLS/STARTTLS
├── Contract\           # Interfaces for Mailbox, Folder, Message, Attachment
├── Enum\               # Flag, Capability, Encryption, SpecialUse, ...
├── Exception\          # Exception hierarchy
├── Idle\               # Typed IDLE events and handler interface
├── Mime\               # RFC 5322/2047/2231 MIME parser
├── Protocol\           # IMAP wire protocol (commands, response parsing, transceiver)
├── Search\             # Fluent search builder
├── Support\            # Date formatting, IMAP literals
├── ValueObject\        # Uid, Address, Envelope, FlagSet, BodyStructure, ...
├── Attachment.php
├── Config.php
├── Folder.php
├── Mailbox.php         # Entry point
└── Message.php
```

## Supported IMAPv4rev2 Extensions

| Extension | RFC | Support |
|-----------|-----|---------|
| IMAP4rev1 | 3501 | Core |
| IMAP4rev2 | 9051 | Core |
| CONDSTORE | 7162 | `Config::enableCondstore` |
| QRESYNC | 7162 | `Config::enableQresync` |
| OBJECTID | 8474 | Auto (emailId/threadId) |
| MOVE | 6851 | Auto (fallback to COPY+DELETE) |
| STATUS=SIZE | 8438 | Auto |
| SAVEDATE | 8514 | Auto |
| UTF8=ACCEPT | 6855 | `Config::utf8Accept` |
| LIST-STATUS | 5819 | Auto |
| LITERAL- | 7888 | Auto |
| SPECIAL-USE | 6154 | Auto |
| SORT | 5256 | Via transceiver |
| THREAD | 5256 | Via transceiver |
| ID | 2971 | `Config::clientId` / `$mailbox->id()` |
| IDLE | 2177 | `$mailbox->idle()` |
| NAMESPACE | 2342 | `$mailbox->namespace()` |
| ENABLE | 5161 | Auto |
| UNSELECT | 3691 | Auto |
| SASL-IR | 4959 | Auto |

## License

Licensed under the [Apache License 2.0](LICENSE).
