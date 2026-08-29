# Italix Mail

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MPL%202.0-blue.svg)](LICENSE)

Transactional mail that cannot fail silently.

Zero Composer dependencies; `ext-mbstring` for header encoding, `ext-openssl` for TLS,
`ext-pdo` for the shipped log.

```bash
php src/Libs/Italix/Mail/tests/MessageTest.php
php src/Libs/Italix/Mail/tests/MailerTest.php    # the log half needs ITALIX_TEST_DSN
```

---

## The rule

**The log row is written before the transport is touched.** Everything else here follows from it.

The history: in one application every mailer caught its own SMTP failure and reported it with
`error_log()` — which on that server wrote nowhere, because php-fpm had no `error_log` for the pool
and `catch_workers_output` was off. The interface said "sent" either way. For months nobody could
answer whether a single message had left the building.

Logging *after* a successful send has the same defect in a smaller form: a process killed mid-send
leaves nothing at all. So:

```
open()   → a queued row exists                  ← before the socket
send()   → the transport tries
settle() → sent, or failed with a machine code
```

A row still `queued` an hour later means a process died between writing it and hearing back. That is
the most informative thing in the table, and `ix mail:status --stranded` asks for exactly it.

---

## Sending

```php
$result = $mailer->send(
    (new Message())
        ->to($customer->email, $customer->name)
        ->subject($t->get('mail.invoice.subject'))
        ->view('mail/invoice', ['invoice' => $invoice])
        ->context('invoice ' . $invoice_id)
);

if (!$result->is_sent()) {
    // error_code(): transport | auth | recipient_rejected | timeout | throttled
    // log_id()  : the row already written, with the server's own reply
}
```

**`send()` never throws for a delivery problem.** It returns a `Result`. A mailer that throws is a
mailer whose callers write `try { } catch { }`, and that catch block is where the silent failures
lived. A message that cannot be *built* — no recipient, a newline in a subject — does throw
`MailException`, because that is a bug in the caller and burying it among real failures helps
nobody.

**`context()` is the field the log is worth having for.** "Delivery failed" tells you nothing at
9am; "invoice 4471, party 147" tells you which customer to call.

### Permanent versus temporary

`is_retryable()` answers whether trying again could work:

| error_c | retryable | why |
|---|---|---|
| `auth` | no | the credentials are wrong |
| `recipient_rejected` | no | the address does not exist |
| `transport`, `timeout`, `throttled` | yes | the server or the network |

A queue that retries a wrong password turns one misconfiguration into a thousand log lines.

---

## Bodies

`view()` renders through whatever `BodyRenderer` the mailer was given. In an Italix application
that is a two-line adapter over `ViewRenderer`, so mail templates get the same escaping guarantee,
the same partials and the same theme fallback as every page:

```php
final class ViewBodies implements BodyRenderer
{
    public function render(string $path, array $data): string
    {
        return $this->view->render($path, $data);
    }
}
```

A plain-text alternative is **generated** from the HTML rather than required. Asking every caller to
write the body twice guarantees the second one rots, and an HTML-only message scores worse with
every spam filter there is. Link targets survive the conversion in parentheses — a text-only reader
receiving "click here" with no URL has received nothing.

---

## Transports

`SmtpTransport` ships in core: EHLO, STARTTLS or implicit TLS, AUTH LOGIN, and SMTP reply codes
mapped to machine error codes. About 200 lines, which is why PHPMailer is not a framework
dependency — an application that needs attachments or DKIM writes a `Transport` over it and loses
nothing else.

`NullTransport` accepts everything, sends nothing, and remembers all of it. For tests, and for a
development environment where mail must not escape — it still goes through `Mailer`, so the log row
is written exactly as in production.

Credentials never reach the transcript: the two lines after `AUTH LOGIN` are masked.

---

## Three details that are invisible when wrong

**Header injection is refused, not escaped.** A name containing CRLF produces a perfectly
deliverable message with an extra `Bcc` nobody asked for, and no encoding makes that one header
again — the same position `Html::attribs()` takes on attribute names. Control characters are checked
**before** `trim()`, which would otherwise strip them and silently rewrite the caller's data.

**Bcc is in the envelope and not in the headers.** Writing it into a header is how blind copies stop
being blind.

**A lone `.` line is dot-stuffed by the transport.** In SMTP's DATA phase a bare dot ends the
message; unescaped it truncates the mail for the one customer whose text contains one. It happens in
the transport rather than at build time, so the logged message is what was actually written.

---

## From the CLI

```bash
ix mail:install                    # create ix_mail_log
ix mail:status                     # the last 24 hours
ix mail:status --since='-7 days'
ix mail:status --failed
ix mail:status --stranded          # attempts that never got a verdict
```

Exits 1 when anything failed or is stranded, so a monitor can watch it.

---

## Deliberately not

- **No mailable hierarchy, no markdown mail, no attachments.**
- **No queue coupling** — asynchronous sending is `$queue->push(new SendMail(...))`, a composition
  rather than a feature.
- **No DKIM, no pooling, no pipelining.** That is the difference between 200 lines and 2,000.
