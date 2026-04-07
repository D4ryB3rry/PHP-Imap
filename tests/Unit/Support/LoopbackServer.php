<?php

declare(strict_types=1);

namespace D4ry\ImapClient\Tests\Unit\Support;

use RuntimeException;

/**
 * Tiny in-process loopback TCP/TLS server for transport-level unit tests.
 *
 * - start() binds 127.0.0.1 to a random free port and starts listening.
 * - Plain TCP only requires a listen socket — accept() can be deferred until
 *   after the client has connected because the kernel queues the SYN.
 * - For TLS scenarios use forkAccept() which forks a child process that does
 *   the server-side accept + crypto handshake while the parent test code
 *   drives the client.
 */
final class LoopbackServer
{
    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    private ?string $certFile = null;

    /**
     * @param string $mode 'plain' (tcp, no ssl), 'tls' (implicit TLS), or
     *                     'starttls' (tcp listener with ssl context for an
     *                     explicit upgrade by the test)
     */
    public function start(string $mode = 'plain'): void
    {
        $opts = [];
        if ($mode === 'tls' || $mode === 'starttls') {
            $this->certFile = $this->createSelfSignedCert();
            $opts['ssl'] = [
                'local_cert' => $this->certFile,
                'allow_self_signed' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ];
        }

        $context = stream_context_create($opts);
        $errno = 0;
        $errstr = '';

        $scheme = $mode === 'tls' ? 'tls' : 'tcp';
        $server = @stream_socket_server(
            "{$scheme}://127.0.0.1:0",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($server === false) {
            throw new RuntimeException(sprintf('LoopbackServer bind failed: [%d] %s', $errno, $errstr));
        }

        $this->server = $server;
        $name = stream_socket_get_name($server, false);
        if ($name === false) {
            throw new RuntimeException('Could not read server socket name');
        }
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
    }

    public function host(): string
    {
        return '127.0.0.1';
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Accept the next pending client. The kernel queues the SYN so this can be
     * called after the client's open() has returned.
     *
     * @return resource
     */
    public function accept(float $timeout = 2.0)
    {
        if ($this->server === null) {
            throw new RuntimeException('Server not started');
        }

        $peer = @stream_socket_accept($this->server, $timeout);
        if ($peer === false) {
            throw new RuntimeException('LoopbackServer accept failed');
        }

        return $peer;
    }

    /**
     * Fork a server-side handler. Child runs $handler(resource $peer) and
     * exits; parent returns the child PID. Tests must call reap() to wait.
     */
    public function forkAccept(callable $handler): int
    {
        if (!function_exists('pcntl_fork')) {
            throw new RuntimeException('pcntl extension required for TLS server tests');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('fork failed');
        }

        if ($pid === 0) {
            // Child
            try {
                $peer = @stream_socket_accept($this->server, 5.0);
                if ($peer !== false) {
                    $handler($peer);
                    @fclose($peer);
                }
            } catch (\Throwable) {
                // Swallow — child must exit cleanly so parent test reports the failure.
            }
            exit(0);
        }

        return $pid;
    }

    public function reap(int $pid): void
    {
        if (function_exists('pcntl_waitpid')) {
            pcntl_waitpid($pid, $status);
        }
    }

    public function stop(): void
    {
        if ($this->server !== null) {
            @fclose($this->server);
            $this->server = null;
        }

        if ($this->certFile !== null && is_file($this->certFile)) {
            @unlink($this->certFile);
            $this->certFile = null;
        }
    }

    private function createSelfSignedCert(): string
    {
        $pkey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($pkey === false) {
            throw new RuntimeException('openssl_pkey_new failed');
        }

        $dn = [
            'commonName' => '127.0.0.1',
            'countryName' => 'XX',
            'organizationName' => 'ImapClient Test',
        ];

        $csr = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            throw new RuntimeException('openssl_csr_new failed');
        }

        $cert = openssl_csr_sign($csr, null, $pkey, 1, ['digest_alg' => 'sha256']);
        if ($cert === false) {
            throw new RuntimeException('openssl_csr_sign failed');
        }

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $keyPem);

        $path = tempnam(sys_get_temp_dir(), 'lb-cert-');
        if ($path === false) {
            throw new RuntimeException('tempnam failed');
        }
        file_put_contents($path, $certPem . $keyPem);

        return $path;
    }
}
