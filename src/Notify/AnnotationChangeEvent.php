<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

/**
 * RFC 5465 AnnotationChange: a `* n FETCH (ANNOTATION ...)` delivered
 * because a per-message annotation changed. The annotations payload is
 * handed through as the raw FETCH data map since the parser does not yet
 * crack annotation structures into typed objects.
 */
final class AnnotationChangeEvent extends NotifyEvent
{
    /**
     * @param array<string, mixed> $fetchData
     */
    public function __construct(
        string $rawLine,
        public readonly int $sequenceNumber,
        public readonly array $fetchData,
    ) {
        parent::__construct($rawLine);
    }
}
