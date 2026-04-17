<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Notify;

use D4ry\ImapClient\Enum\Capability;
use D4ry\ImapClient\Exception\TimeoutException;
use D4ry\ImapClient\Protocol\Transceiver;

/**
 * Shared drain loop used by Mailbox::listenForNotifications() and the
 * folder-scoped convenience helpers. Kept as a dedicated helper so the
 * same logic is not duplicated between the entry points.
 *
 * @infection-ignore-all  live-socket loop, same rationale as Mailbox::idle()
 */
final class NotifyListener
{
    /**
     * Pump untagged server pushes off the transceiver's connection and
     * dispatch them through the given handler. Returns when the handler
     * returns false or the wall-clock timeout elapses.
     */
    public static function drain(
        Transceiver $transceiver,
        NotifyHandlerInterface|callable $handler,
        float $timeout,
    ): void {
        $transceiver->requireCapability(Capability::Notify);

        $dispatcher = new NotifyDispatcher($handler);
        $parser = $transceiver->getResponseParser();
        $connection = $transceiver->getConnection();
        $startTime = microtime(true);

        while (microtime(true) - $startTime < $timeout) {
            try {
                $line = $connection->readLine();
            } catch (TimeoutException) {
                continue;
            }

            $line = rtrim($line, "\r\n");
            if ($line === '' || !str_starts_with($line, '* ')) {
                continue;
            }

            $untagged = $parser->parseUntaggedLineForDispatch($line);

            if ($dispatcher->dispatch($untagged) === false) {
                break;
            }
        }
    }

    /**
     * Configure a NOTIFY subscription for one or more mailbox names,
     * drain events, then tear the subscription down with NOTIFY NONE on
     * exit (best-effort — swallowed if the connection is unusable).
     *
     * @param string[] $mailboxNames
     * @param NotifyEventType[] $events Defaults to MessageNew + MessageExpunge + FlagChange.
     */
    public static function listenToMailboxes(
        Transceiver $transceiver,
        array $mailboxNames,
        NotifyHandlerInterface|callable $handler,
        float $timeout,
        array $events = [],
        bool $includeSubtree = false,
    ): void {
        if ($mailboxNames === []) {
            throw new \InvalidArgumentException('listenToMailboxes() requires at least one mailbox name');
        }

        $filter = $includeSubtree
            ? MailboxFilter::subtree($mailboxNames)
            : MailboxFilter::mailboxes($mailboxNames);

        if ($events === []) {
            $events = [
                NotifyEventType::MessageNew,
                NotifyEventType::MessageExpunge,
                NotifyEventType::FlagChange,
            ];
        }

        $group = new EventGroup($filter, $events);

        $transceiver->requireCapability(Capability::Notify);
        $transceiver->command('NOTIFY', 'SET', $group->toGroupToken($transceiver->isUtf8Enabled()));

        try {
            self::drain($transceiver, $handler, $timeout);
        } finally {
            try {
                $transceiver->command('NOTIFY', 'NONE');
            } catch (\Throwable) {
                // Best effort teardown.
            }
        }
    }
}
