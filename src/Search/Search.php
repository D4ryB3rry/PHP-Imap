<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Search;

use D4ry\ImapClient\Search\Contract\SearchCriteriaInterface;
use D4ry\ImapClient\Support\ImapDateFormatter;
use D4ry\ImapClient\ValueObject\SequenceSet;

class Search implements SearchCriteriaInterface
{
    /** @var string[] */
    private array $criteria = [];

    // --- Flag criteria ---

    public function unread(): self
    {
        $this->criteria[] = 'UNSEEN';
        return $this;
    }

    public function read(): self
    {
        $this->criteria[] = 'SEEN';
        return $this;
    }

    public function flagged(): self
    {
        $this->criteria[] = 'FLAGGED';
        return $this;
    }

    public function unflagged(): self
    {
        $this->criteria[] = 'UNFLAGGED';
        return $this;
    }

    public function answered(): self
    {
        $this->criteria[] = 'ANSWERED';
        return $this;
    }

    public function unanswered(): self
    {
        $this->criteria[] = 'UNANSWERED';
        return $this;
    }

    public function deleted(): self
    {
        $this->criteria[] = 'DELETED';
        return $this;
    }

    public function undeleted(): self
    {
        $this->criteria[] = 'UNDELETED';
        return $this;
    }

    public function draft(): self
    {
        $this->criteria[] = 'DRAFT';
        return $this;
    }

    public function undraft(): self
    {
        $this->criteria[] = 'UNDRAFT';
        return $this;
    }

    public function recent(): self
    {
        $this->criteria[] = 'RECENT';
        return $this;
    }

    public function new(): self
    {
        $this->criteria[] = 'NEW';
        return $this;
    }

    public function old(): self
    {
        $this->criteria[] = 'OLD';
        return $this;
    }

    // --- Date criteria ---

    public function before(\DateTimeInterface $date): self
    {
        $this->criteria[] = 'BEFORE ' . ImapDateFormatter::toImapDate($date);
        return $this;
    }

    public function after(\DateTimeInterface $date): self
    {
        $this->criteria[] = 'SINCE ' . ImapDateFormatter::toImapDate($date);
        return $this;
    }

    public function on(\DateTimeInterface $date): self
    {
        $this->criteria[] = 'ON ' . ImapDateFormatter::toImapDate($date);
        return $this;
    }

    public function since(\DateTimeInterface $date): self
    {
        $this->criteria[] = 'SINCE ' . ImapDateFormatter::toImapDate($date);
        return $this;
    }

    public function sentBefore(\DateTimeInterface $date): self
    {
        $this->criteria[] = 'SENTBEFORE ' . ImapDateFormatter::toImapDate($date);
        return $this;
    }

    public function sentSince(\DateTimeInterface $date): self
    {
        $this->criteria[] = 'SENTSINCE ' . ImapDateFormatter::toImapDate($date);
        return $this;
    }

    public function sentOn(\DateTimeInterface $date): self
    {
        $this->criteria[] = 'SENTON ' . ImapDateFormatter::toImapDate($date);
        return $this;
    }

    // --- String criteria ---

    public function subject(string $value): self
    {
        $this->criteria[] = 'SUBJECT ' . $this->quoteString($value);
        return $this;
    }

    public function body(string $value): self
    {
        $this->criteria[] = 'BODY ' . $this->quoteString($value);
        return $this;
    }

    public function text(string $value): self
    {
        $this->criteria[] = 'TEXT ' . $this->quoteString($value);
        return $this;
    }

    public function from(string $value): self
    {
        $this->criteria[] = 'FROM ' . $this->quoteString($value);
        return $this;
    }

    public function to(string $value): self
    {
        $this->criteria[] = 'TO ' . $this->quoteString($value);
        return $this;
    }

    public function cc(string $value): self
    {
        $this->criteria[] = 'CC ' . $this->quoteString($value);
        return $this;
    }

    public function bcc(string $value): self
    {
        $this->criteria[] = 'BCC ' . $this->quoteString($value);
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->criteria[] = 'HEADER ' . $this->quoteString($name) . ' ' . $this->quoteString($value);
        return $this;
    }

    // --- Size criteria ---

    public function larger(int $bytes): self
    {
        $this->criteria[] = 'LARGER ' . $bytes;
        return $this;
    }

    public function smaller(int $bytes): self
    {
        $this->criteria[] = 'SMALLER ' . $bytes;
        return $this;
    }

    // --- UID criteria ---

    public function uid(SequenceSet $set): self
    {
        $this->criteria[] = 'UID ' . $set->value;
        return $this;
    }

    // --- Keyword criteria ---

    public function keyword(string $keyword): self
    {
        $this->criteria[] = 'KEYWORD ' . $this->assertAtom($keyword);
        return $this;
    }

    public function unkeyword(string $keyword): self
    {
        $this->criteria[] = 'UNKEYWORD ' . $this->assertAtom($keyword);
        return $this;
    }

    // --- Logical operators ---

    public function not(SearchCriteriaInterface $criteria): self
    {
        $this->criteria[] = 'NOT (' . $criteria->compile() . ')';
        return $this;
    }

    public function or(SearchCriteriaInterface $a, SearchCriteriaInterface $b): self
    {
        $this->criteria[] = 'OR (' . $a->compile() . ') (' . $b->compile() . ')';
        return $this;
    }

    // --- Extension criteria ---

    public function modSeqSince(int $modSeq): self
    {
        $this->criteria[] = 'MODSEQ ' . $modSeq;
        return $this;
    }

    // --- All ---

    public function all(): self
    {
        $this->criteria[] = 'ALL';
        return $this;
    }

    // --- Compile ---

    public function compile(): string
    {
        if ($this->criteria === []) {
            return 'ALL';
        }

        return implode(' ', $this->criteria);
    }

    public function __toString(): string
    {
        return $this->compile();
    }

    /**
     * RFC 9051 quoted-string forbids CR, LF, and NUL — the IMAP grammar has
     * no escape mechanism for them inside DQUOTEs. Sending them raw would
     * either be reinterpreted as a new protocol line by the server (command
     * injection) or rejected outright. Callers that need to embed binary
     * payloads in a SEARCH must use the literal form, which this builder
     * does not emit. We surface the limit as a hard error rather than
     * silently stripping bytes the user explicitly passed.
     */
    private function quoteString(string $value): string
    {
        if (preg_match('/[\x00\r\n]/', $value) === 1) {
            throw new \InvalidArgumentException(
                'IMAP quoted-string may not contain NUL, CR, or LF (RFC 9051 §4.3); use a literal form for binary content.'
            );
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"' . $escaped . '"';
    }

    /**
     * RFC 9051 atom: 1*ATOM-CHAR, excluding atom-specials
     * (controls, SP, "(", ")", "{", "%", "*", '"', "\", "]").
     */
    private function assertAtom(string $value): string
    {
        if ($value === '' || preg_match('/[\x00-\x20\x7F(){%*"\\\\\]]/', $value) === 1) {
            throw new \InvalidArgumentException(
                'IMAP keyword must be a non-empty atom (no whitespace, controls, or atom-specials): ' . var_export($value, true)
            );
        }

        return $value;
    }
}
