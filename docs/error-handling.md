# Error Handling

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

## Exception Hierarchy

| Exception | When it's thrown |
|---|---|
| `ConnectionException` | Socket/TLS failure during connect or mid-session |
| `AuthenticationException` | Server rejected credentials |
| `CapabilityException` | Server lacks a capability the caller requested |
| `CommandException` | Server returned `NO` or `BAD` for a command |
| `ParseException` | Server response didn't match the IMAP grammar |
| `ProtocolException` | Unexpected state transition (e.g. untagged data mid-literal) |
| `TimeoutException` | Socket read exceeded the configured timeout |

Catch `ImapException` to handle any of the above generically.
