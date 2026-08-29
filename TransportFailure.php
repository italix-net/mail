<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - TransportFailure
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

use RuntimeException;

/**
 * A delivery attempt that did not succeed, carrying a machine code.
 *
 * Thrown by a `Transport` and caught by `Mailer`, which turns it into a
 * `Result` and a log row. It exists so a transport can say *why* in a way the
 * caller can branch on without parsing an English sentence.
 *
 * `error_code()`: `transport | auth | recipient_rejected | timeout | throttled | encoding`
 */
final class TransportFailure extends RuntimeException
{
    private string $error_c;
    private string $server_reply;

    public function __construct(string $error_c, string $message, string $server_reply = '')
    {
        parent::__construct($message);

        $this->error_c      = $error_c;
        $this->server_reply = $server_reply;
    }

    public function error_code(): string
    {
        return $this->error_c;
    }

    /**
     * Exactly what the server said. Kept verbatim because the useful half of an
     * SMTP diagnostic is usually the part nobody anticipated.
     */
    public function server_reply(): string
    {
        return $this->server_reply;
    }
}
