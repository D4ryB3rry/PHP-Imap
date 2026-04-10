# Working with Messages

## Fetching Messages

```php
use D4ry\ImapClient\Enum\Flag;

// All messages
$messages = $folder->messages();

// By flag shorthand
$unread = $folder->messages(Flag::Seen);

// By search criteria (see the Search guide)
$filtered = $folder->messages(
    (new \D4ry\ImapClient\Search\Search())->unread()->from('boss@example.com')
);

// Single message by UID
$message = $folder->message(new \D4ry\ImapClient\ValueObject\Uid(12345));
```

## Reading Message Content

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

## Message Actions

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

## Appending Messages

Upload a raw RFC 5322 message into a folder (e.g. to store a sent copy in `Sent`):

```php
use D4ry\ImapClient\Enum\Flag;

$raw = "From: me@example.com\r\nTo: you@example.com\r\nSubject: Test\r\n\r\nHello!";

$uid = $folder->append(
    rawMessage: $raw,
    flags: [Flag::Seen],
    internalDate: new DateTime(),
);
```

## See also

- [Attachments](attachments.md)
- [Search](search.md)
- [Folders](folders.md)
