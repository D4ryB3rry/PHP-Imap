<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

/**
 * RFC 5465 §5 filter-events pair: a MailboxFilter plus the list of events
 * the server should push for that filter. Rendered on the wire as
 * `<filter> (<event1> <event2> ...)`, with MessageNew optionally carrying
 * a fetch-att list (same syntax as FETCH items).
 */
final readonly class EventGroup
{
    private const ALLOWED_FETCH_ATT = [
        'UID',
        'FLAGS',
        'INTERNALDATE',
        'RFC822.SIZE',
        'ENVELOPE',
        'BODYSTRUCTURE',
        'MODSEQ',
        'EMAILID',
        'THREADID',
    ];

    private const FETCH_ATT_SECTION_PATTERN = '/^BODY(?:\.PEEK)?\[[^\]]*\](?:<\d+(?:\.\d+)?>)?$/';

    /**
     * @param NotifyEventType[] $events
     * @param string[] $fetchAttributes MessageNew fetch-att tokens (UID, FLAGS, BODY.PEEK[HEADER.FIELDS (FROM SUBJECT)], ...).
     *                                  Ignored unless $events contains NotifyEventType::MessageNew.
     */
    public function __construct(
        public MailboxFilter $filter,
        public array $events,
        public array $fetchAttributes = [],
    ) {
        if ($events === []) {
            throw new \InvalidArgumentException('EventGroup requires at least one event');
        }

        if ($fetchAttributes !== [] && !$this->includesMessageNew()) {
            throw new \InvalidArgumentException('fetchAttributes are only valid when MessageNew is in the event list');
        }

        foreach ($fetchAttributes as $att) {
            self::validateFetchAttribute($att);
        }
    }

    public function toGroupToken(bool $utf8Enabled): string
    {
        $tokens = [];

        foreach ($this->events as $event) {
            if ($event === NotifyEventType::MessageNew && $this->fetchAttributes !== []) {
                $tokens[] = $event->value . ' (' . implode(' ', $this->fetchAttributes) . ')';
                continue;
            }

            $tokens[] = $event->value;
        }

        return sprintf(
            '(%s (%s))',
            $this->filter->toFilterToken($utf8Enabled),
            implode(' ', $tokens),
        );
    }

    private function includesMessageNew(): bool
    {
        foreach ($this->events as $event) {
            if ($event === NotifyEventType::MessageNew) {
                return true;
            }
        }

        return false;
    }

    private static function validateFetchAttribute(string $att): void
    {
        if (in_array($att, self::ALLOWED_FETCH_ATT, true)) {
            return;
        }

        if (preg_match(self::FETCH_ATT_SECTION_PATTERN, $att) === 1) {
            return;
        }

        throw new \InvalidArgumentException(sprintf('Invalid MessageNew fetch attribute: %s', $att));
    }
}
