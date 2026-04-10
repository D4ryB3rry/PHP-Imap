# IDLE (Push Notifications)

Listen for real-time mailbox changes with typed events. IDLE is negotiated automatically when the server advertises the capability.

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

## Available Events

| Event | Trigger | Properties |
|-------|---------|------------|
| `MessageReceivedEvent` | `* N EXISTS` | `$messageCount` |
| `MessageExpungedEvent` | `* N EXPUNGE` | `$sequenceNumber` |
| `FlagsChangedEvent` | `* N FETCH (FLAGS ...)` | `$sequenceNumber`, `$flags` (FlagSet) |
| `RecentCountEvent` | `* N RECENT` | `$count` |
| `IdleHeartbeatEvent` | `* OK ...` | `$text` |

All events extend `IdleEvent`, which provides `$rawLine` and `$timestamp`.

`AbstractIdleHandler` returns `true` (continue) for all events by default — override only the ones you care about. Return `false` from any handler method to stop IDLE.

## Closure-based Alternative

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

## See also

- [Messages](messages.md) — fetching after an IDLE notification
