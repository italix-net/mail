<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - Transport
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

/**
 * How a message actually leaves.
 *
 * The seam that keeps PHPMailer an application choice rather than a framework
 * dependency: `SmtpTransport` ships in core, and an adapter over PHPMailer,
 * Amazon SES or a testing double is a class implementing this.
 *
 * A transport **throws `TransportFailure`** rather than returning false. The
 * distinction matters: `Mailer` has already written the log row by the time it
 * calls this, so a thrown failure updates a row that exists, while a `false`
 * quietly swallowed at some call site is the exact history this library was
 * written against.
 */
interface Transport
{
    /**
     * Deliver $raw to the message's envelope recipients.
     *
     * @param  string $raw the complete RFC 5322 message
     * @throws TransportFailure when delivery does not succeed
     */
    public function send(Message $message, string $raw): void;

    /**
     * A short description for the log: `smtp:mail.example.org:465`.
     */
    public function describe(): string;
}
