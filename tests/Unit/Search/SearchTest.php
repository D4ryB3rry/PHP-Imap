<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Search;

use D4ry\ImapClient\Search\Search;
use D4ry\ImapClient\Support\ImapDateFormatter;
use D4ry\ImapClient\ValueObject\SequenceSet;
use PHPUnit\Framework\TestCase;

/**
 * @covers \D4ry\ImapClient\Search\Search
 * @uses \D4ry\ImapClient\Support\ImapDateFormatter
 * @uses \D4ry\ImapClient\ValueObject\SequenceSet
 */
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
        self::assertSame('DELETED', (new Search())->deleted()->compile());
        self::assertSame('UNDELETED', (new Search())->undeleted()->compile());
        self::assertSame('UNANSWERED', (new Search())->unanswered()->compile());
        self::assertSame('UNDRAFT', (new Search())->undraft()->compile());
        self::assertSame('DRAFT', (new Search())->draft()->compile());
        self::assertSame('RECENT', (new Search())->recent()->compile());
        self::assertSame('NEW', (new Search())->new()->compile());
        self::assertSame('OLD', (new Search())->old()->compile());
    }

    public function testSinceCriterion(): void
    {
        $date = new \DateTimeImmutable('2026-04-07');

        self::assertSame('SINCE 7-Apr-2026', (new Search())->since($date)->compile());
    }

    public function testBodyTextAndAddressStringCriteria(): void
    {
        $search = (new Search())
            ->body('invoice')
            ->text('newsletter')
            ->to('bob@example.com')
            ->cc('carol@example.com')
            ->bcc('dave@example.com');

        self::assertSame(
            'BODY "invoice" TEXT "newsletter" TO "bob@example.com" CC "carol@example.com" BCC "dave@example.com"',
            $search->compile(),
        );
    }

    public function testKeywordCriteria(): void
    {
        $search = (new Search())->keyword('Important')->unkeyword('Junk');

        self::assertSame('KEYWORD Important UNKEYWORD Junk', $search->compile());
    }

    public function testAllCriterion(): void
    {
        self::assertSame('ALL', (new Search())->all()->compile());
    }

    public function testAllCombinedWithOtherCriteria(): void
    {
        $search = (new Search())->all()->unread();

        self::assertSame('ALL UNSEEN', $search->compile());
    }

    public function testNotOperatorWithComplexInner(): void
    {
        $inner = (new Search())->subject('spam')->from('alice@example.com');
        $search = (new Search())->not($inner);

        self::assertSame('NOT (SUBJECT "spam" FROM "alice@example.com")', $search->compile());
    }

    public function testOrOperatorCombinedWithAdditionalCriteria(): void
    {
        $a = (new Search())->from('alice@example.com');
        $b = (new Search())->from('bob@example.com');
        $search = (new Search())->or($a, $b)->unread();

        self::assertSame(
            'OR (FROM "alice@example.com") (FROM "bob@example.com") UNSEEN',
            $search->compile(),
        );
    }

    public function testStringCriterionWithEmptyValue(): void
    {
        self::assertSame('SUBJECT ""', (new Search())->subject('')->compile());
    }

    public function testHeaderEscapesQuotesAndBackslash(): void
    {
        $search = (new Search())->header('X-Custom', 'value with "quotes" and \\slash');

        self::assertSame(
            'HEADER "X-Custom" "value with \\"quotes\\" and \\\\slash"',
            $search->compile(),
        );
    }

    public function testUidCriterionWithSingleId(): void
    {
        $search = (new Search())->uid(SequenceSet::single(42));

        self::assertSame('UID 42', $search->compile());
    }

    public function testFluentInterfaceReturnsSameInstance(): void
    {
        $search = new Search();

        self::assertSame($search, $search->unread());
        self::assertSame($search, $search->subject('hi'));
        self::assertSame($search, $search->larger(1));
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
