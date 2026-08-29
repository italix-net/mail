<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - MailLog
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

/**
 * The record of every attempt, written **before** the attempt.
 *
 * That ordering is the whole library. The history it replaces: every mailer in
 * this application caught its own SMTP failure and reported it with
 * `error_log()`, which on that server wrote nowhere — php-fpm had no `error_log`
 * for the pool and `catch_workers_output` was off. The UI said "sent" either
 * way, and nobody could answer whether a single message had ever left the
 * building.
 *
 * A log written *after* a successful send has the same defect in a smaller
 * form: a process that dies mid-send leaves nothing at all. So `open()` writes
 * a `queued` row and returns its id, and `settle()` moves it to `sent` or
 * `failed`. A row still `queued` an hour later is the most informative thing in
 * the table.
 */
interface MailLog
{
    /**
     * Record an attempt about to be made. Returns the row id.
     */
    public function open(
        string $to_email,
        string $subject,
        string $context_c,
        string $transport_c
    ): int;

    /**
     * Record how it went.
     *
     * @param string      $state_c LogEntry::SENT or LogEntry::FAILED
     * @param string|null $error_c machine code when failed
     */
    public function settle(int $log_id, string $state_c, ?string $error_c = null, ?string $detail = null): void;

    /**
     * @param  string|null $state_c limit to one state
     * @return LogEntry[]           newest first
     */
    public function recent(int $since_t, ?string $state_c = null, int $limit_n = 100): array;

    /**
     * Counts by state since $since_t.
     *
     * @return array<string, int>
     */
    public function summary(int $since_t): array;

    /**
     * Rows still `queued` and older than $before_t: attempts that never got a
     * verdict. The most interesting query in the table.
     *
     * @return LogEntry[]
     */
    public function stranded(int $before_t, int $limit_n = 50): array;
}
