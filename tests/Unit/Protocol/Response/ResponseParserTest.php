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
#[UsesClass(\D4ry\ImapClient\Protocol\StreamingFetchState::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\Uid::class)]
#[UsesClass(\D4ry\ImapClient\ValueObject\FlagSet::class)]
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

    public function testReadResponseFetchWithUnclosedParenReturnsSeqOnly(): void
    {
        // The literal-content branch ensures `(` is the first byte but the
        // closing paren is missing — strrpos() returns false, so parseFetchData
        // bails out and the result contains only the seq key.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 7 FETCH (UID 42 UNCLOSED',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $fetch = $response->untagged[0];

        self::assertSame('FETCH', $fetch->type);
        self::assertSame(7, $fetch->data['seq']);
        self::assertCount(1, $fetch->data);
    }

    public function testSetNextLiteralSinkRoutesLiteralIntoSink(): void
    {
        $payload = 'literal-sink-bytes';
        $connection = new FakeConnection();
        $connection->queueLines('* 1 FETCH (UID 42 BODY[2] {' . strlen($payload) . '}');
        $connection->queueBytes($payload);
        $connection->queueLines(
            ')',
            'A0001 OK FETCH done',
        );

        $sink = fopen('php://memory', 'w+b');
        self::assertNotFalse($sink);

        try {
            $parser = $this->makeParser($connection);
            $parser->setNextLiteralSink($sink);

            $response = $parser->readResponse('A0001');

            rewind($sink);
            self::assertSame($payload, stream_get_contents($sink));
            // After the literal is consumed the FETCH parser sees an empty
            // body for that section because the framing was rewritten to {0}.
            self::assertSame('', $response->untagged[0]->data['BODY[2]'] ?? null);
        } finally {
            fclose($sink);
        }
    }

    public function testReadNextStreamingItemQueuesFetchAndCompletesOnTaggedLine(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* CAPABILITY IMAP4rev1',
            '* 1 FETCH (UID 42 FLAGS (\Seen))',
            '* 2 FETCH (UID 43 FLAGS ())',
            'A0001 OK FETCH done',
        );

        $parser = $this->makeParser($connection);
        $state = new \D4ry\ImapClient\Protocol\StreamingFetchState('A0001');

        // First item: non-FETCH untagged → otherUntagged.
        $parser->readNextStreamingItem($state);
        self::assertCount(1, $state->otherUntagged);
        self::assertSame('CAPABILITY', $state->otherUntagged[0]->type);
        self::assertFalse($state->completed);

        // Two FETCH items → fetchQueue.
        $parser->readNextStreamingItem($state);
        $parser->readNextStreamingItem($state);
        self::assertCount(2, $state->fetchQueue);

        // Tagged completion line.
        $parser->readNextStreamingItem($state);
        self::assertTrue($state->completed);
        self::assertNotNull($state->finalResponse);
        self::assertSame([], $state->otherUntagged);
        self::assertCount(1, $state->finalResponse->untagged);
    }

    public function testReadNextStreamingItemUnknownLineGoesToOtherUntagged(): void
    {
        $connection = new FakeConnection();
        // A continuation in the middle of a streaming FETCH is unexpected;
        // the parser must preserve it as UNKNOWN instead of crashing.
        $connection->queueLines(
            '+ go ahead',
            'A0001 OK done',
        );

        $parser = $this->makeParser($connection);
        $state = new \D4ry\ImapClient\Protocol\StreamingFetchState('A0001');

        $parser->readNextStreamingItem($state);
        self::assertCount(1, $state->otherUntagged);
        self::assertSame('UNKNOWN', $state->otherUntagged[0]->type);
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

    // ----- Exact-message kills for the Concat / ConcatOperandRemoval mutants
    // on ProtocolException construction. -----

    public function testReadGreetingNonUntaggedThrowsExactMessage(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK done');

        try {
            $this->makeParser($connection)->readGreeting();
            self::fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            self::assertSame('Expected server greeting, got: A0001 OK done', $e->getMessage());
        }
    }

    public function testReadGreetingByeThrowsExactMessage(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('* BYE not welcome');

        try {
            $this->makeParser($connection)->readGreeting();
            self::fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            self::assertSame('Server rejected connection: * BYE not welcome', $e->getMessage());
        }
    }

    public function testReadContinuationInvalidThrowsExactMessage(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK not a continuation');

        try {
            $this->makeParser($connection)->readContinuation();
            self::fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            self::assertSame('Expected continuation, got: A0001 OK not a continuation', $e->getMessage());
        }
    }

    public function testReadResponseInvalidTaggedLineThrowsExactMessage(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 GARBAGE here');

        try {
            $this->makeParser($connection)->readResponse('A0001');
            self::fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            self::assertSame('Unable to parse tagged response: A0001 GARBAGE here', $e->getMessage());
        }
    }

    // ----- Tag-prefix discrimination -----

    public function testReadResponseDoesNotMatchTagAsBarePrefix(): void
    {
        // The expected tag must match exactly, not as a bare prefix:
        // "A00010" must NOT be parsed as the tagged completion of "A0001".
        // Kills the ConcatOperandRemoval mutant on `$expectedTag . ' '` at
        // line 87 (and the same construct at line 123).
        $connection = new FakeConnection();
        $connection->queueLines(
            'A00010 OK should-not-be-treated-as-tagged',
            'A0001 OK actual completion',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('actual completion', $response->text);
        self::assertCount(1, $response->untagged);
        self::assertSame('UNKNOWN', $response->untagged[0]->type);
    }

    public function testReadNextStreamingItemDoesNotMatchTagAsBarePrefix(): void
    {
        // Same shape as the readResponse case, but for the streaming-fetch
        // path. Kills the ConcatOperandRemoval mutant at line 123.
        $connection = new FakeConnection();
        $connection->queueLines(
            'A00010 OK should-not-be-tagged-completion',
            'A0001 OK actual completion',
        );

        $parser = $this->makeParser($connection);
        $state = new \D4ry\ImapClient\Protocol\StreamingFetchState('A0001');

        $parser->readNextStreamingItem($state);
        self::assertFalse($state->completed);
        self::assertCount(1, $state->otherUntagged);
        self::assertSame('UNKNOWN', $state->otherUntagged[0]->type);

        $parser->readNextStreamingItem($state);
        self::assertTrue($state->completed);
    }

    // ----- Continuation text trimming (UnwrapTrim line 82, 149) -----

    public function testReadContinuationTrimsTrailingWhitespace(): void
    {
        // The trim() around substr() must collapse trailing spaces. Without
        // it (UnwrapTrim mutant on line 149), the returned text would carry
        // the trailing space verbatim.
        $connection = new FakeConnection();
        $connection->queueLines('+ ready   ');

        self::assertSame('ready', $this->makeParser($connection)->readContinuation());
    }

    public function testReadResponseContinuationTextIsTrimmed(): void
    {
        // Same shape but for the readResponse() continuation branch — kills
        // the UnwrapTrim mutant on line 82.
        $connection = new FakeConnection();
        $connection->queueLines('+ Ready   ');

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('+', $response->tag);
        self::assertSame('Ready', $response->text);
    }

    // ----- preg_match anchor / flag kills for parseUntaggedLine regexes -----

    public function testReadResponseUntaggedNonNumberedTrailingDataIsTrimmed(): void
    {
        // The data branch for non-numeric / non-status untagged responses
        // (line 235-236) trims and uppercases the captured groups. Tests with
        // mixed-case "* foo bar" + trailing space exercise both UnwrapStrToUpper
        // and UnwrapTrim mutants on those lines.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* foo  bar baz   ',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame('FOO', $response->untagged[0]->type);
        self::assertSame('bar baz', $response->untagged[0]->data);
    }

    public function testReadResponseLowerCaseStatusUntaggedIsUppercased(): void
    {
        // Untagged status with lowercase keyword exercises the /i flag and
        // strtoupper on line 213 (PregMatchRemoveFlags + UnwrapStrToUpper).
        $connection = new FakeConnection();
        $connection->queueLines(
            '* no [ALERT] disk almost full',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        $no = $response->untagged[0];
        self::assertSame('NO', $no->type);
        self::assertSame('ALERT', $no->data['code']);
        self::assertSame('disk almost full', $no->data['text']);
    }

    public function testReadResponseLowerCaseStructuredKeywordIsUppercased(): void
    {
        // Lowercase "capability" exercises the /i flag and strtoupper on
        // lines 228-229 (PregMatchRemoveFlags + UnwrapStrToUpper).
        $connection = new FakeConnection();
        $connection->queueLines(
            '* capability   IMAP4rev1   IDLE   ',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');

        $cap = $response->untagged[0];
        self::assertSame('CAPABILITY', $cap->type);
        self::assertSame(['IMAP4rev1', 'IDLE'], $cap->data);
    }

    public function testReadResponseLowerCaseTaggedStatusIsUppercased(): void
    {
        // Lowercase tagged "ok" exercises the /i flag on line 249 and the
        // strtoupper on line 250.
        $connection = new FakeConnection();
        $connection->queueLines('A0001 ok all done');

        $response = $this->makeParser($connection)->readResponse('A0001');

        self::assertSame(ResponseStatus::Ok, $response->status);
        self::assertSame('all done', $response->text);
    }

    // ----- LIST / STATUS edge cases for trim mutants -----

    public function testReadResponseListResponseTrimsAttributesAndName(): void
    {
        // Trim mutants on lines 306, 309, 314, 315 (and 392 in
        // parseParenthesizedList) need inputs with extra whitespace. Use a
        // LIST line whose attribute list and name include extra spaces.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST (\HasNoChildren  \Sent) "/" "INBOX/Sent"',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $list = $response->untagged[0];

        self::assertSame(['\HasNoChildren', '\Sent'], $list->data['attributes']);
        self::assertSame('/', $list->data['delimiter']);
        self::assertSame('INBOX/Sent', $list->data['name']);
    }

    public function testReadResponseFlagsRequireWrappingParens(): void
    {
        // The parenthesized-list regex requires both `^\(` and `\)$`.
        // Kills PregMatchRemoveCaret/Dollar mutants on line 293, plus the
        // UnwrapTrim mutants on the inner trims (lines 294, 392).
        $connection = new FakeConnection();
        $connection->queueLines(
            '* FLAGS (  \Seen   \Answered  )',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        self::assertSame(['\Seen', '\Answered'], $response->untagged[0]->data);
    }

    public function testReadResponseStatusKeysAreUppercasedAndTrimmed(): void
    {
        // Lowercase status attribute keys + extra whitespace inside the
        // (...) block exercise the strtoupper on line 331 and the trims on
        // lines 325/327.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* STATUS "INBOX" (  messages 12   unseen 3  )',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $status = $response->untagged[0];

        self::assertSame('INBOX', $status->data['mailbox']);
        self::assertSame(12, $status->data['attributes']['MESSAGES']);
        self::assertSame(3, $status->data['attributes']['UNSEEN']);
    }

    public function testReadResponseListResultAlwaysHasAttributesKey(): void
    {
        // The initial array literal `['attributes' => [], 'delimiter' => '',
        // 'name' => '']` must include the 'attributes' key — without it
        // (ArrayItemRemoval line 302), a LIST input that fails the regex
        // would still need to expose 'attributes' as an empty array.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST garbage that does not match',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $list = $response->untagged[0];

        self::assertSame('LIST', $list->type);
        self::assertArrayHasKey('attributes', $list->data);
        self::assertSame([], $list->data['attributes']);
    }

    public function testReadResponseStatusResultAlwaysHasMailboxKey(): void
    {
        // Mirror of the LIST case for STATUS — kills ArrayItemRemoval line 323.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* STATUS garbage',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $status = $response->untagged[0];

        self::assertSame('STATUS', $status->type);
        self::assertArrayHasKey('mailbox', $status->data);
        self::assertSame('', $status->data['mailbox']);
    }

    public function testReadResponseEnabledTrimsLeadingAndTrailingWhitespace(): void
    {
        // Kills UnwrapTrim on line 281 — the ENABLED branch trims before
        // splitting. Without the trim, leading whitespace would produce an
        // empty first token.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* ENABLED   UTF8=ACCEPT   CONDSTORE   ',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        self::assertSame(['UTF8=ACCEPT', 'CONDSTORE'], $response->untagged[0]->data);
    }

    public function testReadResponseCapabilityTrimsLeadingAndTrailingWhitespace(): void
    {
        // Kills UnwrapTrim on line 288 in parseCapabilities.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* CAPABILITY   IMAP4rev1   IDLE   ',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        self::assertSame(['IMAP4rev1', 'IDLE'], $response->untagged[0]->data);
    }

    public function testReadResponseSearchEmptyDataReturnsEmptyArrayNotNumbers(): void
    {
        // Kills ReturnRemoval on line 342 (parseNumberList early return).
        // Also exercises the UnwrapTrim on line 340 — without trim, the
        // bare-space input '* SEARCH ' would not equal '' after trim removal
        // and would proceed into preg_split, returning [''] instead of [].
        $connection = new FakeConnection();
        $connection->queueLines(
            '* SEARCH    ',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        self::assertSame([], $response->untagged[0]->data);
    }

    public function testReadResponseFetchHandlesLeadingWhitespaceBeforeOpenParen(): void
    {
        // Kills UnwrapLtrim on line 360 — parseFetchData ltrims the data so
        // a FETCH with leading whitespace before "(" still parses.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 1 FETCH    (UID 42 FLAGS (\Seen))',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $fetch = $response->untagged[0];

        self::assertSame('FETCH', $fetch->type);
        self::assertSame(1, $fetch->data['seq']);
        self::assertGreaterThan(1, count($fetch->data));
    }

    public function testReadResponseLowerCaseFetchTypeIsUppercased(): void
    {
        // Kills UnwrapStrToUpper on line 200 — lowercase 'fetch' must be
        // dispatched into parseFetchData (which keys off the uppercase form).
        // The parsed result must therefore include the inner FETCH fields,
        // not just the seq number.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 1 fetch (UID 42 FLAGS (\Seen))',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $fetch = $response->untagged[0];

        self::assertSame('FETCH', $fetch->type);
        // Only true if parseFetchData() ran — otherwise data would be the
        // ['number'=>1,'data'=>'...'] shape.
        self::assertArrayHasKey('seq', $fetch->data);
        self::assertGreaterThan(1, count($fetch->data));
    }

    public function testReadResponseUntaggedNumberedNonFetchTrailingDataIsTrimmed(): void
    {
        // Kills UnwrapTrim on line 201 — trailing whitespace after the type
        // word must be stripped from the captured data field.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 5 EXISTS   extra   ',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $exists = $response->untagged[0];

        self::assertSame('EXISTS', $exists->type);
        self::assertSame(5, $exists->data['number']);
        self::assertSame('extra', $exists->data['data']);
    }

    public function testReadFullLineLiteralFramingRequiresEndOfLineAnchor(): void
    {
        // Kills PregMatchRemoveDollar on line 157. Without the `$` anchor,
        // a `{N}` framing in the middle of a line would be picked up as a
        // literal length even when it is followed by more bytes on the same
        // line. The literal-content path requires the framing to be at the
        // *end* of the line — feed a line where `{5}` appears mid-line and
        // assert no literal read happens.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 1 FETCH (BODY[1] "{5} embedded literal-looking text but not a real one")',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $fetch = $response->untagged[0];

        self::assertSame('FETCH', $fetch->type);
        // No literal was read from the byte queue (we never queued any).
        self::assertSame(1, $fetch->data['seq']);
    }

    public function testReadResponseFetchInnerStripsClosingParen(): void
    {
        // The `$end - 1` substr length in parseFetchData strips the outer
        // closing `)` before passing the inner to FetchResponseParser.
        // Mutating the offset (DecrementInteger → `$end - 0`, Minus →
        // `$end + 1`) would either include the trailing `)` in the inner
        // string or read past the end. Either case breaks UID parsing.
        $connection = new FakeConnection();
        $connection->queueLines(
            '* 1 FETCH (UID 42 FLAGS (\Seen))',
            'A0001 OK done',
        );

        $response = $this->makeParser($connection)->readResponse('A0001');
        $fetch = $response->untagged[0];

        self::assertSame('FETCH', $fetch->type);
        self::assertSame(1, $fetch->data['seq']);
        // The presence of UID 42 proves FetchResponseParser received an
        // inner string whose final char is NOT ')' (would have caused
        // parse failure) and whose length is correct.
        self::assertArrayHasKey('UID', $fetch->data);
        self::assertSame(42, $fetch->data['UID']->value);
    }

    public function testReadFullLineParsesIntegerLiteralSizeFromCapturedDigits(): void
    {
        // Kills DecrementInteger on line 158 (which mutates `(int) $matches[1]`
        // to `(int) $matches[0]`). $matches[0] is the full match `{5}`, and
        // `(int) "{5}"` evaluates to 0, so the literal read would consume 0
        // bytes instead of 5 — a payload assertion catches that.
        $payload = 'XXXXX';
        $connection = new FakeConnection();
        $connection->queueLines('* 1 FETCH (BODY[TEXT] {5}');
        $connection->queueBytes($payload);
        $connection->queueLines(')');
        $connection->queueLines('A0001 OK done');

        $response = $this->makeParser($connection)->readResponse('A0001');
        $fetch = $response->untagged[0];

        self::assertSame('FETCH', $fetch->type);
        self::assertSame($payload, $fetch->data['BODY[TEXT]']);
    }
}
