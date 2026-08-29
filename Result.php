<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - Result
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

/**
 * What happened to one message.
 *
 * `log_id()` is the field that makes this useful rather than decorative: it is
 * the row *already written*, so an error message can name it and an operator
 * can go straight to `ix mail:status` instead of correlating timestamps.
 */
final class Result
{
    private bool $sent_flag;
    private int $log_id;
    private string $error_c;
    private string $detail;

    private function __construct(bool $sent_flag, int $log_id, string $error_c = '', string $detail = '')
    {
        $this->sent_flag = $sent_flag;
        $this->log_id    = $log_id;
        $this->error_c   = $error_c;
        $this->detail    = $detail;
    }

    public static function sent(int $log_id): self
    {
        return new self(true, $log_id);
    }

    public static function failed(int $log_id, string $error_c, string $detail): self
    {
        return new self(false, $log_id, $error_c, $detail);
    }

    public function is_sent(): bool
    {
        return $this->sent_flag;
    }

    /** '' when sent; otherwise transport | auth | recipient_rejected | timeout | throttled */
    public function error_code(): string
    {
        return $this->error_c;
    }

    /** Exactly what the server said, when it said anything. */
    public function detail(): string
    {
        return $this->detail;
    }

    /** The mail-log row this attempt wrote. */
    public function log_id(): int
    {
        return $this->log_id;
    }

    /**
     * Whether trying again could plausibly work.
     *
     * `auth` and `recipient_rejected` are permanent: the credentials are wrong
     * or the address does not exist, and a queue that retries them turns one
     * misconfiguration into a thousand log lines.
     */
    public function is_retryable(): bool
    {
        return !$this->sent_flag
            && in_array($this->error_c, ['transport', 'timeout', 'throttled'], true);
    }
}
