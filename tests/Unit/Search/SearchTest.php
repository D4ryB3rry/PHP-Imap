<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Search;

use D4ry\ImapClient\Search\Search;
use D4ry\ImapClient\Support\ImapDateFormatter;
use D4ry\ImapClient\ValueObject\SequenceSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Search::class)]
#[UsesClass(ImapDateFormatter::class)]
#[UsesClass(SequenceSet::class)]
final class SearchTest extends TestCase
{
    public function testEmptySearchCompilesToAll(): void
    {
        self::assertSame('ALL', (new Search())->compile());
    }

    public function testFlagCriteria(): void
    {
        $search = (new Search())->unread()->flagged()->answered();

        self::assertSame('UNSEEN FLAGGED ANSWERED', $search->compile());
    }

    public function testNegativeFlagCriteria(): void
    {
        self::assertSame('SEEN', (new Search())->read()->compile());
        self::assertSame('UNFLAGGED', (new Search())->unflagged()->compile());
        self::assertSame('UNDELETED', (new Search())->undeleted()->compile());
        self::assertSame('UNANSWERED', (new Search())->unanswered()->compile());
        self::assertSame('UNDRAFT', (new Search())->undraft()->compile());
    }

    public function testDateCriteriaUseImapDateFormat(): void
    {
        $date = new \DateTimeImmutable('2026-04-07');
        $search = (new Search())->before($date)->after($date)->on($date);

        self::assertSame('BEFORE 7-Apr-2026 SINCE 7-Apr-2026 ON 7-Apr-2026', $search->compile());
    }

    public function testSentDateCriteria(): void
    {
        $date = new \DateTimeImmutable('2026-04-07');
        $search = (new Search())->sentBefore($date)->sentSince($date)->sentOn($date);

        self::assertSame('SENTBEFORE 7-Apr-2026 SENTSINCE 7-Apr-2026 SENTON 7-Apr-2026', $search->compile());
    }

    public function testStringCriteriaAreQuoted(): void
    {
        $search = (new Search())->subject('Hello World')->from('alice@example.com');

        self::assertSame('SUBJECT "Hello World" FROM "alice@example.com"', $search->compile());
    }

    public function testStringCriteriaEscapesQuotesAndBackslash(): void
    {
        $search = (new Search())->subject('Say "hi" \\there');

        self::assertSame('SUBJECT "Say \\"hi\\" \\\\there"', $search->compile());
    }

    public function testHeaderCriterion(): void
    {
        self::assertSame(
            'HEADER "X-Spam" "yes"',
            (new Search())->header('X-Spam', 'yes')->compile()
        );
    }

    public function testSizeCriteria(): void
    {
        self::assertSame('LARGER 1024 SMALLER 4096', (new Search())->larger(1024)->smaller(4096)->compile());
    }

    public function testUidCriterion(): void
    {
        $search = (new Search())->uid(SequenceSet::range(1, 10));

        self::assertSame('UID 1:10', $search->compile());
    }

    public function testNotOperator(): void
    {
        $inner = (new Search())->subject('spam');
        $search = (new Search())->not($inner);

        self::assertSame('NOT (SUBJECT "spam")', $search->compile());
    }

    public function testOrOperator(): void
    {
        $a = (new Search())->from('alice@example.com');
        $b = (new Search())->from('bob@example.com');
        $search = (new Search())->or($a, $b);

        self::assertSame('OR (FROM "alice@example.com") (FROM "bob@example.com")', $search->compile());
    }

    public function testModSeqSince(): void
    {
        self::assertSame('MODSEQ 12345', (new Search())->modSeqSince(12345)->compile());
    }

    public function testToStringDelegatesToCompile(): void
    {
        $search = (new Search())->unread();

        self::assertSame('UNSEEN', (string) $search);
    }
}
