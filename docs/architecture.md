# Architecture

The library is split into small, focused namespaces. Each layer has a single responsibility and can be used independently via the contracts in `Contract/`.

```
D4ry\ImapClient\
├── Auth\               # PLAIN, LOGIN, XOAUTH2 authentication
├── Collection\         # Lazy iterable collections (Folder, Message, Attachment)
├── Connection\         # Raw socket I/O with TLS/STARTTLS + logging/recording/replay decorators
├── Contract\           # Interfaces for Mailbox, Folder, Message, Attachment
├── Enum\               # Flag, Capability, Encryption, SpecialUse, Status, ...
├── Exception\          # Exception hierarchy (see Error Handling)
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

## Layering

- **Entry points** (`Mailbox`, `Folder`, `Message`, `Attachment`) are the high-level façade you normally interact with.
- **Protocol** implements the IMAP wire format. `Transceiver` sends commands and streams responses; `ResponseParser` decodes the grammar from RFC 9051.
- **Connection** owns the socket. Decorators (`LoggingConnection`, `RecordingConnection`, `ReplayConnection`) wrap the base `SocketConnection` without the upper layers knowing.
- **Collection** lazily materialises results — iterating a `MessageCollection` triggers FETCH on-demand rather than eagerly loading the whole folder.
- **ValueObject** holds immutable, type-safe domain data returned from parsing.

## See also

- [Raw Connection Access](advanced/raw-connection.md)
- [Recording & Replay](advanced/recording-replay.md)
- [Supported Extensions](extensions.md)
