# Working with Attachments

Attachments are discovered from the message's `BODYSTRUCTURE` response — no body data is transferred until you explicitly save or read the content. This means you can iterate attachment metadata on thousands of messages without downloading any payload.

```php
foreach ($message->attachments() as $attachment) {
    // Skip inline images (embedded in HTML)
    if ($attachment->isInline()) {
        echo "Inline: cid:" . $attachment->contentId() . "\n";
        continue;
    }

    echo $attachment->filename();   // "report.pdf"
    echo $attachment->mimeType();   // "application/pdf"
    echo $attachment->size();       // bytes (from BODYSTRUCTURE, before decoding)

    // Save to disk
    $attachment->save('/path/to/downloads');  // saves as /path/to/downloads/report.pdf

    // Or get raw decoded content
    $bytes = $attachment->content();
}
```

## Filtering

```php
$regular = $message->attachments()->nonInline();  // only real attachments
$inline  = $message->attachments()->inline();     // only embedded images
```

## Partial Fetches

Attachments are fetched by MIME part number — only the requested part is downloaded, not the entire message. A 50 MB email with a 12 KB text body costs 12 KB + the BODYSTRUCTURE round-trip.

## See also

- [Messages](messages.md)
