<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - SmtpTransport
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

/**
 * SMTP over a socket, with the reply codes mapped to machine error codes.
 *
 * About 200 lines, which is why PHPMailer does not need to be a framework
 * dependency. What it does *not* do is the reason it stays that size: no
 * attachments, no DKIM signing, no connection pooling, no pipelining. An
 * application that needs those writes a `Transport` over PHPMailer and loses
 * nothing else.
 *
 * ## Reply codes become error codes
 *
 * | SMTP | error_c | why it matters |
 * |---|---|---|
 * | 535, 530 | `auth` | credentials, not the recipient — retrying will not help |
 * | 550, 553, 551 | `recipient_rejected` | the address is wrong; retrying will not help either |
 * | 421, 450, 451, 452 | `throttled` | temporary; retrying *is* the answer |
 * | socket timeout | `timeout` | |
 * | anything else | `transport` | |
 *
 * That split is what lets a queue decide whether a retry is worth attempting,
 * instead of hammering a server that has already said no permanently.
 *
 * The stream factory is injectable, which is how the conversation is tested
 * against a scripted server rather than against the internet.
 */
final class SmtpTransport implements Transport
{
    private const CRLF = "\r\n";

    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $security_c;   // 'tls' | 'ssl' | 'none'
    private int $timeout_n;
    private string $helo_c;

    /** @var callable|null fn(string $dsn, int $timeout_n) */
    private $connector;

    /** @var resource|null */
    private $socket;

    /** @var string[] the whole conversation, for diagnostics */
    private array $transcript = [];

    public function __construct(
        string $host,
        int $port = 587,
        string $username = '',
        string $password = '',
        string $security_c = 'tls',
        int $timeout_n = 15,
        string $helo_c = 'localhost',
        ?callable $connector = null
    ) {
        $this->host       = $host;
        $this->port       = $port;
        $this->username   = $username;
        $this->password   = $password;
        $this->security_c = $security_c;
        $this->timeout_n  = max(1, $timeout_n);
        $this->helo_c     = $helo_c;
        $this->connector  = $connector;
    }

    public function describe(): string
    {
        return sprintf('smtp:%s:%d (%s)', $this->host, $this->port, $this->security_c);
    }

    /**
     * The conversation from the last attempt — what actually went over the wire.
     */
    public function transcript(): string
    {
        return implode("\n", $this->transcript);
    }

    public function send(Message $message, string $raw): void
    {
        $this->transcript = [];

        $from = $message->get_from();

        if ($from === null) {
            throw new TransportFailure('transport', 'No From address: SMTP needs an envelope sender.');
        }

        $this->connect();

        try {
            $this->expect(220);
            $this->command('EHLO ' . $this->helo_c, 250);

            if ($this->security_c === 'tls') {
                $this->command('STARTTLS', 220);
                $this->enable_crypto();
                // A second EHLO is mandatory: the capabilities before and after
                // the handshake are different, and AUTH usually appears only
                // after it.
                $this->command('EHLO ' . $this->helo_c, 250);
            }

            if ($this->username !== '') {
                $this->authenticate();
            }

            $this->command('MAIL FROM:<' . $from->email() . '>', 250);

            foreach ($message->envelope_recipients() as $recipient) {
                $this->command('RCPT TO:<' . $recipient->email() . '>', [250, 251]);
            }

            $this->command('DATA', 354);
            $this->write(Mime::dot_stuff($raw) . self::CRLF . '.' . self::CRLF);
            $this->expect(250);

            // QUIT is best-effort: the message is accepted once 250 arrives, and
            // a server that hangs up rudely afterwards has not un-sent it.
            try {
                $this->command('QUIT', 221);
            } catch (TransportFailure $e) {
                // Deliberately ignored.
            }
        } finally {
            $this->disconnect();
        }
    }

    // -------------------------------------------------------------------------
    // Conversation
    // -------------------------------------------------------------------------

    private function connect(): void
    {
        $scheme = $this->security_c === 'ssl' ? 'ssl://' : 'tcp://';
        $dsn    = $scheme . $this->host . ':' . $this->port;

        if ($this->connector !== null) {
            $socket = ($this->connector)($dsn, $this->timeout_n);
        } else {
            $socket = @stream_socket_client(
                $dsn,
                $errno,
                $errstr,
                $this->timeout_n,
                STREAM_CLIENT_CONNECT
            );

            if ($socket === false) {
                throw new TransportFailure(
                    'transport',
                    sprintf('Cannot reach %s: %s', $dsn, (string) ($errstr ?? 'unknown error'))
                );
            }
        }

        if (!is_resource($socket)) {
            throw new TransportFailure('transport', "Cannot reach {$dsn}.");
        }

        stream_set_timeout($socket, $this->timeout_n);

        $this->socket = $socket;
    }

    private function enable_crypto(): void
    {
        $ok = @stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($ok !== true) {
            throw new TransportFailure('transport', 'STARTTLS handshake failed.');
        }
    }

    private function authenticate(): void
    {
        // AUTH LOGIN: universally supported, and the base64 is an encoding not a
        // protection — which is why `security_c` should not be 'none' in
        // production, and why that is worth saying here rather than assuming.
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($this->username), 334);
        $this->command(base64_encode($this->password), 235);
    }

    /**
     * @param int|int[] $expected
     */
    private function command(string $line, $expected): string
    {
        $this->write($line . self::CRLF);

        // Never let a password reach a log or a transcript.
        $this->transcript[] = '> ' . (strlen($line) > 0 && $this->looks_like_secret($line) ? '***' : $line);

        return $this->expect($expected, false);
    }

    /**
     * @param int|int[] $expected
     */
    private function expect($expected, bool $record = true): string
    {
        $reply = $this->read();

        if ($record) {
            $this->transcript[] = '< ' . $reply;
        } else {
            $this->transcript[] = '< ' . $reply;
        }

        $code     = (int) substr($reply, 0, 3);
        $accepted = is_array($expected) ? $expected : [$expected];

        if (in_array($code, $accepted, true)) {
            return $reply;
        }

        throw new TransportFailure(self::error_code_for($code), $this->failure_message($code, $reply), $reply);
    }

    private function read(): string
    {
        $reply = '';

        while (true) {
            $line = fgets($this->socket, 8192);

            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);

                throw new TransportFailure(
                    ($meta['timed_out'] ?? false) ? 'timeout' : 'transport',
                    ($meta['timed_out'] ?? false)
                        ? "The server stopped answering after {$this->timeout_n}s."
                        : 'The server closed the connection.'
                );
            }

            $reply .= $line;

            // A multi-line reply has a dash after the code on every line but the
            // last: "250-SIZE" then "250 HELP".
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        return rtrim($reply, "\r\n");
    }

    private function write(string $data): void
    {
        if (@fwrite($this->socket, $data) === false) {
            throw new TransportFailure('transport', 'The connection was lost while writing.');
        }
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
    }

    // -------------------------------------------------------------------------
    // Mapping
    // -------------------------------------------------------------------------

    public static function error_code_for(int $smtp_code): string
    {
        if (in_array($smtp_code, [530, 534, 535, 538], true)) {
            return 'auth';
        }

        if (in_array($smtp_code, [550, 551, 553, 554], true)) {
            return 'recipient_rejected';
        }

        if (in_array($smtp_code, [421, 450, 451, 452, 471], true)) {
            return 'throttled';
        }

        return 'transport';
    }

    private function failure_message(int $code, string $reply): string
    {
        return sprintf('SMTP %d from %s: %s', $code, $this->host, trim($reply));
    }

    private function looks_like_secret(string $line): bool
    {
        // The two lines after AUTH LOGIN are the credentials, base64 encoded.
        $previous = end($this->transcript);

        return is_string($previous) && strpos($previous, '334') !== false;
    }
}
