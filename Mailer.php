<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - Mailer
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

use Throwable;

/**
 * Sends a message, and records the attempt before making it.
 *
 *     $result = $mailer->send(
 *         (new Message())
 *             ->to($customer_email, $customer_name)
 *             ->subject($t->get('mail.invoice.subject'))
 *             ->view('mail/invoice', ['invoice' => $invoice])
 *             ->context('invoice ' . $invoice_id)
 *     );
 *
 *     if (!$result->is_sent()) {
 *         // error_code(): transport | auth | recipient_rejected | timeout | throttled
 *         // log_id() is the row already written, with the server's own reply
 *     }
 *
 * ## The one rule
 *
 * **The log row is written before the transport is touched.** Everything else
 * here follows from that.
 *
 * The history: every mailer in this application caught its own SMTP failure and
 * reported it with `error_log()`, which on that server wrote nowhere — php-fpm
 * had no `error_log` for the pool and `catch_workers_output` was off. The
 * interface said "sent" either way, and for months nobody could answer whether
 * a single message had left the building.
 *
 * Logging *after* a successful send would have the same defect in a smaller
 * form: a process killed mid-send still leaves nothing. So the row goes in as
 * `queued` first, and a row still `queued` an hour later is the single most
 * informative thing in the table — `ix mail:status --stranded` exists to ask
 * exactly that.
 *
 * ## And the corollary
 *
 * `send()` **never throws** for a delivery problem. It returns a `Result`. A
 * mailer that throws is a mailer whose callers write `try { } catch { }`, and
 * that catch block is where the last decade of silent mail failures lived.
 * `MailException` is still raised for a message that cannot be *built* — no
 * recipient, a newline in a subject — because that is a programming error and
 * burying it in the mail log helps nobody.
 */
final class Mailer
{
    private Transport $transport;
    private MailLog $log;
    private ?BodyRenderer $renderer;
    private ?Address $default_from;
    private string $message_id_domain;

    public function __construct(
        Transport $transport,
        MailLog $log,
        ?BodyRenderer $renderer = null,
        ?string $from_email = null,
        string $from_name = '',
        string $message_id_domain = ''
    ) {
        $this->transport         = $transport;
        $this->log               = $log;
        $this->renderer          = $renderer;
        $this->default_from      = $from_email === null ? null : new Address($from_email, $from_name);
        $this->message_id_domain = $message_id_domain !== ''
            ? $message_id_domain
            : ($from_email !== null ? (string) substr(strrchr($from_email, '@') ?: '@localhost', 1) : 'localhost');
    }

    public function send(Message $message): Result
    {
        // Built first, and outside the log: a message that cannot be assembled
        // is a bug in the caller, not a delivery failure, and recording it as
        // one hides it among real ones.
        if ($message->get_from() === null && $this->default_from !== null) {
            $message = $message->from($this->default_from->email(), $this->default_from->name());
        }

        $message->assert_sendable();

        [$text, $html] = $this->bodies($message);

        $raw = Mime::build($message, $text, $html, Mime::message_id($this->message_id_domain));

        // --- the rule -------------------------------------------------------
        $log_id = $this->log->open(
            $message->primary_recipient(),
            $message->get_subject(),
            $message->get_context(),
            $this->transport->describe()
        );

        try {
            $this->transport->send($message, $raw);
        } catch (TransportFailure $e) {
            $this->log->settle(
                $log_id,
                LogEntry::FAILED,
                $e->error_code(),
                $e->server_reply() !== '' ? $e->server_reply() : $e->getMessage()
            );

            return Result::failed($log_id, $e->error_code(), $e->getMessage());
        } catch (Throwable $e) {
            // A transport that throws something else is still a failure, and
            // must not escape past the log row it already has.
            $this->log->settle($log_id, LogEntry::FAILED, 'transport', get_class($e) . ': ' . $e->getMessage());

            return Result::failed($log_id, 'transport', $e->getMessage());
        }

        $this->log->settle($log_id, LogEntry::SENT);

        return Result::sent($log_id);
    }

    public function log(): MailLog
    {
        return $this->log;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    /**
     * @return array{0: string, 1: string} [text, html]
     */
    private function bodies(Message $message): array
    {
        $text = $message->get_text();
        $html = $message->get_html();
        $view = $message->get_view();

        if ($view !== null) {
            if ($this->renderer === null) {
                throw new MailException(
                    "This message renders \"{$view}\", but the mailer was built without a "
                    . BodyRenderer::class . '.'
                );
            }

            $html = $this->renderer->render($view, $message->get_view_data());
        }

        // A plain-text alternative is generated rather than required: a message
        // with only HTML scores worse with every spam filter there is, and
        // asking every caller to write the body twice guarantees the second one
        // rots.
        if ($html !== '' && $text === '') {
            $text = self::to_text($html);
        }

        return [$text, $html];
    }

    /**
     * A readable plain-text rendering of an HTML body.
     *
     * Not a converter — it keeps link targets, because a text-only reader
     * receiving "click here" with no URL has received nothing.
     *
     * The target goes in parentheses rather than angle brackets, which is what
     * a mail client would use: `strip_tags()` runs afterwards and treats
     * `<https://…>` as a tag, so the angle-bracket form silently deletes the
     * very URL this method exists to preserve.
     */
    public static function to_text(string $html): string
    {
        $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;

        $text = preg_replace('~<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>~is', '$3 ( $2 )', $text) ?? $text;
        $text = preg_replace('~<(br|/p|/div|/tr|/h[1-6])\b[^>]*>~i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~[ \t]+~', ' ', $text) ?? $text;
        $text = preg_replace('~\n\s*\n\s*\n+~', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
