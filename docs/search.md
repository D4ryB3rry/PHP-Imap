# Search

The fluent search builder produces IMAP SEARCH criteria. Pass the builder to `$folder->messages()` and iteration will only yield matching messages.

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

## Available Criteria

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

## Logical Operators

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

## See also

- [Messages](messages.md)
- [Folders](folders.md)
