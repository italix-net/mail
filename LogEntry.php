<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - LogEntry
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

/**
 * One row of the mail log.
 *
 * `state_c` moves `queued` → `sent` | `failed`, and the fact that `queued` is a
 * state a row can be *left* in is the point of the whole design: a process that
 * dies between writing the row and hearing back from the server leaves evidence
 * that something was attempted and never got a verdict.
 */
final class LogEntry
{
    public const QUEUED = 'queued';
    public const SENT   = 'sent';
    public const FAILED = 'failed';

    public int $log_id = 0;
    public string $state_c = self::QUEUED;
    public string $to_email = '';
    public string $subject = '';
    public string $context_c = '';
    public string $transport_c = '';
    public ?string $error_c = null;
    public ?string $detail = null;
    public string $insert_dt = '';
    public ?string $settled_dt = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        $entry = new self();

        $entry->log_id      = (int) ($row['id'] ?? 0);
        $entry->state_c     = (string) ($row['state_c'] ?? self::QUEUED);
        $entry->to_email    = (string) ($row['to_email'] ?? '');
        $entry->subject     = (string) ($row['subject'] ?? '');
        $entry->context_c   = (string) ($row['context_c'] ?? '');
        $entry->transport_c = (string) ($row['transport_c'] ?? '');
        $entry->error_c     = isset($row['error_c']) ? (string) $row['error_c'] : null;
        $entry->detail      = isset($row['detail']) ? (string) $row['detail'] : null;
        $entry->insert_dt   = (string) ($row['insert_dt'] ?? '');
        $entry->settled_dt  = isset($row['settled_dt']) ? (string) $row['settled_dt'] : null;

        return $entry;
    }
}
