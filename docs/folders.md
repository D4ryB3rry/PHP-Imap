# Working with Folders

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
```

## Folder Operations

```php
$folder->select();       // read-write
$folder->examine();      // read-only
$folder->create();
$folder->delete();
$folder->rename('New Name');
$folder->subscribe();
$folder->unsubscribe();
$folder->expunge();      // permanently remove \Deleted messages
```

## Child Folders

```php
foreach ($folder->children() as $child) {
    echo $child->name() . "\n";
}
```

## Folder Status

```php
$status = $folder->status();
echo $status->messages;      // total messages
echo $status->unseen;        // unread count
echo $status->uidNext;       // next UID
echo $status->uidValidity;
echo $status->highestModSeq; // requires CONDSTORE
echo $status->size;          // requires STATUS=SIZE
```

## See also

- [Messages](messages.md)
- [Search](search.md) — filtering a folder's messages
