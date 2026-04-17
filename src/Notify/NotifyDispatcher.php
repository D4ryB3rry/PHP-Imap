<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

use D4ry\ImapClient\Protocol\Response\UntaggedResponse;
use D4ry\ImapClient\ValueObject\FlagSet;

/**
 * Classifies untagged IMAP responses arriving under a NOTIFY subscription
 * and fans them out to a {@see NotifyHandlerInterface} (or a callable
 * equivalent). Used by both delivery channels:
 *
 * - **Passive:** registered as an untagged-response hook on the Transceiver
 *   so events delivered inside another command's reply (e.g. a FETCH's
 *   untaggeds) are dispatched transparently. Handler return values are
 *   ignored here — there is no loop to break.
 * - **Active:** driven by
 *   {@see \D4ry\ImapClient\Contract\MailboxInterface::listenForNotifications()}
 *   which pumps the socket directly; handler returning `false` breaks the
 *   drain loop.
 */
final class NotifyDispatcher
{
    /** @var NotifyHandlerInterface|callable */
    private $handler;

    public function __construct(NotifyHandlerInterface|callable $handler)
    {
        $this->handler = $handler;
    }

    /**
     * Classify one untagged response and invoke the handler. Returns false
     * only when the handler returned false (active-loop stop signal);
     * returns true when no handler method was matched or the handler kept
     * running.
     */
    public function dispatch(UntaggedResponse $untagged): bool
    {
        $event = $this->classify($untagged);

        if ($event === null) {
            return true;
        }

        return $this->invoke($event);
    }

    private function classify(UntaggedResponse $untagged): ?NotifyEvent
    {
        $raw = $untagged->raw ?? '';

        return match ($untagged->type) {
            'EXISTS' => new MessageNewEvent(
                $raw,
                $this->extractNumber($untagged),
                [],
            ),
            'EXPUNGE' => new MessageExpungedEvent(
                $raw,
                $this->extractNumber($untagged),
            ),
            'FETCH' => $this->classifyFetch($untagged, $raw),
            'STATUS' => $this->classifyStatus($untagged, $raw),
            'LIST' => $this->classifyList($untagged, $raw),
            'LSUB' => $this->classifyList($untagged, $raw, subscriptionOnly: true),
            'METADATA' => $this->classifyMetadata($untagged, $raw),
            default => null,
        };
    }

    private function classifyFetch(UntaggedResponse $untagged, string $raw): NotifyEvent
    {
        $data = is_array($untagged->data) ? $untagged->data : [];
        $seq = isset($data['seq']) && is_int($data['seq']) ? $data['seq'] : 0;

        $flags = $data['FLAGS'] ?? null;

        // FETCH containing only FLAGS (plus the implicit 'seq' key) is a
        // FlagChange notification. Any other item present means we're
        // looking at MessageNew carrying fetch-att, or an AnnotationChange
        // if the payload is the ANNOTATION structure — the parser surfaces
        // that under the 'ANNOTATION' key when present.
        if (array_key_exists('ANNOTATION', $data)) {
            return new AnnotationChangeEvent($raw, $seq, $data);
        }

        if ($flags instanceof FlagSet && $this->isFlagsOnly($data)) {
            return new FlagChangeEvent($raw, $seq, $flags);
        }

        return new MessageNewEvent(
            $raw,
            $seq,
            $data,
            $flags instanceof FlagSet ? $flags : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isFlagsOnly(array $data): bool
    {
        foreach ($data as $key => $_) {
            if ($key === 'seq' || $key === 'FLAGS') {
                continue;
            }

            return false;
        }

        return true;
    }

    private function classifyStatus(UntaggedResponse $untagged, string $raw): ?MailboxStatusEvent
    {
        $data = is_array($untagged->data) ? $untagged->data : [];

        $mailbox = isset($data['mailbox']) && is_string($data['mailbox']) ? $data['mailbox'] : '';
        $attributes = isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : [];

        if ($mailbox === '') {
            return null;
        }

        return new MailboxStatusEvent($raw, $mailbox, $attributes);
    }

    private function classifyList(UntaggedResponse $untagged, string $raw, bool $subscriptionOnly = false): ?NotifyEvent
    {
        $data = is_array($untagged->data) ? $untagged->data : [];

        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        $delimiter = isset($data['delimiter']) && is_string($data['delimiter']) ? $data['delimiter'] : '';
        $attributes = isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : [];

        if ($name === '') {
            return null;
        }

        if ($subscriptionOnly) {
            return new SubscriptionChangeEvent($raw, $name, $delimiter, $attributes);
        }

        return new MailboxNameEvent($raw, $name, $delimiter, $attributes);
    }

    private function classifyMetadata(UntaggedResponse $untagged, string $raw): NotifyEvent
    {
        // ResponseParser does not crack METADATA, so the untagged payload
        // arrives verbatim as: `"mbox" (entry value ...)` (per-mailbox) or
        // `"" (entry value ...)` (server scope). We split on the first
        // whitespace that separates the mailbox argument from the entry
        // list and hand both halves through raw — consumers that care
        // about entries parse them themselves against RFC 5464.
        $payload = is_string($untagged->data) ? trim($untagged->data) : '';

        [$mailbox, $rawEntries] = self::splitMetadataPayload($payload);

        if ($mailbox === '') {
            return new ServerMetadataChangeEvent($raw, $rawEntries);
        }

        return new MailboxMetadataChangeEvent($raw, $mailbox, $rawEntries);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitMetadataPayload(string $payload): array
    {
        if ($payload === '') {
            return ['', ''];
        }

        // Mailbox argument is always either a quoted-string or a bare atom
        // terminated by whitespace; the remainder (after trimming) is the
        // entry list. `\s*` inside the regex already eats every whitespace
        // character between the closing quote and the entries, so `$m[2]`
        // never carries leading whitespace. The PregMatchRemoveDollar
        // mutant on the `$` end-anchor is equivalent because the greedy
        // `.*` under the `s` flag matches to end-of-string regardless.
        // @infection-ignore-all
        if ($payload[0] === '"' && preg_match('/^"((?:\\\\.|[^"\\\\])*)"\s*(.*)$/s', $payload, $m) === 1) {
            return [stripcslashes($m[1]), $m[2]];
        }

        // Bare atom mailbox: first whitespace run delimits the entries.
        // preg_split() collapses consecutive whitespace characters, so the
        // entries half never carries leading whitespace and no additional
        // trimming is needed.
        $parts = preg_split('/\s+/', $payload, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private function extractNumber(UntaggedResponse $untagged): int
    {
        if (!is_array($untagged->data)) {
            return 0;
        }

        $n = $untagged->data['number'] ?? 0;

        return is_int($n) ? $n : 0;
    }

    private function invoke(NotifyEvent $event): bool
    {
        $handler = $this->handler;

        if ($handler instanceof NotifyHandlerInterface) {
            return match (true) {
                $event instanceof MessageNewEvent => $handler->onMessageNew($event),
                $event instanceof MessageExpungedEvent => $handler->onMessageExpunged($event),
                $event instanceof FlagChangeEvent => $handler->onFlagChange($event),
                $event instanceof MailboxNameEvent => $handler->onMailboxName($event),
                $event instanceof SubscriptionChangeEvent => $handler->onSubscriptionChange($event),
                $event instanceof AnnotationChangeEvent => $handler->onAnnotationChange($event),
                $event instanceof MailboxMetadataChangeEvent => $handler->onMailboxMetadataChange($event),
                $event instanceof ServerMetadataChangeEvent => $handler->onServerMetadataChange($event),
                $event instanceof MailboxStatusEvent => $handler->onMailboxStatus($event),
            };
        }

        $result = $handler($event);

        return $result !== false;
    }
}
