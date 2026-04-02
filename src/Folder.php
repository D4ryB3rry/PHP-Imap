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
        return new MessageCollection(function () use ($criteria): array {
            $this->select();

            $searchCriteria = match (true) {
                $criteria === null => 'ALL',
                $criteria instanceof Flag => $this->flagToSearchCriteria($criteria),
                $criteria instanceof SearchCriteriaInterface => $criteria->compile(),
            };

            $searchResult = $this->performSearch($searchCriteria);

            if ($searchResult->isEmpty()) {
                return [];
            }

            return $this->fetchMessages($searchResult->uids);
        });
    }

    public function message(Uid $uid): MessageInterface
    {
        $this->select();

        $messages = $this->fetchMessages([$uid]);

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
    private function fetchMessages(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $uidList = implode(',', array_map(fn(Uid $u) => $u->value, $uids));

        $fetchItems = ['UID', 'FLAGS', 'ENVELOPE', 'INTERNALDATE', 'RFC822.SIZE'];

        if ($this->transceiver->hasCapability(\D4ry\ImapClient\Enum\Capability::Condstore)) {
            $fetchItems[] = 'MODSEQ';
        }

        if ($this->transceiver->hasCapability(\D4ry\ImapClient\Enum\Capability::ObjectId)) {
            $fetchItems[] = 'EMAILID';
            $fetchItems[] = 'THREADID';
        }

        $response = $this->transceiver->command(
            'UID FETCH',
            $uidList,
            '(' . implode(' ', $fetchItems) . ')',
        );

        $messages = [];

        foreach ($response->untagged as $untagged) {
            if ($untagged->type !== 'FETCH' || !is_array($untagged->data)) {
                continue;
            }

            $data = $untagged->data;
            $uid = $data['UID'] ?? null;
            if (!$uid instanceof Uid) {
                continue;
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

            $messages[] = new Message(
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
            );
        }

        return $messages;
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
