<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Protocol;

use D4ry\ImapClient\Protocol\Response\Response;
use D4ry\ImapClient\Protocol\Response\UntaggedResponse;

/**
 * Mutable state for an in-flight streaming FETCH.
 *
 * Required because the streaming generator must be able to yield individual
 * FETCH responses to consumer code, and that consumer code may issue nested
 * IMAP commands on the same Transceiver. Those nested commands need to drain
 * the rest of the streaming response into this object so the streaming
 * generator can resume from the queue instead of blocking on a socket that
 * has nothing left for it.
 */
final class StreamingFetchState
{
    /** @var list<UntaggedResponse> FETCH untagged responses awaiting consumer pull */
    public array $fetchQueue = [];

    /** @var list<UntaggedResponse> non-FETCH untagged responses (CAPABILITY etc.) */
    public array $otherUntagged = [];

    public ?Response $finalResponse = null;

    public bool $completed = false;

    public function __construct(public string $tag)
    {
    }
}
