# Configuration

The `Config` value object holds everything needed to open a connection: host, credentials, transport options, and feature toggles for optional IMAP extensions.

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

## Fields

| Field | Purpose |
|---|---|
| `host` | IMAP server hostname |
| `credential` | A `CredentialInterface` implementation — see [Authentication](authentication.md) |
| `port` | TCP port (defaults to 993 for TLS, 143 for StartTls/None) |
| `encryption` | `Encryption::Tls`, `Encryption::StartTls`, or `Encryption::None` |
| `timeout` | Socket timeout in seconds (float) |
| `enableCondstore` | Negotiates CONDSTORE for mod-sequence tracking |
| `enableQresync` | Negotiates QRESYNC for efficient resync after reconnect |
| `utf8Accept` | Sends `ENABLE UTF8=ACCEPT` for UTF-8 mailbox names and headers |
| `clientId` | Array sent via the `ID` command to identify your client |
| `recordPath` | Path to write a session recording — see [Recording & Replay](advanced/recording-replay.md) |
| `logPath` | Path to write a human-readable IMAP trace |

## See also

- [Authentication](authentication.md)
- [Supported Extensions](extensions.md)
