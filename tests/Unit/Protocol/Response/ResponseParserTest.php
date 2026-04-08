<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Protocol\Response;

use D4ry\ImapClient\Exception\ProtocolException;
use D4ry\ImapClient\Protocol\Response\FetchResponseParser;
use D4ry\ImapClient\Protocol\Response\Response;
use D4ry\ImapClient\Protocol\Response\ResponseParser;
use D4ry\ImapClient\Protocol\Response\ResponseStatus;
use D4ry\ImapClient\Protocol\Response\UntaggedResponse;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseParser::class)]
#[UsesClass(Response::class)]
#[UsesClass(UntaggedResponse::class)]
#[UsesClass(FetchResponseParser::class)]
final class ResponseParserTest extends TestCase
{
    private function makeParser(FakeConnection $connection): ResponseParser
    {
        return new ResponseParser($connection);
    }

    public function testReadGreetingOk(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('* OK [CAPABILITY IMAP4rev1] server ready');

        $greeting = $this->makeParser($connection)->readGreeting();

        self::assertSame('OK', $greeting->type);
        self::assertSame('[CAPABILITY IMAP4rev1] server ready', $greeting->data);
        self::assertNotNull($greeting->raw);
    }

    public function testReadGreetingPreAuth(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('* PREAUTH already authenticated');

        $greeting = $this->makeParser($connection)->readGreeting();

        self::assertSame('PREAUTH', $greeting->type);
        self::assertSame('already authenticated', $greeting->data);
    }

    public function testReadGreetingByeThrows(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('* BYE not welcome');

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Server rejected connection');

        $this->makeParser($connection)->readGreeting();
    }

    public function testReadGreetingFallbackForUnknownStatus(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('* SOMETHING odd');

        $greeting = $this->makeParser($connection)->readGreeting();

        self::assertSame('OK', $greeting->type);
        self::assertSame('SOMETHING odd', $greeting->data);
    }

    public function testReadGreetingNonUntaggedThrows(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK done');

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Expected server greeting');

        $this->makeParser($connection)->readGreeting();
    }

    public function testReadResponseTaggedOk(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK NOOP completed');

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame(ResponseStatus::Ok, $response->status);
        self::assertSame('A0001', $response->tag);
        self::assertSame('NOOP completed', $response->text);
        self::assertNull($response->responseCode);
    }

    public function testReadResponseTaggedNoIsParsed(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 NO mailbox does not exist');

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame(ResponseStatus::No, $response->status);
        self::assertSame('mailbox does not exist', $response->text);
    }

    public function testReadResponseTaggedBadIsParsed(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 BAD syntax error');

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame(ResponseStatus::Bad, $response->status);
    }

    public function testReadResponseExtractsResponseCode(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK [READ-WRITE] SELECT completed');

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('READ-WRITE', $response->responseCode);
        self::assertSame('SELECT completed', $response->text);
    }

    public function testReadResponseInvalidTaggedLineThrows(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 GARBAGE here');

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Unable to parse tagged response');

        $this->makeParser($connection)->readResponse('A0001');
    }

    public function testReadResponseContinuationWithText(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('+ Ready for additional command text');

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('+', $response->tag);
        self::assertSame('Ready for additional command text', $response->text);
        self::assertSame(ResponseStatus::Ok, $response->status);
    }

    public function testReadResponseContinuationBare(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('+');

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('+', $response->tag);
        self::assertSame('', $response->text);
    }

    public function testReadResponseAggregatesUntaggedLines(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* CAPABILITY IMAP4rev1 IDLE',
            '* 5 EXISTS',
            '* OK [UIDVALIDITY 1] message',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertCount(3, $response->untagged);

        $cap = $response->untagged[0];
        self::assertSame('CAPABILITY', $cap->type);
        self::assertSame(['IMAP4rev1', 'IDLE'], $cap->data);

        $exists = $response->untagged[1];
        self::assertSame('EXISTS', $exists->type);
        self::assertSame(5, $exists->data['number']);

        $ok = $response->untagged[2];
        self::assertSame('OK', $ok->type);
        self::assertSame('UIDVALIDITY 1', $ok->data['code']);
        self::assertSame('message', $ok->data['text']);
    }

    public function testReadResponseUnknownLineFallsBackToUnknown(): void
    {
        // A line beginning with a tag prefix that is not the expected tag,
        // and that does not start with "* " or "+", is treated as UNKNOWN.
        $connection = new FakeConnection();
        $connection->queueLines(
            'A9999 OK stray',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertCount(1, $response->untagged);
        self::assertSame('UNKNOWN', $response->untagged[0]->type);
    }

    public function testReadResponseParsesFlags(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* FLAGS (\\Answered \\Seen)',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        $flags = $response->untagged[0];
        self::assertSame('FLAGS', $flags->type);
        self::assertSame(['\\Answered', '\\Seen'], $flags->data);
    }

    public function testReadResponseParsesListResponse(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST (\\HasNoChildren) "/" "INBOX"',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        $list = $response->untagged[0];
        self::assertSame('LIST', $list->type);
        self::assertSame(['\\HasNoChildren'], $list->data['attributes']);
        self::assertSame('/', $list->data['delimiter']);
        self::assertSame('INBOX', $list->data['name']);
    }

    public function testReadResponseParsesListResponseWithMultipleAttributes(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST (\\HasChildren \\Marked) "/" "Sent"',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        $list = $response->untagged[0];
        self::assertSame(['\\HasChildren', '\\Marked'], $list->data['attributes']);
        self::assertSame('/', $list->data['delimiter']);
        self::assertSame('Sent', $list->data['name']);
    }

    public function testReadResponseParsesStatusResponse(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* STATUS "INBOX" (MESSAGES 12 UNSEEN 3 UIDNEXT 100)',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        $status = $response->untagged[0];
        self::assertSame('STATUS', $status->type);
        self::assertSame('INBOX', $status->data['mailbox']);
        self::assertSame(12, $status->data['attributes']['MESSAGES']);
        self::assertSame(3, $status->data['attributes']['UNSEEN']);
        self::assertSame(100, $status->data['attributes']['UIDNEXT']);
    }

    public function testReadResponseParsesSearchResults(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* SEARCH 1 4 9 16',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame([1, 4, 9, 16], $response->untagged[0]->data);
    }

    public function testReadResponseParsesSearchResultsIgnoresNonNumericTokens(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* SEARCH 2 4 not-a-number 8',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame([2, 4, 8], $response->untagged[0]->data);
    }

    public function testReadResponseParsesEnabled(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* ENABLED UTF8=ACCEPT CONDSTORE',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame(['UTF8=ACCEPT', 'CONDSTORE'], $response->untagged[0]->data);
    }

    public function testReadResponseParsesUntaggedNoWithCode(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* NO [ALERT] disk almost full',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        $no = $response->untagged[0];
        self::assertSame('NO', $no->type);
        self::assertSame('ALERT', $no->data['code']);
        self::assertSame('disk almost full', $no->data['text']);
    }

    public function testReadResponseParsesFetchWithLiteral(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('* 1 FETCH (BODY[TEXT] {5}');
        $connection->queueBytes('hello');
        $connection->queueLines(')');
        $connection->queueLines('A0001 OK done');

        $response = $this->makeParser($connection)->readResponse('A0001');

        $fetch = $response->untagged[0];
        self::assertSame('FETCH', $fetch->type);
        self::assertSame(1, $fetch->data['seq']);
    }

    public function testReadContinuationWithText(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('+ go ahead');

        self::assertSame('go ahead', $this->makeParser($connection)->readContinuation());
    }

    public function testReadContinuationBare(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('+');

        self::assertSame('', $this->makeParser($connection)->readContinuation());
    }

    public function testReadContinuationInvalidThrows(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK not a continuation');

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Expected continuation');

        $this->makeParser($connection)->readContinuation();
    }

    public function testReadResponseUnknownUntaggedTypeFallback(): void
    {
        // Lines like "* FOO bar" do not match any of the structured patterns
        // and fall back to a generic uppercased type.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* FOO bar baz',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('FOO', $response->untagged[0]->type);
        self::assertSame('bar baz', $response->untagged[0]->data);
    }

    public function testReadGreetingOkWithoutCode(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('* OK ready');

        $greeting = $this->makeParser($connection)->readGreeting();

        self::assertSame('OK', $greeting->type);
        self::assertSame('ready', $greeting->data);
    }

    public function testReadResponseUntaggedNumberedNonFetch(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 5 EXISTS',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $exists = $response->untagged[0];

        self::assertSame('EXISTS', $exists->type);
        self::assertSame(5, $exists->data['number']);
        self::assertSame('', $exists->data['data']);
    }

    public function testReadResponseListWithNilDelimiter(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST (\\Noselect) NIL "Foo"',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $list = $response->untagged[0];

        self::assertSame(['\\Noselect'], $list->data['attributes']);
        self::assertSame('', $list->data['delimiter']);
        self::assertSame('Foo', $list->data['name']);
    }

    public function testReadResponseListWithNilDelimiterAndEmptyAttributes(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST () NIL "Foo"',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $list = $response->untagged[0];

        self::assertSame([], $list->data['attributes']);
        self::assertSame('', $list->data['delimiter']);
        self::assertSame('Foo', $list->data['name']);
    }

    public function testReadResponseListWithEmptyAttributes(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST () "/" "Bar"',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $list = $response->untagged[0];

        self::assertSame([], $list->data['attributes']);
        self::assertSame('/', $list->data['delimiter']);
        self::assertSame('Bar', $list->data['name']);
    }

    public function testReadResponseLsubResponse(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LSUB (\\HasNoChildren) "/" "Subscribed"',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('LSUB', $response->untagged[0]->type);
        self::assertSame('Subscribed', $response->untagged[0]->data['name']);
    }

    public function testReadResponseStatusUnquotedMailbox(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* STATUS INBOX (MESSAGES 1)',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $status = $response->untagged[0];

        self::assertSame('INBOX', $status->data['mailbox']);
        self::assertSame(1, $status->data['attributes']['MESSAGES']);
    }

    public function testReadResponseEmptySearch(): void
    {
        // Trailing space ensures the SEARCH branch matches with empty data.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* SEARCH ',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('SEARCH', $response->untagged[0]->type);
        self::assertSame([], $response->untagged[0]->data);
    }

    public function testReadResponseSortResponse(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* SORT 3 1 2',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame([3, 1, 2], $response->untagged[0]->data);
    }

    public function testReadFullLineHandlesMultipleLiterals(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('* 1 FETCH (BODY[1] {3}');
        $connection->queueBytes('abc');
        $connection->queueLines(' BODY[2] {3}');
        $connection->queueBytes('xyz');
        $connection->queueLines(')');
        $connection->queueLines('A0001 OK done');

        $response = $this->makeParser($connection)->readResponse('A0001');
        $fetch = $response->untagged[0];

        self::assertSame('FETCH', $fetch->type);
        self::assertSame('abc', $fetch->data['BODY[1]']);
        self::assertSame('xyz', $fetch->data['BODY[2]']);
    }

    public function testReadFullLineHandlesNonSynchronizingLiteral(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('* 1 FETCH (BODY[TEXT] {5+}');
        $connection->queueBytes('hello');
        $connection->queueLines(')');
        $connection->queueLines('A0001 OK done');

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('hello', $response->untagged[0]->data['BODY[TEXT]']);
    }

    public function testReadResponseTaggedOkWithCapabilityCode(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK [CAPABILITY IMAP4rev1 IDLE] LOGIN done');

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('CAPABILITY IMAP4rev1 IDLE', $response->responseCode);
        self::assertSame('LOGIN done', $response->text);
    }

    public function testReadResponseEmptyUntaggedLineFallsBackToUnknown(): void
    {
        // A bare "* " line has empty rest and matches none of the structured patterns.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* ',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('UNKNOWN', $response->untagged[0]->type);
    }

    public function testReadResponseFlagsWithoutParensFallsBackToEmpty(): void
    {
        // FLAGS data that does not match the parenthesized regex returns [].
        $connection = new FakeConnection();
        $connection->queueLines(
            '* FLAGS bogus',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('FLAGS', $response->untagged[0]->type);
        self::assertSame([], $response->untagged[0]->data);
    }

    public function testReadResponseFetchWithEmptyParens(): void
    {
        // parseFetchData: data does not match the inner-paren regex,
        // so only the seq key is returned.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 7 FETCH ',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $fetch = $response->untagged[0];

        self::assertSame('FETCH', $fetch->type);
        self::assertSame(7, $fetch->data['seq']);
        self::assertCount(1, $fetch->data);
    }
}
