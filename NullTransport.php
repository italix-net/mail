<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - NullTransport
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

/**
 * Accepts everything, sends nothing, remembers all of it.
 *
 * For tests, and for a development environment where mail must not escape. It
 * still goes through `Mailer`, so the log row is written exactly as in
 * production — a developer checking `ix mail:status` sees the same thing an
 * operator would.
 *
 * `fail_with()` makes it refuse, which is how the failure paths get tested
 * without an unreachable server.
 */
final class NullTransport implements Transport
{
    /** @var array<int, array{message: Message, raw: string}> */
    private array $sent = [];

    private ?string $fail_error_c = null;
    private string $fail_reply = '';

    public function fail_with(string $error_c, string $server_reply = 'simulated'): self
    {
        $this->fail_error_c = $error_c;
        $this->fail_reply   = $server_reply;

        return $this;
    }

    public function succeed(): self
    {
        $this->fail_error_c = null;

        return $this;
    }

    public function send(Message $message, string $raw): void
    {
        if ($this->fail_error_c !== null) {
            throw new TransportFailure($this->fail_error_c, 'NullTransport was told to fail.', $this->fail_reply);
        }

        $this->sent[] = ['message' => $message, 'raw' => $raw];
    }

    public function describe(): string
    {
        return 'null';
    }

    /**
     * @return array<int, array{message: Message, raw: string}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function last_raw(): string
    {
        $last = end($this->sent);

        return $last === false ? '' : $last['raw'];
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
