# Documentation

Full reference for `d4ry/imap-client`. For a high-level overview and quick start, see the [project README](../README.md).

## Guides

- [Getting Started](getting-started.md) — installation, first connection, minimal examples
- [Configuration](configuration.md) — `Config` constructor, CONDSTORE, QRESYNC, UTF8, ID
- [Authentication](authentication.md) — PLAIN, LOGIN, XOAUTH2 with auto-refresh
- [Folders](folders.md) — list, select, create, rename, status
- [Messages](messages.md) — fetch, read, flag, move, copy, append
- [Attachments](attachments.md) — iterate, filter, save, partial fetch
- [Search](search.md) — fluent builder, criteria, logical operators
- [IDLE (Push Notifications)](idle.md) — typed events, handler class, closure form
- [NOTIFY (Multi-Folder Push)](notify.md) — RFC 5465 subscriptions, passive + active dispatch
- [Error Handling](error-handling.md) — exception hierarchy, common failures

## Reference

- [Architecture](architecture.md) — namespace tree and subsystem map
- [Supported Extensions](extensions.md) — IMAP extensions with RFC references
- [Benchmarks](benchmarks.md) — full methodology, scenarios, and caveats
- [Testing](testing.md) — unit vs integration suites, provider credentials

## Advanced

- [Raw Connection Access](advanced/raw-connection.md) — direct socket/transceiver use
- [Namespace](advanced/namespace.md) — IMAP NAMESPACE entries
- [Recording & Replay](advanced/recording-replay.md) — capture sessions to JSONL and replay deterministically
