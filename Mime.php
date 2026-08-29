<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - Mime
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

/**
 * Turns a `Message` into the bytes that go down the wire.
 *
 * Three details that are easy to get wrong and invisible when they are:
 *
 * **Headers are folded and encoded, never concatenated raw.** A subject with an
 * accent sent as-is is at the mercy of whatever the receiving server assumes;
 * RFC 2047 removes the guess.
 *
 * **Lines are CRLF.** SMTP is a line protocol and a bare `\n` is not a line
 * ending. Some servers accept it, which is worse than none doing so.
 *
 * **A line consisting of a single dot is escaped.** In SMTP's DATA phase `.` on
 * its own ends the message, so a body containing one would truncate the mail —
 * a bug that appears only for the one customer whose text happens to contain it.
 */
final class Mime
{
    private const CRLF = "\r\n";

    private function __construct()
    {
    }

    /**
     * Build the complete message: headers, blank line, body.
     */
    public static function build(Message $message, string $text, string $html, ?string $message_id_c = null): string
    {
        $headers = self::headers($message, $message_id_c);
        $body    = self::body($text, $html, $boundary);

        if ($boundary !== null) {
            $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
        } elseif ($html !== '') {
            $headers['Content-Type']              = 'text/html; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'quoted-printable';
        } else {
            $headers['Content-Type']              = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'quoted-printable';
        }

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode(self::CRLF, $lines) . self::CRLF . self::CRLF . $body;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(Message $message, ?string $message_id_c): array
    {
        $headers = [
            'MIME-Version' => '1.0',
            'Date'         => date('r'),
        ];

        if ($message->get_from() !== null) {
            $headers['From'] = $message->get_from()->to_header();
        }

        if ($message->recipients_to() !== []) {
            $headers['To'] = implode(', ', array_map(
                static function (Address $a): string { return $a->to_header(); },
                $message->recipients_to()
            ));
        }

        if ($message->recipients_cc() !== []) {
            $headers['Cc'] = implode(', ', array_map(
                static function (Address $a): string { return $a->to_header(); },
                $message->recipients_cc()
            ));
        }

        // Bcc is deliberately absent: it belongs to the envelope, and writing it
        // into a header is how "blind" copies stop being blind.

        if ($message->get_reply_to() !== null) {
            $headers['Reply-To'] = $message->get_reply_to()->to_header();
        }

        $headers['Subject'] = self::header_value($message->get_subject());

        if ($message_id_c !== null) {
            $headers['Message-ID'] = '<' . $message_id_c . '>';
        }

        foreach ($message->get_headers() as $name => $value) {
            $headers[$name] = self::header_value($value);
        }

        return $headers;
    }

    /**
     * Encode a header value, and only when it needs it.
     *
     * Pure ASCII goes through untouched, which keeps a log or a `tcpdump`
     * readable; anything else is RFC 2047 base64.
     */
    public static function header_value(string $value): string
    {
        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new MailException('A newline in a header value is injection; refused.');
        }

        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function body(string $text, string $html, ?string &$boundary): string
    {
        $boundary = null;

        if ($text !== '' && $html !== '') {
            $boundary = 'ix-' . bin2hex(random_bytes(12));

            return implode(self::CRLF, [
                'This is a message in MIME format.',
                '',
                '--' . $boundary,
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: quoted-printable',
                '',
                self::encode_body($text),
                '--' . $boundary,
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: quoted-printable',
                '',
                self::encode_body($html),
                '--' . $boundary . '--',
                '',
            ]);
        }

        return self::encode_body($html !== '' ? $html : $text);
    }

    /**
     * Quoted-printable, with CRLF line endings.
     */
    private static function encode_body(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        return str_replace("\n", self::CRLF, quoted_printable_encode($body));
    }

    /**
     * Protect the DATA terminator: a line that is just `.` becomes `..`.
     *
     * Applied by the transport rather than at build time, because the raw
     * message is also what gets logged and shown, and doubling dots there would
     * be a lie about what was sent.
     */
    public static function dot_stuff(string $raw): string
    {
        return preg_replace('/^\./m', '..', $raw) ?? $raw;
    }

    /**
     * A Message-ID that does not leak the machine's hostname.
     */
    public static function message_id(string $domain): string
    {
        return bin2hex(random_bytes(16)) . '@' . ($domain !== '' ? $domain : 'localhost');
    }
}
