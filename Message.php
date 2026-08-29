<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - Message
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

/**
 * What to send, and to whom. An immutable descriptor — it does not know how
 * mail leaves the building.
 *
 *     $message = (new Message())
 *         ->to('a@b.it', 'Mario Rossi')
 *         ->subject($t->get('mail.invoice.subject'))
 *         ->view('mail/invoice', ['invoice' => $invoice])
 *         ->context('invoice ' . $invoice_id);
 *
 * `context()` is the field the log is worth having for. "Delivery failed" tells
 * you nothing at 9am; "invoice 4471, party 147" tells you which customer to
 * call.
 *
 * Every builder returns a new instance, so a base message can be prepared once
 * and specialised per recipient without the first one changing underneath.
 */
final class Message
{
    /** @var Address[] */
    private array $to = [];

    /** @var Address[] */
    private array $cc = [];

    /** @var Address[] */
    private array $bcc = [];

    private ?Address $from = null;
    private ?Address $reply_to = null;

    private string $subject = '';
    private string $text = '';
    private string $html = '';

    private ?string $view_path = null;

    /** @var array<string, mixed> */
    private array $view_data = [];

    private string $context_c = '';

    /** @var array<string, string> */
    private array $headers = [];

    // -------------------------------------------------------------------------
    // Recipients
    // -------------------------------------------------------------------------

    public function to(string $email, string $name = ''): self
    {
        $clone       = clone $this;
        $clone->to[] = new Address($email, $name);

        return $clone;
    }

    public function cc(string $email, string $name = ''): self
    {
        $clone       = clone $this;
        $clone->cc[] = new Address($email, $name);

        return $clone;
    }

    public function bcc(string $email, string $name = ''): self
    {
        $clone        = clone $this;
        $clone->bcc[] = new Address($email, $name);

        return $clone;
    }

    public function from(string $email, string $name = ''): self
    {
        $clone       = clone $this;
        $clone->from = new Address($email, $name);

        return $clone;
    }

    public function reply_to(string $email, string $name = ''): self
    {
        $clone           = clone $this;
        $clone->reply_to = new Address($email, $name);

        return $clone;
    }

    // -------------------------------------------------------------------------
    // Content
    // -------------------------------------------------------------------------

    public function subject(string $subject): self
    {
        if (preg_match('/[\r\n\x00]/', $subject) === 1) {
            throw new MailException(
                'A newline in a subject is header injection; refused rather than escaped.'
            );
        }

        $clone          = clone $this;
        $clone->subject = trim($subject);

        return $clone;
    }

    public function text(string $body): self
    {
        $clone       = clone $this;
        $clone->text = $body;

        return $clone;
    }

    public function html(string $body): self
    {
        $clone       = clone $this;
        $clone->html = $body;

        return $clone;
    }

    /**
     * Render the body from a template.
     *
     * The template is resolved by whatever `BodyRenderer` the mailer was given
     * — in an Italix application that is `ViewRenderer`, so mail templates get
     * the same escaping guarantee, the same partials and the same theme
     * fallback as every other page.
     *
     * @param array<string, mixed> $data
     */
    public function view(string $path, array $data = []): self
    {
        $clone            = clone $this;
        $clone->view_path = $path;
        $clone->view_data = $data;

        return $clone;
    }

    /**
     * Free-text note recorded with the log row: what this message was *about*.
     */
    public function context(string $context_c): self
    {
        $clone            = clone $this;
        $clone->context_c = $context_c;

        return $clone;
    }

    public function header(string $name, string $value): self
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $name) !== 1) {
            throw new MailException("Refusing \"{$name}\" as a header name.");
        }

        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new MailException("A newline in the \"{$name}\" header is injection; refused.");
        }

        $clone                 = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /** @return Address[] */
    public function recipients_to(): array { return $this->to; }

    /** @return Address[] */
    public function recipients_cc(): array { return $this->cc; }

    /** @return Address[] */
    public function recipients_bcc(): array { return $this->bcc; }

    /**
     * Everyone the transport must deliver to — the envelope, which includes bcc
     * even though no header mentions them.
     *
     * @return Address[]
     */
    public function envelope_recipients(): array
    {
        return array_merge($this->to, $this->cc, $this->bcc);
    }

    public function get_from(): ?Address { return $this->from; }
    public function get_reply_to(): ?Address { return $this->reply_to; }
    public function get_subject(): string { return $this->subject; }
    public function get_text(): string { return $this->text; }
    public function get_html(): string { return $this->html; }
    public function get_view(): ?string { return $this->view_path; }
    public function get_context(): string { return $this->context_c; }

    /** @return array<string, mixed> */
    public function get_view_data(): array { return $this->view_data; }

    /** @return array<string, string> */
    public function get_headers(): array { return $this->headers; }

    /**
     * The first recipient, for the log's "who" column.
     */
    public function primary_recipient(): string
    {
        return $this->to === [] ? '' : $this->to[0]->email();
    }

    /**
     * Refuse a message that cannot be delivered, before anything is logged or
     * connected. A missing recipient is a programming error, not a delivery
     * failure, and treating it as the latter buries it in the mail log.
     */
    public function assert_sendable(): void
    {
        if ($this->to === [] && $this->cc === [] && $this->bcc === []) {
            throw new MailException('This message has no recipient.');
        }

        if ($this->subject === '') {
            throw new MailException('This message has no subject.');
        }

        if ($this->text === '' && $this->html === '' && $this->view_path === null) {
            throw new MailException('This message has no body: set text(), html() or view().');
        }
    }
}
