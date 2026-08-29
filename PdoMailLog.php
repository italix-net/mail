<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - PdoMailLog
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

use PDO;

/**
 * The mail log in one table.
 *
 * A table rather than a file, and the reason is `ix mail:status`: "how many
 * failed in the last day, and to whom" is a query, and against an append-only
 * text file it is a `grep` with an argument nobody remembers. The file this
 * replaces was also written with `@file_put_contents`, so a log that could not
 * be written failed as silently as the mail it was supposed to record.
 */
final class PdoMailLog implements MailLog
{
    public const DEFAULT_TABLE = 'ix_mail_log';

    private PDO $pdo;
    private string $table;
    private string $driver_c;

    public function __construct(PDO $pdo, string $table = self::DEFAULT_TABLE)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new MailException("Refusing to interpolate \"{$table}\" as a table name.");
        }

        $this->pdo      = $pdo;
        $this->table    = $table;
        $this->driver_c = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function install(): void
    {
        $t = $this->quoted();

        $sql = $this->driver_c === 'mysql'
            ? "CREATE TABLE IF NOT EXISTS {$t} ("
                . ' id BIGINT AUTO_INCREMENT PRIMARY KEY,'
                . ' state_c VARCHAR(10) NOT NULL,'
                . ' to_email VARCHAR(255) NOT NULL,'
                . ' subject VARCHAR(255) NOT NULL,'
                . ' context_c VARCHAR(160) NOT NULL DEFAULT "",'
                . ' transport_c VARCHAR(80) NOT NULL DEFAULT "",'
                . ' error_c VARCHAR(32) NULL,'
                . ' detail TEXT NULL,'
                . ' insert_dt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . ' settled_dt DATETIME NULL,'
                . ' INDEX ix_mail_log_state (state_c, insert_dt)'
                . ') ENGINE=InnoDB'
            : "CREATE TABLE IF NOT EXISTS {$t} ("
                . ' id BIGSERIAL PRIMARY KEY,'
                . " state_c VARCHAR(10) NOT NULL,"
                . ' to_email VARCHAR(255) NOT NULL,'
                . ' subject VARCHAR(255) NOT NULL,'
                . " context_c VARCHAR(160) NOT NULL DEFAULT '',"
                . " transport_c VARCHAR(80) NOT NULL DEFAULT '',"
                . ' error_c VARCHAR(32) NULL,'
                . ' detail TEXT NULL,'
                . ' insert_dt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . ' settled_dt TIMESTAMP NULL)';

        $this->pdo->exec($sql);
    }

    public function open(string $to_email, string $subject, string $context_c, string $transport_c): int
    {
        $this->pdo->prepare(
            "INSERT INTO {$this->quoted()} (state_c, to_email, subject, context_c, transport_c)"
            . ' VALUES (?, ?, ?, ?, ?)'
        )->execute([
            LogEntry::QUEUED,
            self::fit($to_email, 255),
            self::fit($subject, 255),
            self::fit($context_c, 160),
            self::fit($transport_c, 80),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function settle(int $log_id, string $state_c, ?string $error_c = null, ?string $detail = null): void
    {
        $this->pdo->prepare(
            "UPDATE {$this->quoted()} SET state_c = ?, error_c = ?, detail = ?,"
            . ' settled_dt = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$state_c, $error_c, $detail, $log_id]);
    }

    public function recent(int $since_t, ?string $state_c = null, int $limit_n = 100): array
    {
        $sql    = "SELECT * FROM {$this->quoted()} WHERE insert_dt >= ?";
        $params = [date('Y-m-d H:i:s', $since_t)];

        if ($state_c !== null) {
            $sql     .= ' AND state_c = ?';
            $params[] = $state_c;
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, $limit_n);

        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        return array_map(
            static function (array $row): LogEntry { return LogEntry::from_row($row); },
            $st->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function summary(int $since_t): array
    {
        $st = $this->pdo->prepare(
            "SELECT state_c, COUNT(*) AS n FROM {$this->quoted()} WHERE insert_dt >= ? GROUP BY state_c"
        );
        $st->execute([date('Y-m-d H:i:s', $since_t)]);

        $summary = [];

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summary[(string) $row['state_c']] = (int) $row['n'];
        }

        return $summary;
    }

    public function stranded(int $before_t, int $limit_n = 50): array
    {
        $st = $this->pdo->prepare(
            "SELECT * FROM {$this->quoted()} WHERE state_c = ? AND insert_dt < ?"
            . ' ORDER BY id DESC LIMIT ' . max(1, $limit_n)
        );
        $st->execute([LogEntry::QUEUED, date('Y-m-d H:i:s', $before_t)]);

        return array_map(
            static function (array $row): LogEntry { return LogEntry::from_row($row); },
            $st->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private static function fit(string $value, int $length_n): string
    {
        return mb_substr($value, 0, $length_n);
    }

    private function quoted(): string
    {
        return $this->driver_c === 'mysql' ? '`' . $this->table . '`' : '"' . $this->table . '"';
    }
}
