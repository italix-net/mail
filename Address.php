<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - Address
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

/**
 * One recipient: an address, and optionally a name.
 *
 * **A newline in either is refused, not escaped.** `To: a@b.it\r\nBcc: attacker@x`
 * is header injection, and no amount of encoding makes that one header again —
 * the same position `Html::attribs()` takes on attribute names. Every string
 * that reaches a header passes through here or through `Mime::header_value()`.
 */
final class Address
{
    private string $email;
    private string $name;

    public function __construct(string $email, string $name = '')
    {
        // Checked *before* trimming, deliberately. `trim()` strips CR, LF and
        // NUL, so validating afterwards would quietly accept "a@b.it\r\n" by
        // silently rewriting the caller's data — and an address with a newline
        // in it is malformed input worth refusing, not tidying.
        self::refuse_control_characters($email, 'address');
        self::refuse_control_characters($name, 'name');

        $email = trim($email);
        $name  = trim($name);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("Not a usable e-mail address: \"{$email}\".");
        }

        $this->email = $email;
        $this->name  = $name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The header form: `Name <a@b.it>`, or bare when there is no name.
     *
     * A name containing anything outside printable ASCII is RFC 2047 encoded
     * rather than sent raw — "Rossi Società" in a header is otherwise at the
     * mercy of whatever the receiving server assumes.
     */
    public function to_header(): string
    {
        if ($this->name === '') {
            return $this->email;
        }

        $name = preg_match('/^[\x20-\x7E]*$/', $this->name) === 1
            ? '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $this->name) . '"'
            : '=?UTF-8?B?' . base64_encode($this->name) . '?=';

        return $name . ' <' . $this->email . '>';
    }

    public function __toString(): string
    {
        return $this->to_header();
    }

    private static function refuse_control_characters(string $value, string $what): void
    {
        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new MailException(
                "A newline or NUL in a recipient {$what} is header injection; refused rather than escaped."
            );
        }
    }
}
