<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit;

use D4ry\ImapClient\Collection\FolderCollection;
use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Enum\SpecialUse;
use D4ry\ImapClient\Exception\CapabilityException;
use D4ry\ImapClient\Exception\CommandException;
use D4ry\ImapClient\Exception\ConnectionException;
use D4ry\ImapClient\Folder;
use D4ry\ImapClient\Idle\FlagsChangedEvent;
use D4ry\ImapClient\Idle\IdleEvent;
use D4ry\ImapClient\Idle\IdleHandlerInterface;
use D4ry\ImapClient\Idle\IdleHeartbeatEvent;
use D4ry\ImapClient\Idle\MessageExpungedEvent;
use D4ry\ImapClient\Idle\MessageReceivedEvent;
use D4ry\ImapClient\Idle\RecentCountEvent;
use D4ry\ImapClient\Auth\LoginCredential;
use D4ry\ImapClient\Config;
use D4ry\ImapClient\Connection\LoggingConnection;
use D4ry\ImapClient\Connection\RecordingConnection;
use D4ry\ImapClient\Connection\ReplayConnection;
use D4ry\ImapClient\Connection\SocketConnection;
use D4ry\ImapClient\Enum\Encryption;
use D4ry\ImapClient\Mailbox;
use D4ry\ImapClient\Protocol\Response\Response;
use D4ry\ImapClient\Protocol\Response\ResponseStatus;
use D4ry\ImapClient\Protocol\Response\UntaggedResponse;
use D4ry\ImapClient\Protocol\Transceiver;
use D4ry\ImapClient\Tests\Unit\Support\FakeConnection;
use D4ry\ImapClient\Tests\Unit\Support\LoopbackServer;
use D4ry\ImapClient\Tests\Unit\Support\TimeoutOnceConnection;
use D4ry\ImapClient\ValueObject\NamespaceInfo;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @covers \D4ry\ImapClient\Mailbox
 * @uses \D4ry\ImapClient\Protocol\Transceiver
 * @uses \D4ry\ImapClient\Folder
 * @uses \D4ry\ImapClient\Collection\FolderCollection
 * @uses \D4ry\ImapClient\ValueObject\NamespaceInfo
 * @uses \D4ry\ImapClient\ValueObject\MailboxPath
 * @uses \D4ry\ImapClient\ValueObject\Tag
 * @uses \D4ry\ImapClient\ValueObject\FlagSet
 * @uses \D4ry\ImapClient\Exception\CapabilityException
 * @uses \D4ry\ImapClient\Exception\CommandException
 * @uses \D4ry\ImapClient\Exception\ConnectionException
 * @uses \D4ry\ImapClient\Idle\IdleHeartbeatEvent
 * @uses \D4ry\ImapClient\Idle\MessageReceivedEvent
 * @uses \D4ry\ImapClient\Idle\MessageExpungedEvent
 * @uses \D4ry\ImapClient\Idle\FlagsChangedEvent
 * @uses \D4ry\ImapClient\Idle\RecentCountEvent
 * @uses \D4ry\ImapClient\Protocol\Command\Command
 * @uses \D4ry\ImapClient\Protocol\Command\CommandBuilder
 * @uses \D4ry\ImapClient\Protocol\Response\Response
 * @uses \D4ry\ImapClient\Protocol\Response\ResponseParser
 * @uses \D4ry\ImapClient\Protocol\Response\FetchResponseParser
 * @uses \D4ry\ImapClient\Protocol\Response\UntaggedResponse
 * @uses \D4ry\ImapClient\Protocol\TagGenerator
 * @uses \D4ry\ImapClient\Auth\LoginCredential
 * @uses \D4ry\ImapClient\Auth\Contract\CredentialInterface
 * @uses \D4ry\ImapClient\Config
 * @uses \D4ry\ImapClient\Connection\SocketConnection
 * @uses \D4ry\ImapClient\Connection\LoggingConnection
 * @uses \D4ry\ImapClient\Connection\RecordingConnection
 * @uses \D4ry\ImapClient\Connection\ReplayConnection
 * @uses \D4ry\ImapClient\Connection\Redactor
 * @uses \D4ry\ImapClient\Exception\TimeoutException
 */
final class MailboxTest extends TestCase
{
    private function setCapabilities(Transceiver $transceiver, string ...$caps): void
    {
        $prop = new ReflectionProperty(Transceiver::class, 'cachedCapabilities');
        $prop->setValue($transceiver, $caps);
    }

    private function makeMailbox(\D4ry\ImapClient\Connection\Contract\ConnectionInterface $connection, string ...$caps): array
    {
        $transceiver = new Transceiver($connection);
        $this->setCapabilities($transceiver, Capability::Imap4rev1, ...$caps);

        $ref = new ReflectionClass(Mailbox::class);
        $mailbox = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('transceiver');
        $prop->setValue($mailbox, $transceiver);

        return [$mailbox, $transceiver];
    }

    public function testFoldersParsesListResponseAndIsLazy(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST (\HasChildren) "/" "INBOX"',
            '* LIST (\Sent) "/" "Sent"',
            'A0001 OK LIST done',
        );

        [$mailbox] = $this->makeMailbox($connection);

        $folders = $mailbox->folders();
        self::assertSame([], $connection->writes, 'folders() must be lazy');

        self::assertSame(2, $folders->count());
        self::assertSame("A0001 LIST \"\" \"*\"\r\n", $connection->writes[0]);
        self::assertSame(SpecialUse::Sent, $folders->byName('Sent')?->specialUse());
    }

    public function testFolderReturnsParsedListEntry(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST (\HasNoChildren) "/" "Archive"',
            'A0001 OK LIST done',
        );

        [$mailbox] = $this->makeMailbox($connection);

        $folder = $mailbox->folder('Archive');

        self::assertSame('Archive', $folder->path()->path);
        self::assertSame("A0001 LIST \"\" Archive\r\n", $connection->writes[0]);
    }

    public function testFolderFallsBackToBareFolderWhenListIsEmpty(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK LIST done');

        [$mailbox] = $this->makeMailbox($connection);

        $folder = $mailbox->folder('NewFolder');

        self::assertSame('NewFolder', $folder->path()->path);
        self::assertNull($folder->specialUse());
    }

    public function testInboxDelegatesToFolder(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* LIST () "/" "INBOX"',
            'A0001 OK LIST done',
        );

        [$mailbox] = $this->makeMailbox($connection);

        $inbox = $mailbox->inbox();

        self::assertSame('INBOX', $inbox->path()->path);
        self::assertSame("A0001 LIST \"\" INBOX\r\n", $connection->writes[0]);
    }

    public function testCapabilitiesDelegatesToTransceiver(): void
    {
        $connection = new FakeConnection();

        [$mailbox] = $this->makeMailbox($connection, Capability::Idle, Capability::Move);

        self::assertContains(Capability::Idle, $mailbox->capabilities());
        self::assertTrue($mailbox->hasCapability(Capability::Idle));
        self::assertFalse($mailbox->hasCapability(Capability::Qresync));
    }

    public function testIdWithoutParams(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* ID ("name" "TestServer")',
            'A0001 OK ID done',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Id);

        $mailbox->id();

        self::assertSame("A0001 ID NIL\r\n", $connection->writes[0]);
    }

    public function testIdWithParamsWritesParameterTuples(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* ID ("name" "Server")',
            'A0001 OK ID done',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Id);

        $mailbox->id(['name' => 'TestClient', 'version' => '1.0']);

        self::assertSame(
            "A0001 ID (\"name\" \"TestClient\" \"version\" \"1.0\")\r\n",
            $connection->writes[0],
        );
    }

    public function testIdReturnsNullWhenNoIdUntaggedPresent(): void
    {
        // Server returns OK but never emits a `* ID` untagged. id() must
        // fall through and return null.
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK ID done');

        [$mailbox] = $this->makeMailbox($connection, Capability::Id);

        self::assertNull($mailbox->id());
    }

    public function testIdThrowsWhenCapabilityMissing(): void
    {
        $connection = new FakeConnection();
        [$mailbox] = $this->makeMailbox($connection);

        $this->expectException(CapabilityException::class);
        $mailbox->id();
    }

    public function testNamespaceParsesPersonalNamespace(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '* NAMESPACE (("" "/")) NIL NIL',
            'A0001 OK NAMESPACE done',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Namespace);

        $ns = $mailbox->namespace();

        self::assertInstanceOf(NamespaceInfo::class, $ns);
        // Parser is best-effort; only check that the call succeeded and returned a NamespaceInfo.
        self::assertSame("A0001 NAMESPACE\r\n", $connection->writes[0]);
    }

    public function testNamespaceThrowsWhenCapabilityMissing(): void
    {
        $connection = new FakeConnection();
        [$mailbox] = $this->makeMailbox($connection);

        $this->expectException(CapabilityException::class);
        $mailbox->namespace();
    }

    public function testDisconnectSendsLogoutAndClosesConnection(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK LOGOUT done');

        [$mailbox] = $this->makeMailbox($connection);

        $mailbox->disconnect();

        self::assertSame("A0001 LOGOUT\r\n", $connection->writes[0]);
        self::assertFalse($connection->isConnected());
    }

    public function testDisconnectSwallowsCommandException(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 NO logout failed');

        [$mailbox] = $this->makeMailbox($connection);

        $mailbox->disconnect();

        self::assertFalse($connection->isConnected());
    }

    public function testGetTransceiverReturnsInjectedInstance(): void
    {
        $connection = new FakeConnection();
        [$mailbox, $transceiver] = $this->makeMailbox($connection);

        self::assertSame($transceiver, $mailbox->getTransceiver());
    }

    public function testIdleWithCallableReceivesEventAndStops(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '+ idling',
            '* 5 EXISTS',
            'A0001 OK IDLE done',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Idle);

        $received = [];
        $mailbox->idle(function (IdleEvent $event) use (&$received): bool {
            $received[] = $event;
            return false; // stop after first event
        }, timeout: 5.0);

        self::assertCount(1, $received);
        self::assertInstanceOf(MessageReceivedEvent::class, $received[0]);
        self::assertSame(5, $received[0]->messageCount);

        self::assertSame("A0001 IDLE\r\n", $connection->writes[0]);
        self::assertSame("DONE\r\n", $connection->writes[1]);
    }

    public function testIdleThrowsWhenIdleCapabilityMissing(): void
    {
        $connection = new FakeConnection();
        [$mailbox] = $this->makeMailbox($connection);

        $this->expectException(CapabilityException::class);
        $mailbox->idle(fn() => false);
    }

    public function testIdleWithHandlerInterfaceDispatchesAllEventTypes(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '+ idling',
            '* 1 EXISTS',
            '* 2 EXPUNGE',
            '* 3 RECENT',
            '* 4 FETCH (FLAGS (\Seen))',
            '* OK Still here',
            'A0001 OK IDLE done',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Idle);

        $handler = new class implements IdleHandlerInterface {
            public int $received = 0;
            public int $expunged = 0;
            public int $recent = 0;
            public int $flags = 0;
            public int $heartbeat = 0;

            public function onMessageReceived(MessageReceivedEvent $event): bool
            {
                $this->received++;
                return true;
            }

            public function onMessageExpunged(MessageExpungedEvent $event): bool
            {
                $this->expunged++;
                return true;
            }

            public function onFlagsChanged(FlagsChangedEvent $event): bool
            {
                $this->flags++;
                return true;
            }

            public function onRecentCount(RecentCountEvent $event): bool
            {
                $this->recent++;
                return true;
            }

            public function onHeartbeat(IdleHeartbeatEvent $event): bool
            {
                $this->heartbeat++;
                return false; // stop after the heartbeat
            }
        };

        $mailbox->idle($handler, timeout: 5.0);

        self::assertSame(1, $handler->received);
        self::assertSame(1, $handler->expunged);
        self::assertSame(1, $handler->recent);
        self::assertSame(1, $handler->flags);
        self::assertSame(1, $handler->heartbeat);
    }

    public function testIdleThrowsConnectionExceptionWhenServerDoesNotSendContinuation(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 NO idle not allowed');

        [$mailbox] = $this->makeMailbox($connection, Capability::Idle);

        $this->expectException(ConnectionException::class);
        $mailbox->idle(fn() => false);
    }

    public function testIdleHandlesParsingEdgeCases(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines(
            '+ idling',
            '',                          // empty → continue at line 202
            'noise without star prefix', // parseIdleEvent returns null → continue at 208 (and 229)
            '* foo bar',                 // untagged that matches neither numeric nor OK/NO/BAD/BYE → 254
            '* 5 FETCH (UID 7)',         // FETCH without (FLAGS ...) → parseFetchIdleEvent fallback at 270
            '* BAD oh no',               // OK|NO|BAD|BYE branch heartbeat (also exercises 251)
            '* 1 EXISTS',                // final stop event
            'A0001 OK IDLE done',
        );

        [$mailbox] = $this->makeMailbox($connection, Capability::Idle);

        $events = [];
        $mailbox->idle(function (\D4ry\ImapClient\Idle\IdleEvent $event) use (&$events): bool {
            $events[] = $event;

            return !($event instanceof \D4ry\ImapClient\Idle\MessageReceivedEvent);
        }, timeout: 5.0);

        // Three heartbeats (* foo bar, * 5 FETCH..., * BAD oh no) plus the EXISTS.
        self::assertCount(4, $events);
        self::assertInstanceOf(\D4ry\ImapClient\Idle\IdleHeartbeatEvent::class, $events[0]);
        self::assertInstanceOf(\D4ry\ImapClient\Idle\IdleHeartbeatEvent::class, $events[1]);
        self::assertInstanceOf(\D4ry\ImapClient\Idle\IdleHeartbeatEvent::class, $events[2]);
        self::assertInstanceOf(\D4ry\ImapClient\Idle\MessageReceivedEvent::class, $events[3]);
    }

    public function testIdleSwallowsTimeoutExceptionAndContinuesReading(): void
    {
        $inner = new FakeConnection();
        $inner->queueLines(
            '+ idling',
            '* 1 EXISTS',
            'A0001 OK IDLE done',
        );
        // Throw on the second readLine() — the first is consumed by the
        // continuation handshake, the timeout then fires inside the loop.
        $connection = new TimeoutOnceConnection($inner, throwOnCall: 2);

        [$mailbox] = $this->makeMailbox($connection, Capability::Idle);

        $received = [];
        $mailbox->idle(function (\D4ry\ImapClient\Idle\IdleEvent $event) use (&$received): bool {
            $received[] = $event;

            return false;
        }, timeout: 5.0);

        self::assertCount(1, $received);
        self::assertInstanceOf(\D4ry\ImapClient\Idle\MessageReceivedEvent::class, $received[0]);
    }

    public function testIdReturnsNullWhenResponseHasNoIdUntagged(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK ID done');

        [$mailbox] = $this->makeMailbox($connection, Capability::Id);

        self::assertNull($mailbox->id());
    }

    public function testNamespaceReturnsEmptyInfoWhenResponseHasNoNamespaceUntagged(): void
    {
        $connection = new FakeConnection();
        $connection->queueLines('A0001 OK NAMESPACE done');

        [$mailbox] = $this->makeMailbox($connection, Capability::Namespace);

        $ns = $mailbox->namespace();

        self::assertEquals(new NamespaceInfo(), $ns);
    }

    public function testParseFolderListSkipsLoosePayloads(): void
    {
        $connection = new FakeConnection();
        [$mailbox] = $this->makeMailbox($connection);

        $untagged = [
            new UntaggedResponse('LIST', 'not-an-array'),
            new UntaggedResponse('LIST', ['attributes' => [], 'delimiter' => '/', 'name' => '']),
        ];

        $method = new ReflectionMethod(Mailbox::class, 'parseFolderList');
        $result = $method->invoke($mailbox, $untagged);

        self::assertSame([], $result);
    }

    public function testParseNamespaceResponseHandlesNonStringAndAllBuckets(): void
    {
        $connection = new FakeConnection();
        [$mailbox] = $this->makeMailbox($connection);

        $method = new ReflectionMethod(Mailbox::class, 'parseNamespaceResponse');

        // Non-string data → empty NamespaceInfo (covers line 351).
        $empty = $method->invoke($mailbox, 42);
        self::assertEquals(new NamespaceInfo(), $empty);

        // String with three tuples → personal, other, shared all populated
        // (covers the elseif/else branches at 367–370).
        $populated = $method->invoke(
            $mailbox,
            '(("" "/")) (("Other/" "/")) (("Shared/" "/"))',
        );

        self::assertSame([['prefix' => '', 'delimiter' => '/']], $populated->personal);
        self::assertSame([['prefix' => 'Other/', 'delimiter' => '/']], $populated->other);
        self::assertSame([['prefix' => 'Shared/', 'delimiter' => '/']], $populated->shared);
    }

    public function testConnectPlainHappyPathDrivesFullExtensionFlow(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }

        $server = new LoopbackServer();
        $server->start('plain');

        $pid = $server->forkAccept(function ($peer): void {
            // Helpers — read one CRLF-terminated line / write a line.
            $readLine = static function ($peer): string {
                $line = '';
                while (($c = fread($peer, 1)) !== false && $c !== '') {
                    $line .= $c;
                    if (str_ends_with($line, "\r\n")) {
                        return $line;
                    }
                }
                return $line;
            };
            $writeLine = static function ($peer, string $line): void {
                fwrite($peer, $line . "\r\n");
                fflush($peer);
            };

            // 1) Greeting.
            $writeLine($peer, '* OK ready');

            // 2) LOGIN — first command from LoginCredential. Reply with a
            //    response code carrying the capabilities so we don't need a
            //    separate CAPABILITY round-trip.
            $readLine($peer); // A0001 LOGIN ...
            $writeLine($peer, '* CAPABILITY IMAP4rev1 ENABLE ID UTF8=ACCEPT IDLE');
            $writeLine($peer, 'A0001 OK [CAPABILITY IMAP4rev1 ENABLE ID UTF8=ACCEPT IDLE] LOGIN done');

            // 3) ENABLE CONDSTORE QRESYNC UTF8=ACCEPT
            $readLine($peer);
            $writeLine($peer, '* ENABLED CONDSTORE QRESYNC UTF8=ACCEPT');
            $writeLine($peer, 'A0002 OK ENABLE done');

            // 4) ID
            $readLine($peer);
            $writeLine($peer, '* ID ("name" "TestServer")');
            $writeLine($peer, 'A0003 OK ID done');

            // Hold the socket open briefly to avoid racing the client's last
            // response read.
            usleep(100_000);
        });

        $logPath = sys_get_temp_dir() . '/imap-connect-test-' . uniqid('', true) . '.log';

        try {
            $config = new Config(
                host: $server->host(),
                credential: new LoginCredential('user', 'pass'),
                port: $server->port(),
                encryption: Encryption::None,
                timeout: 2.0,
                enableCondstore: true,
                enableQresync: true,
                utf8Accept: true,
                clientId: ['name' => 'TestClient'],
                logPath: $logPath, // exercises the LoggingConnection wrap branch
            );

            $mailbox = Mailbox::connect($config);

            self::assertInstanceOf(Mailbox::class, $mailbox);
            self::assertFileExists($logPath);
        } finally {
            $server->reap($pid);
            $server->stop();
            @unlink($logPath);
        }
    }

    public function testConnectStartTlsBranchUpgradesAndRefreshesCapabilities(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }

        $server = new LoopbackServer();
        $server->start('starttls');

        $pid = $server->forkAccept(function ($peer): void {
            $readLine = static function ($peer): string {
                $line = '';
                while (($c = fread($peer, 1)) !== false && $c !== '') {
                    $line .= $c;
                    if (str_ends_with($line, "\r\n")) {
                        return $line;
                    }
                }
                return $line;
            };
            $writeLine = static function ($peer, string $line): void {
                fwrite($peer, $line . "\r\n");
                fflush($peer);
            };

            // 1) Greeting (plain).
            $writeLine($peer, '* OK ready');

            // 2) STARTTLS, then crypto upgrade.
            $readLine($peer); // A0001 STARTTLS
            $writeLine($peer, 'A0001 OK begin TLS');

            @stream_socket_enable_crypto(
                $peer,
                true,
                STREAM_CRYPTO_METHOD_TLSv1_2_SERVER
                | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER
                | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER,
            );

            // 3) refreshCapabilities() → CAPABILITY (no extensions to enable,
            //    no clientId — this exercises the "skip" branches of the
            //    feature-flag if-blocks).
            $readLine($peer); // A0002 CAPABILITY
            $writeLine($peer, '* CAPABILITY IMAP4rev1');
            $writeLine($peer, 'A0002 OK CAPABILITY done');

            // 4) LOGIN — bare OK with no CAPABILITY response code, so the
            //    handshake must follow up with a refresh.
            $readLine($peer); // A0003 LOGIN ...
            $writeLine($peer, 'A0003 OK LOGIN done');

            // 5) Post-auth refreshCapabilities() per RFC 3501 §6.2 — the
            //    server's advertised capabilities can change across LOGIN.
            $readLine($peer); // A0004 CAPABILITY
            $writeLine($peer, '* CAPABILITY IMAP4rev1');
            $writeLine($peer, 'A0004 OK CAPABILITY done');

            usleep(100_000);
        });

        try {
            $config = new Config(
                host: $server->host(),
                credential: new LoginCredential('user', 'pass'),
                port: $server->port(),
                encryption: Encryption::StartTls,
                timeout: 5.0,
                sslOptions: [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            );

            $mailbox = Mailbox::connect($config);

            self::assertInstanceOf(Mailbox::class, $mailbox);
        } finally {
            $server->reap($pid);
            $server->stop();
        }
    }

    public function testHandshakeRefreshesCapabilitiesPostAuthWhenLoginOmitsCapabilityCode(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }

        $server = new LoopbackServer();
        $server->start('plain');

        $pid = $server->forkAccept(function ($peer): void {
            $readLine = static function ($peer): string {
                $line = '';
                while (($c = fread($peer, 1)) !== false && $c !== '') {
                    $line .= $c;
                    if (str_ends_with($line, "\r\n")) {
                        return $line;
                    }
                }
                return $line;
            };
            $writeLine = static function ($peer, string $line): void {
                fwrite($peer, $line . "\r\n");
                fflush($peer);
            };

            // 1) Greeting carries pre-auth CAPABILITY *including* OBJECTID.
            //    Some servers advertise extensions pre-auth that they no
            //    longer support after LOGIN — this is the exact stale-cache
            //    scenario that produced "BAD Unknown parameter: EMAILID".
            $writeLine($peer, '* OK [CAPABILITY IMAP4rev1 OBJECTID] ready');

            // 2) LOGIN — bare OK, no [CAPABILITY ...] code on the reply.
            $readLine($peer); // A0001 LOGIN ...
            $writeLine($peer, 'A0001 OK LOGIN done');

            // 3) Post-auth refresh: server now reports a capability set
            //    *without* OBJECTID. The client must trust this and stop
            //    asking for EMAILID/THREADID.
            $readLine($peer); // A0002 CAPABILITY
            $writeLine($peer, '* CAPABILITY IMAP4rev1 IDLE');
            $writeLine($peer, 'A0002 OK CAPABILITY done');

            usleep(100_000);
        });

        try {
            $config = new Config(
                host: $server->host(),
                credential: new LoginCredential('user', 'pass'),
                port: $server->port(),
                encryption: Encryption::None,
                timeout: 2.0,
            );

            $mailbox = Mailbox::connect($config);

            $transceiverProp = (new ReflectionClass(Mailbox::class))->getProperty('transceiver');
            $transceiver = $transceiverProp->getValue($mailbox);
            self::assertInstanceOf(Transceiver::class, $transceiver);
            self::assertFalse(
                $transceiver->hasCapability(Capability::ObjectId),
                'Stale pre-auth OBJECTID must be evicted by post-auth CAPABILITY refresh',
            );
            // Pin a capability that the post-auth refresh response *did*
            // include — without an actual call to refreshCapabilities()
            // the cached set would be empty and this assertion would fail.
            // Kills the MethodCallRemoval mutant on
            // Transceiver::refreshCapabilities() line 343.
            self::assertTrue(
                $transceiver->hasCapability(Capability::Idle),
                'Post-auth refresh must populate the capability cache',
            );
        } finally {
            $server->reap($pid);
            $server->stop();
        }
    }

    public function testHandshakeSkipsExtraCapabilityRoundTripWhenLoginOkCarriesCapabilityCode(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }

        $server = new LoopbackServer();
        $server->start('plain');

        $pid = $server->forkAccept(function ($peer): void {
            $readLine = static function ($peer): string {
                $line = '';
                while (($c = fread($peer, 1)) !== false && $c !== '') {
                    $line .= $c;
                    if (str_ends_with($line, "\r\n")) {
                        return $line;
                    }
                }
                return $line;
            };
            $writeLine = static function ($peer, string $line): void {
                fwrite($peer, $line . "\r\n");
                fflush($peer);
            };

            // 1) Greeting (no capabilities).
            $writeLine($peer, '* OK ready');

            // 2) LOGIN — OK reply piggy-backs the capability list. The
            //    handshake must NOT issue a follow-up CAPABILITY command.
            $readLine($peer); // A0001 LOGIN ...
            $writeLine($peer, 'A0001 OK [CAPABILITY IMAP4rev1] LOGIN done');

            // If the client incorrectly sends another command, it'll appear
            // here. We deliberately do NOT respond — so the test would hang
            // (and fail via timeout) if the regression returns.
            usleep(100_000);
        });

        try {
            $config = new Config(
                host: $server->host(),
                credential: new LoginCredential('user', 'pass'),
                port: $server->port(),
                encryption: Encryption::None,
                timeout: 2.0,
            );

            $mailbox = Mailbox::connect($config);
            self::assertInstanceOf(Mailbox::class, $mailbox);
        } finally {
            $server->reap($pid);
            $server->stop();
        }
    }

    public function testConnectFailsFastWithEncryptionHintWhenPlainServerNeverSendsGreeting(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }

        // Plain TCP listener — accepts the connection but the handler just
        // sleeps without writing a greeting, so readGreeting() must hit the
        // greetingTimeout and the catch branch in performHandshake().
        $server = new LoopbackServer();
        $server->start('plain');

        $pid = $server->forkAccept(function ($peer): void {
            // Don't write anything. Hold the socket open long enough for the
            // client's short greetingTimeout to fire while we're still here.
            usleep(500_000);
        });

        try {
            $config = new Config(
                host: $server->host(),
                credential: new LoginCredential('user', 'pass'),
                port: $server->port(),
                encryption: Encryption::StartTls,
                timeout: 5.0,
                greetingTimeout: 0.3,
            );

            try {
                Mailbox::connect($config);
                self::fail('Expected ConnectionException');
            } catch (ConnectionException $e) {
                // Pin the exact full hint message — kills the Concat /
                // ConcatOperandRemoval mutants on the StartTls/None branch
                // of the buildGreetingTimeoutHint() match expression.
                $expected = sprintf(
                    'No IMAP greeting from %s:%d within 0.3s (encryption=StartTls).',
                    $server->host(),
                    $server->port(),
                ) . ' The server accepted the TCP connection but never sent a plaintext "* OK ..." line.'
                    . ' This usually means the port is implicit-TLS — try Encryption::Tls.';
                self::assertSame($expected, $e->getMessage());
                self::assertInstanceOf(
                    \D4ry\ImapClient\Exception\TimeoutException::class,
                    $e->getPrevious(),
                );
            }
        } finally {
            $server->reap($pid);
            $server->stop();
        }
    }

    public function testConnectFailsFastWithEncryptionHintWhenNoneServerNeverSendsGreeting(): void
    {
        // Same as the StartTls variant but with Encryption::None — kills the
        // MatchArmRemoval mutant that splits the `Encryption::StartTls,
        // Encryption::None` arm by ensuring BOTH inputs produce the hint.
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }

        $server = new LoopbackServer();
        $server->start('plain');

        $pid = $server->forkAccept(function ($peer): void {
            usleep(500_000);
        });

        try {
            $config = new Config(
                host: $server->host(),
                credential: new LoginCredential('user', 'pass'),
                port: $server->port(),
                encryption: Encryption::None,
                timeout: 5.0,
                greetingTimeout: 0.3,
            );

            try {
                Mailbox::connect($config);
                self::fail('Expected ConnectionException');
            } catch (ConnectionException $e) {
                $expected = sprintf(
                    'No IMAP greeting from %s:%d within 0.3s (encryption=None).',
                    $server->host(),
                    $server->port(),
                ) . ' The server accepted the TCP connection but never sent a plaintext "* OK ..." line.'
                    . ' This usually means the port is implicit-TLS — try Encryption::Tls.';
                self::assertSame($expected, $e->getMessage());
            }
        } finally {
            $server->reap($pid);
            $server->stop();
        }
    }

    public function testConnectFailsFastWithEncryptionHintWhenTlsServerNeverSendsGreeting(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }

        // Implicit-TLS listener — exercises the Encryption::Tls arm of the
        // hint builder. PHP's stream layer drives the TLS handshake during
        // accept(), then the handler holds the socket open without sending
        // an IMAP greeting, so the client times out post-handshake.
        $server = new LoopbackServer();
        $server->start('tls');

        $pid = $server->forkAccept(function ($peer): void {
            usleep(500_000);
        });

        try {
            $config = new Config(
                host: $server->host(),
                credential: new LoginCredential('user', 'pass'),
                port: $server->port(),
                encryption: Encryption::Tls,
                timeout: 5.0,
                greetingTimeout: 0.3,
                sslOptions: [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            );

            try {
                Mailbox::connect($config);
                self::fail('Expected ConnectionException');
            } catch (ConnectionException $e) {
                // Pin the exact full hint message — kills the Concat /
                // ConcatOperandRemoval mutants on the Tls branch of
                // buildGreetingTimeoutHint().
                $expected = sprintf(
                    'No IMAP greeting from %s:%d within 0.3s (encryption=Tls).',
                    $server->host(),
                    $server->port(),
                ) . ' The TLS handshake completed but the server never sent an IMAP greeting.'
                    . ' The port may not be implicit-TLS — try Encryption::StartTls or Encryption::None,'
                    . ' or check that the port actually speaks IMAP.';
                self::assertSame($expected, $e->getMessage());
                self::assertInstanceOf(
                    \D4ry\ImapClient\Exception\TimeoutException::class,
                    $e->getPrevious(),
                );
            }
        } finally {
            $server->reap($pid);
            $server->stop();
        }
    }

    public function testConnectFromRecordingReplaysCapturedSession(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required');
        }

        $server = new LoopbackServer();
        $server->start('plain');

        $pid = $server->forkAccept(function ($peer): void {
            $readLine = static function ($peer): string {
                $line = '';
                while (($c = fread($peer, 1)) !== false && $c !== '') {
                    $line .= $c;
                    if (str_ends_with($line, "\r\n")) {
                        return $line;
                    }
                }
                return $line;
            };
            $writeLine = static function ($peer, string $line): void {
                fwrite($peer, $line . "\r\n");
                fflush($peer);
            };

            // 1) Greeting.
            $writeLine($peer, '* OK ready');

            // 2) LOGIN — capabilities piggy-backed on the OK response code so
            //    no separate CAPABILITY round-trip is needed.
            $readLine($peer); // A0001 LOGIN ...
            $writeLine($peer, '* CAPABILITY IMAP4rev1 ENABLE ID UTF8=ACCEPT IDLE');
            $writeLine($peer, 'A0001 OK [CAPABILITY IMAP4rev1 ENABLE ID UTF8=ACCEPT IDLE] LOGIN done');

            // 3) ENABLE CONDSTORE QRESYNC UTF8=ACCEPT
            $readLine($peer);
            $writeLine($peer, '* ENABLED CONDSTORE QRESYNC UTF8=ACCEPT');
            $writeLine($peer, 'A0002 OK ENABLE done');

            // 4) ID
            $readLine($peer);
            $writeLine($peer, '* ID ("name" "TestServer")');
            $writeLine($peer, 'A0003 OK ID done');

            usleep(100_000);
        });

        $recordPath = sys_get_temp_dir() . '/imap-record-test-' . uniqid('', true) . '.jsonl';

        try {
            // 1. Live connect — captures the session to disk. Redaction is
            //    disabled so the recorded LOGIN write matches what the replay
            //    will produce when authenticate() runs again.
            $recordingConfig = new Config(
                host: $server->host(),
                credential: new LoginCredential('user', 'pass'),
                port: $server->port(),
                encryption: Encryption::None,
                timeout: 2.0,
                enableCondstore: true,
                enableQresync: true,
                utf8Accept: true,
                clientId: ['name' => 'TestClient'],
                recordPath: $recordPath,
                recordRedactCredentials: false,
            );

            $live = Mailbox::connect($recordingConfig);
            self::assertInstanceOf(Mailbox::class, $live);
            self::assertFileExists($recordPath);
            self::assertGreaterThan(0, filesize($recordPath));

            // Inspect the captured wire writes to assert the connect-time
            // ENABLE and ID lines were exactly what we expect. This pins
            // the byte format of both commands and kills the
            // NotIdentical / LogicalAnd / MethodCallRemoval mutants on the
            // ENABLE block (line 138-139) and reinforces the byte-format
            // assertions for the connect-time ID block (line 143-149).
            $writes = [];
            foreach (file($recordPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (($event['t'] ?? null) === 'write') {
                    $writes[] = $event['data'];
                }
            }
            $writeBlob = implode('', $writes);
            self::assertStringContainsString(
                "ENABLE CONDSTORE QRESYNC UTF8=ACCEPT\r\n",
                $writeBlob,
                'connect handshake must send the exact ENABLE line for the requested extensions',
            );
            self::assertStringContainsString(
                "ID (\"name\" \"TestClient\")\r\n",
                $writeBlob,
                'connect handshake must send the exact ID line for the configured clientId',
            );

            // 2. Replay against the captured fixture — no network, no server.
            //    The Config's host/port/encryption/timeout/sslOptions are
            //    accepted but ignored; credentials and feature flags must
            //    match what was recorded.
            $replayConfig = new Config(
                host: 'replay.invalid',
                credential: new LoginCredential('user', 'pass'),
                port: 993,
                encryption: Encryption::None,
                timeout: 2.0,
                enableCondstore: true,
                enableQresync: true,
                utf8Accept: true,
                clientId: ['name' => 'TestClient'],
            );

            $replayed = Mailbox::connectFromRecording($recordPath, $replayConfig);

            self::assertInstanceOf(Mailbox::class, $replayed);
        } finally {
            $server->reap($pid);
            $server->stop();
            @unlink($recordPath);
        }
    }
}
