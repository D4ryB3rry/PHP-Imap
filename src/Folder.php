<?php

declare(strict_types=1);

namespace D4ry\ImapClient;

use D4ry\ImapClient\Collection\FolderCollection;
use D4ry\ImapClient\Collection\MessageCollection;
use D4ry\ImapClient\Contract\FolderInterface;
use D4ry\ImapClient\Contract\MessageInterface;
use D4ry\ImapClient\Enum\Flag;
use D4ry\ImapClient\Enum\SpecialUse;
use D4ry\ImapClient\Enum\StatusAttribute;
use D4ry\ImapClient\Protocol\Command\CommandBuilder;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\Search\Contract\SearchCriteriaInterface;
use D4ry\ImapClient\Search\Search;
use D4ry\ImapClient\Search\SearchResult;
use D4ry\ImapClient\Support\ImapDateFormatter;
use D4ry\ImapClient\ValueObject\Envelope;
use D4ry\ImapClient\ValueObject\FlagSet;
use D4ry\ImapClient\ValueObject\MailboxPath;
use D4ry\ImapClient\ValueObject\MailboxStatus;
use D4ry\ImapClient\ValueObject\SequenceNumber;
use D4ry\ImapClient\ValueObject\Uid;

class Folder implements FolderInterface
{
    private ?MailboxStatus $cachedStatus = null;

    public function __construct(
        private readonly Transceiver $transceiver,
        private MailboxPath $mailboxPath,
        private readonly ?SpecialUse $specialUseAttr = null,
        private readonly array $attributes = [],
    ) {
    }

    public function path(): MailboxPath
    {
        return $this->mailboxPath;
    }

    public function name(): string
    {
        return $this->mailboxPath->name();
    }

    public function status(): MailboxStatus
    {
        if ($this->cachedStatus !== null) {
            return $this->cachedStatus;
        }

        $encoded = $this->encodedPath();

        $attrs = [
            StatusAttribute::Messages->value,
            StatusAttribute::Recent->value,
            StatusAttribute::UidNext->value,
            StatusAttribute::UidValidity->value,
            StatusAttribute::Unseen->value,
        ];

        if ($this->transceiver->hasCapability(\D4ry\ImapClient\Enum\Capability::Condstore)) {
            $attrs[] = StatusAttribute::HighestModSeq->value;
        }

        if ($this->transceiver->hasCapability(\D4ry\ImapClient\Enum\Capability::StatusSize)) {
            $attrs[] = StatusAttribute::Size->value;
        }

        $response = $this->transceiver->command(
            'STATUS',
            $encoded,
            '(' . implode(' ', $attrs) . ')',
        );

        $statusData = null;
        foreach ($response->untagged as $untagged) {
            if ($untagged->type === 'STATUS' && is_array($untagged->data)) {
                $statusData = $untagged->data['attributes'] ?? [];
                break;
            }
        }

        $statusData ??= [];

        $this->cachedStatus = new MailboxStatus(
            messages: $statusData['MESSAGES'] ?? 0,
            recent: $statusData['RECENT'] ?? 0,
            uidNext: $statusData['UIDNEXT'] ?? 0,
            uidValidity: $statusData['UIDVALIDITY'] ?? 0,
            unseen: $statusData['UNSEEN'] ?? 0,
            highestModSeq: $statusData['HIGHESTMODSEQ'] ?? null,
            size: $statusData['SIZE'] ?? null,
        );

        return $this->cachedStatus;
    }

    public function specialUse(): ?SpecialUse
    {
        return $this->specialUseAttr;
    }

    public function messages(Flag|SearchCriteriaInterface|null $criteria = null): MessageCollection
    {
        return new MessageCollection(function () use ($criteria): \Generator {
            $this->select();

            // Fast path: no criteria → skip the UID SEARCH ALL roundtrip
            // entirely and FETCH the whole mailbox by sequence range. For
            // mailboxes with tens of thousands of messages this saves both
            // a full server-side search and parsing ~10k UIDs we'd just
            // re-send straight back to the server.
            if ($criteria === null) {
                return yield from $this->streamFetchMessages('1:*', useUid: false);
            }

            $searchCriteria = $criteria instanceof Flag
                ? $this->flagToSearchCriteria($criteria)
                : $criteria->compile();

            $searchResult = $this->performSearch($searchCriteria);

            if ($searchResult->isEmpty()) {
                return;
            }

            // Compress the UID list to sequence ranges (e.g. 1:5,7,9:12)
            // before sending. A literal comma list of 10k UIDs is ~50 KB on
            // the wire and the server processes it noticeably slower than a
            // compact range expression.
            $set = $this->compressUidsToSet($searchResult->uids);

            return yield from $this->streamFetchMessages($set, useUid: true);
        });
    }

    /**
     * Fetch a contiguous range of messages by sequence number (default) or
     * by UID. Useful for "first N" / "last N" / paging benchmarks where the
     * full mailbox FETCH from {@see messages()} would otherwise stream every
     * message over the wire — even if the consumer breaks out of the loop
     * early — bloating logs and wasting bandwidth.
     */
    public function messagesRange(int $from, int $to, bool $useUid = false, bool $withBodyStructure = false): MessageCollection
    {
        if ($from < 1 || $to < $from) {
            throw new \InvalidArgumentException(
                sprintf('Invalid message range: %d:%d', $from, $to)
            );
        }

        return new MessageCollection(function () use ($from, $to, $useUid, $withBodyStructure): \Generator {
            $this->select();

            return yield from $this->streamFetchMessages($from . ':' . $to, useUid: $useUid, withBodyStructure: $withBodyStructure);
        });
    }

    public function message(Uid $uid): MessageInterface
    {
        $this->select();

        // Eagerly fetch BODYSTRUCTURE in the same round-trip as the envelope.
        // Callers that resolve a single message by UID overwhelmingly go on to
        // touch attachments() / text() / html() — all of which need the
        // structure. Bundling it here removes one server round-trip per
        // message and is the dominant fix for benchmark 03.
        $messages = $this->fetchMessages([$uid], withBodyStructure: true);

        if ($messages === []) {
            throw new Exception\ImapException(
                sprintf('Message with UID %d not found in %s', $uid->value, $this->mailboxPath->path)
            );
        }

        return $messages[0];
    }

    public function search(SearchCriteriaInterface $criteria): SearchResult
    {
        $this->select();

        return $this->performSearch($criteria->compile());
    }

    public function select(): self
    {
        if ($this->transceiver->selectedMailbox === $this->mailboxPath->path) {
            return $this;
        }

        $this->transceiver->command('SELECT', $this->encodedPath());
        $this->transceiver->selectedMailbox = $this->mailboxPath->path;
        $this->cachedStatus = null;

        return $this;
    }

    public function examine(): self
    {
        $this->transceiver->command('EXAMINE', $this->encodedPath());
        $this->transceiver->selectedMailbox = $this->mailboxPath->path;
        $this->cachedStatus = null;

        return $this;
    }

    public function create(): self
    {
        $this->transceiver->command('CREATE', $this->encodedPath());

        return $this;
    }

    public function delete(): void
    {
        if ($this->transceiver->selectedMailbox === $this->mailboxPath->path) {
            if ($this->transceiver->hasCapability(\D4ry\ImapClient\Enum\Capability::Unselect)) {
                $this->transceiver->command('UNSELECT');
            } else {
                $this->transceiver->command('CLOSE');
            }
            $this->transceiver->selectedMailbox = null;
        }

        $this->transceiver->command('DELETE', $this->encodedPath());
    }

    public function rename(string $newName): self
    {
        $newPath = $this->mailboxPath->parent()?->child($newName) ?? new MailboxPath($newName, $this->mailboxPath->delimiter);

        $encodedOld = $this->encodedPath();
        $encodedNew = CommandBuilder::encodeMailboxName(
            $newPath->path,
            $this->transceiver->isUtf8Enabled(),
        );

        $this->transceiver->command('RENAME', $encodedOld, $encodedNew);

        $this->mailboxPath = $newPath;

        return $this;
    }

    public function subscribe(): self
    {
        $this->transceiver->command('SUBSCRIBE', $this->encodedPath());

        return $this;
    }

    public function unsubscribe(): self
    {
        $this->transceiver->command('UNSUBSCRIBE', $this->encodedPath());

        return $this;
    }

    public function expunge(): void
    {
        $this->select();
        $this->transceiver->command('EXPUNGE');
    }

    public function children(): FolderCollection
    {
        return new FolderCollection(function (): array {
            $pattern = $this->mailboxPath->path . $this->mailboxPath->delimiter . '%';
            $encoded = CommandBuilder::encodeMailboxName($pattern, $this->transceiver->isUtf8Enabled());

            $response = $this->transceiver->command('LIST', '""', $encoded);

            return $this->parseFolderList($response->untagged);
        });
    }

    public function append(string $rawMessage, array $flags = [], ?\DateTimeInterface $internalDate = null): ?Uid
    {
        $args = [$this->encodedPath()];

        if ($flags !== []) {
            $flagStrings = array_map(fn(Flag|string $f) => $f instanceof Flag ? $f->value : $f, $flags);
            $args[] = '(' . implode(' ', $flagStrings) . ')';
        }

        if ($internalDate !== null) {
            $args[] = '"' . ImapDateFormatter::toImapDateTime($internalDate) . '"';
        }

        $args[] = '{' . strlen($rawMessage) . '}';

        // append() bypasses Transceiver::command() and writes directly to the
        // socket, so it must drain any in-flight streaming FETCH itself.
        $this->transceiver->drainStreamingFetch();

        $tag = $this->transceiver->getTagGenerator()->next();
        $line = $tag->value . ' APPEND ' . implode(' ', $args) . "\r\n";
        $this->transceiver->getConnection()->write($line);

        // Wait for continuation
        $contResponse = $this->transceiver->readResponseForTag($tag->value);

        if ($contResponse->tag === '+') {
            $this->transceiver->getConnection()->write($rawMessage . "\r\n");
            $response = $this->transceiver->readResponseForTag($tag->value);

            // Check APPENDUID response code
            if ($response->responseCode !== null && preg_match('/APPENDUID\s+\d+\s+(\d+)/', $response->responseCode, $m)) {
                return new Uid((int) $m[1]);
            }

            return null;
        }

        return null;
    }

    private function performSearch(string $criteria): SearchResult
    {
        $response = $this->transceiver->command('UID SEARCH', $criteria);

        $uids = [];
        $highestModSeq = null;

        foreach ($response->untagged as $untagged) {
            if ($untagged->type === 'SEARCH' && is_array($untagged->data)) {
                $uids = array_map(fn(int $id) => new Uid($id), $untagged->data);
            }
        }

        return new SearchResult($uids, $highestModSeq);
    }

    /**
     * @param Uid[] $uids
     * @return MessageInterface[]
     */
    private function fetchMessages(array $uids, bool $withBodyStructure = false): array
    {
        if ($uids === []) {
            return [];
        }

        $set = $this->compressUidsToSet($uids);

        return iterator_to_array(
            $this->streamFetchMessages($set, useUid: true, withBodyStructure: $withBodyStructure),
            false,
        );
    }

    /**
     * Stream messages from a UID FETCH (or sequence FETCH) so the
     * MessageCollection can hand them to consumers as they arrive instead of
     * buffering all of them in memory before the first `foreach` iteration.
     *
     * The OBJECTID quirk handling lives here too: if a server advertises the
     * capability but rejects EMAILID/THREADID, we catch the BAD on the first
     * attempt (which always arrives before any FETCH untagged response, so
     * nothing has been yielded yet) and re-issue the FETCH with the items
     * stripped.
     *
     * @return \Generator<int, MessageInterface>
     */
    private function streamFetchMessages(string $sequenceSet, bool $useUid, bool $withBodyStructure = false): \Generator
    {
        $command = $useUid ? 'UID FETCH' : 'FETCH';
        [$fetchItems, $wantObjectId, $baseItems] = $this->buildFetchItems($withBodyStructure);

        try {
            foreach ($this->transceiver->commandStreamingFetch(
                $command,
                $sequenceSet,
                '(' . implode(' ', $fetchItems) . ')',
            ) as $untagged) {
                $message = $this->messageFromFetchData($untagged->data);
                if ($message !== null) {
                    yield $message;
                }
            }

            return;
        } catch (\D4ry\ImapClient\Exception\CommandException $e) {
            $isObjectIdReject = $wantObjectId
                && $e->status === 'BAD'
                && (
                    stripos($e->responseText, 'EMAILID') !== false
                    || stripos($e->responseText, 'THREADID') !== false
                );

            if (!$isObjectIdReject) {
                throw $e;
            }

            $this->transceiver->objectIdFetchItemsDisabled = true;
        }

        foreach ($this->transceiver->commandStreamingFetch(
            $command,
            $sequenceSet,
            '(' . implode(' ', $baseItems) . ')',
        ) as $untagged) {
            $message = $this->messageFromFetchData($untagged->data);
            if ($message !== null) {
                yield $message;
            }
        }
    }

    /**
     * @return array{0: string[], 1: bool, 2: string[]}
     */
    private function buildFetchItems(bool $withBodyStructure = false): array
    {
        $baseItems = ['UID', 'FLAGS', 'ENVELOPE', 'INTERNALDATE', 'RFC822.SIZE'];

        if ($this->transceiver->hasCapability(\D4ry\ImapClient\Enum\Capability::Condstore)) {
            $baseItems[] = 'MODSEQ';
        }

        if ($withBodyStructure) {
            $baseItems[] = 'BODYSTRUCTURE';
        }

        $wantObjectId = $this->transceiver->hasCapability(\D4ry\ImapClient\Enum\Capability::ObjectId)
            && !$this->transceiver->objectIdFetchItemsDisabled;

        $fetchItems = $baseItems;
        if ($wantObjectId) {
            $fetchItems[] = 'EMAILID';
            $fetchItems[] = 'THREADID';
        }

        return [$fetchItems, $wantObjectId, $baseItems];
    }

    private function messageFromFetchData(array $data): ?Message
    {
        $uid = $data['UID'] ?? null;
        if (!$uid instanceof Uid) {
            return null;
        }

        $envelope = $data['ENVELOPE'] ?? new Envelope(null, null, [], [], [], [], [], [], null, null);
        $flags = $data['FLAGS'] ?? new FlagSet();
        $dateStr = $data['INTERNALDATE'] ?? null;
        $size = $data['RFC822.SIZE'] ?? 0;

        $date = new \DateTimeImmutable();
        if ($dateStr !== null) {
            try {
                $date = ImapDateFormatter::parse($dateStr);
            } catch (\Exception) {
            }
        }

        $bodyStructure = $data['BODYSTRUCTURE'] ?? null;
        if (!$bodyStructure instanceof \D4ry\ImapClient\ValueObject\BodyStructure) {
            $bodyStructure = null;
        }

        return new Message(
            transceiver: $this->transceiver,
            uid: $uid,
            sequenceNumber: new SequenceNumber($data['seq'] ?? 0),
            envelope: $envelope,
            flags: $flags,
            internalDate: $date,
            size: $size,
            folderPath: $this->mailboxPath->path,
            emailIdValue: $data['EMAILID'] ?? null,
            threadIdValue: $data['THREADID'] ?? null,
            modSeqValue: $data['MODSEQ'] ?? null,
            bodyStructure: $bodyStructure,
        );
    }

    /**
     * Compress a UID list into an IMAP sequence-set with contiguous ranges,
     * e.g. [1,2,3,5,7,8] → "1:3,5,7:8". Drastically shrinks the UID FETCH
     * command line for mailboxes where most UIDs are contiguous (the common
     * case for "all messages" SEARCH results).
     *
     * @param Uid[] $uids
     */
    private function compressUidsToSet(array $uids): string
    {
        $values = [];
        foreach ($uids as $uid) {
            $values[] = $uid->value;
        }
        sort($values);

        $ranges = [];
        $start = $end = $values[0];

        $count = count($values);
        for ($i = 1; $i < $count; $i++) {
            $value = $values[$i];

            if ($value === $end + 1) {
                $end = $value;
                continue;
            }

            if ($value === $end) {
                continue; // dedupe duplicate UIDs
            }

            $ranges[] = $start === $end ? (string) $start : $start . ':' . $end;
            $start = $end = $value;
        }

        $ranges[] = $start === $end ? (string) $start : $start . ':' . $end;

        return implode(',', $ranges);
    }

    private function flagToSearchCriteria(Flag $flag): string
    {
        return match ($flag) {
            Flag::Seen => 'SEEN',
            Flag::Answered => 'ANSWERED',
            Flag::Flagged => 'FLAGGED',
            Flag::Deleted => 'DELETED',
            Flag::Draft => 'DRAFT',
            Flag::Recent => 'RECENT',
        };
    }

    /**
     * @param \D4ry\ImapClient\Protocol\Response\UntaggedResponse[] $untaggedResponses
     * @return FolderInterface[]
     */
    private function parseFolderList(array $untaggedResponses): array
    {
        $folders = [];

        foreach ($untaggedResponses as $untagged) {
            if ($untagged->type !== 'LIST' || !is_array($untagged->data)) {
                continue;
            }

            $data = $untagged->data;
            $attrs = $data['attributes'] ?? [];
            $delimiter = $data['delimiter'] ?? '/';
            $rawName = $data['name'] ?? '';

            if ($rawName === '') {
                continue;
            }

            $name = CommandBuilder::decodeMailboxName($rawName, $this->transceiver->isUtf8Enabled());

            $specialUse = null;
            foreach ($attrs as $attr) {
                $specialUse = SpecialUse::tryFrom($attr);
                if ($specialUse !== null) {
                    break;
                }
            }

            $folders[] = new self(
                transceiver: $this->transceiver,
                mailboxPath: new MailboxPath($name, $delimiter),
                specialUseAttr: $specialUse,
                attributes: $attrs,
            );
        }

        return $folders;
    }

    private function encodedPath(): string
    {
        return CommandBuilder::encodeMailboxName(
            $this->mailboxPath->path,
            $this->transceiver->isUtf8Enabled(),
        );
    }
}
