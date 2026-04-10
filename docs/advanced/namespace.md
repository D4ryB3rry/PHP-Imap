# Namespace

Some servers expose multiple top-level namespaces (personal, other users, shared). The `NAMESPACE` command (RFC 2342) returns their prefixes and hierarchy delimiters.

```php
$ns = $mailbox->namespace();

foreach ($ns->personal as $entry) {
    echo $entry['prefix'];    // e.g. "" or "INBOX."
    echo $entry['delimiter'];  // e.g. "/" or "."
}
```

If the server does not advertise `NAMESPACE`, calling `$mailbox->namespace()` throws `CapabilityException` — see [Error Handling](../error-handling.md).
