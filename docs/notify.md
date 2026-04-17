# NOTIFY (Server-Push Events)

Subscribe to real-time server pushes for one or more mailboxes — message arrivals, expunges, flag changes, mailbox renames, subscription changes, metadata updates — without being pinned to a single mailbox like IDLE. NOTIFY (RFC 5465) is negotiated automatically when the server advertises the capability.

Unlike IDLE, a NOTIFY subscription stays alive across ordinary commands: the server may interleave untagged event responses into the reply of any command you issue.

## Quick Start

Listen on the INBOX and a few other folders, stop on first new mail:

```php
use D4ry\ImapClient\Notify\AbstractNotifyHandler;
use D4ry\ImapClient\Notify\MessageNewEvent;
use D4ry\ImapClient\Notify\FlagChangeEvent;

class MyHandler extends AbstractNotifyHandler
{
    public function onMessageNew(MessageNewEvent $event): bool
    {
        echo "New message #{$event->sequenceNumber} arrived\n";
        return false; // stop the drain loop
    }

    public function onFlagChange(FlagChangeEvent $event): bool
    {
        echo "Flags changed on #{$event->sequenceNumber}: "
            . implode(', ', $event->flags->flags) . "\n";
        return true;
    }
}

$mailbox->listenToFolders(
    [$mailbox->inbox(), 'Archive', 'Sent'],
    new MyHandler(),
    timeout: 600,
);
```

`listenToFolders()` registers a `NOTIFY SET (mailboxes ...)` subscription, drains events until the handler returns `false` or the timeout elapses, then tears the subscription down with `NOTIFY NONE`.

## Folder-Scoped Convenience

```php
$mailbox->inbox()->listen(function ($event) {
    // closure handler — any NotifyEvent subtype
    return $event instanceof \D4ry\ImapClient\Notify\MessageNewEvent ? false : true;
}, timeout: 300);
```

Pass `includeSubtree: true` to subscribe to the folder and every descendant.

## Events

| Event | Trigger | Properties |
|-------|---------|------------|
| `MessageNewEvent` | `* n FETCH (...)` after message arrival | `$sequenceNumber`, `$fetchData`, `$flags` |
| `MessageExpungedEvent` | `* n EXPUNGE` | `$sequenceNumber` |
| `FlagChangeEvent` | `* n FETCH (FLAGS ...)` | `$sequenceNumber`, `$flags` (FlagSet) |
| `MailboxStatusEvent` | `* STATUS mbox (...)` | `$mailbox`, `$attributes` |
| `MailboxNameEvent` | `* LIST (...)` for create/delete/rename | `$mailbox`, `$delimiter`, `$attributes`; `isNonExistent()` |
| `SubscriptionChangeEvent` | `* LIST (...)` / `* LSUB (...)` on (un)subscribe | `$mailbox`, `$delimiter`, `$attributes`; `isSubscribed()` |
| `AnnotationChangeEvent` | `* n FETCH (ANNOTATION ...)` | `$sequenceNumber`, `$fetchData` |
| `MailboxMetadataChangeEvent` | `* METADATA "mbox" (...)` | `$mailbox`, `$rawEntries` |
| `ServerMetadataChangeEvent` | `* METADATA "" (...)` | `$rawEntries` |

All events extend `NotifyEvent`, which provides `$rawLine` and `$timestamp`.

Return `true` from any handler method to continue the drain loop, `false` to stop.

`AbstractNotifyHandler` returns `true` for every event by default — override only the ones you care about.

## Closure Form

```php
use D4ry\ImapClient\Notify\NotifyEvent;
use D4ry\ImapClient\Notify\MessageNewEvent;

$mailbox->listenToFolders(
    [$mailbox->inbox()],
    function (NotifyEvent $event): bool {
        if ($event instanceof MessageNewEvent) {
            return false;
        }
        return true;
    },
    timeout: 300,
);
```

## Low-Level API

For custom filters, custom event lists, or passive dispatch, go through the primitives.

### `MailboxFilter` (RFC 5465 §5)

```php
use D4ry\ImapClient\Notify\MailboxFilter;

MailboxFilter::selected();           // only the selected mailbox
MailboxFilter::selectedDelayed();    // selected, with delayed delivery
MailboxFilter::inboxes();            // every mailbox named INBOX
MailboxFilter::personal();           // user's own namespace
MailboxFilter::subscribed();         // all subscribed mailboxes
MailboxFilter::subtree(['Work']);    // subtree roots
MailboxFilter::mailboxes(['Work/A', 'Sent']); // explicit list
```

### `EventGroup`

A filter plus the events you want delivered for it. `MessageNew` may carry a fetch-att list (same syntax as FETCH items) so new-message events arrive pre-loaded with whatever you request:

```php
use D4ry\ImapClient\Notify\EventGroup;
use D4ry\ImapClient\Notify\MailboxFilter;
use D4ry\ImapClient\Notify\NotifyEventType;

$group = new EventGroup(
    filter: MailboxFilter::subscribed(),
    events: [
        NotifyEventType::MessageNew,
        NotifyEventType::MessageExpunge,
        NotifyEventType::FlagChange,
    ],
    fetchAttributes: ['UID', 'FLAGS', 'ENVELOPE'],
);
```

Allowed fetch tokens: `UID`, `FLAGS`, `INTERNALDATE`, `RFC822.SIZE`, `ENVELOPE`, `BODYSTRUCTURE`, `MODSEQ`, `EMAILID`, `THREADID`, plus any `BODY[...]` / `BODY.PEEK[...]` section form.

### `Mailbox::notify()` — register subscription

```php
$mailbox->notify([$group1, $group2], includeStatus: true);
```

`includeStatus: true` adds the optional `STATUS` keyword so the server also pushes `* STATUS` for matching non-selected mailboxes.

### `Mailbox::notifyNone()` — disable

```php
$mailbox->notifyNone();
```

Also clears any passive-dispatch handler set via `setNotifyHandler()`.

### Passive Dispatch

Register a handler that classifies untagged events arriving *inside the reply of any other command* — no dedicated loop required:

```php
$mailbox->notify([$group]);
$mailbox->setNotifyHandler(new MyHandler());

// Any subsequent command may surface events to the handler.
$mailbox->inbox()->messages()->first(); // handler may fire during this
```

Handler return values are ignored in passive mode — there is no loop to break. Pass `null` to clear:

```php
$mailbox->setNotifyHandler(null);
```

### Active Drain Loop

Pump the socket directly for an already-registered subscription:

```php
$mailbox->notify([$group]);
$mailbox->listenForNotifications(new MyHandler(), timeout: 300);
$mailbox->notifyNone();
```

## Capability Check

```php
use D4ry\ImapClient\Enum\Capability;

if (!$mailbox->hasCapability(Capability::Notify)) {
    // fall back to IDLE or polling
}
```

All NOTIFY entry points automatically require the capability and throw `CapabilityNotSupportedException` if the server does not advertise it.

## NOTIFY vs IDLE

|  | IDLE | NOTIFY |
|--|------|--------|
| Mailbox scope | one (the selected one) | any number, any filter |
| Coexists with other commands | no (must end IDLE first) | yes — events interleave |
| Event types | EXISTS, EXPUNGE, FETCH | all of IDLE + LIST, STATUS, METADATA |
| Typical use | watch INBOX | watch multi-folder workflows |

## See also

- [IDLE](idle.md) — simpler, single-mailbox alternative
- [Folders](folders.md) — building the folder list to subscribe to
- [Messages](messages.md) — fetching after a `MessageNewEvent`
