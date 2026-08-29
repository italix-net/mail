# Changelog — italix/mail

Format: [Keep a Changelog](https://keepachangelog.com/). Versioning policy: `VERSIONING.md` at the
project root.

## [2.0.0] — 2026-08-28

### Changed — BREAKING

`_c` on function/method names is retired in favor of spelling out what the value actually is —
see `src/Libs/Italix/CONVENTIONS.md`, "`_c` is for variables... only." `_c` stays on variables,
parameters and properties; only method names changed, no behavior:

- `Result::error_c()` → `error_code()`
- `TransportFailure::error_c()` → `error_code()`

(`Message::subject()` and `Address::name()` are unrelated pre-existing methods — an email subject
line and a display name — never touched by this or any other `_c` rename.)

## [1.0.1] — 2026-08-13

### Legal

- **Licensed under MPL-2.0**, applied 2026-08-13: the `license` field in `composer.json`, a `LICENSE`
  file, and the Exhibit A notice in every source file — MPL §1.4 defines "Covered Software" per file,
  so the per-file header is what makes the licence apply rather than decoration.

  This is a **first declaration, not a relicensing.** The package carried no licence at all before,
  which in most jurisdictions means all rights reserved: nothing had been granted, so nothing is
  taken away and no consumer's position gets worse. That is why it is recorded here rather than
  treated as a breaking change — unlike `italix/orm`, which went Apache-2.0 → MPL-2.0 and took a
  MAJOR because that direction does narrow what a consumer already had.

## [1.0.0] — 2026-08

First release. See `README.md` for usage.

### Added

- **`Message`** — immutable builder: `to`/`cc`/`bcc`/`from`/`reply_to`, `subject`, `text`/`html`/
  `view`, `header`, and `context()` for the log.
- **`Address`** — validates, refuses control characters, RFC 2047-encodes non-ASCII names.
- **`Mime`** — headers, folding, quoted-printable, `multipart/alternative`, dot-stuffing.
- **`Transport`** with **`SmtpTransport`** (EHLO, STARTTLS, AUTH LOGIN, injectable socket factory)
  and **`NullTransport`** (records, sends nothing, can be told to fail).
- **`MailLog`** with **`PdoMailLog`** — `open()`, `settle()`, `recent()`, `summary()`, `stranded()`.
- **`Mailer`** — the orchestrator. **`Result`** — `is_sent()`, `error_c()`, `detail()`, `log_id()`,
  `is_retryable()`.
- **`BodyRenderer`** — the seam for the application's view layer.

### The rule, and everything that follows from it

**The log row is written before the transport is touched.**

The history this replaces: every mailer in one application caught its own SMTP failure and reported
it with `error_log()`, which on that server wrote nowhere — php-fpm had no `error_log` for the pool
and `catch_workers_output` was off. The interface said "sent" either way, and for months nobody
could answer whether a single message had left the building.

Logging *after* a successful send has the same defect in a smaller form: a process killed mid-send
leaves nothing. So `open()` writes a `queued` row first and `settle()` moves it to `sent` or
`failed`. **A row still `queued` an hour later is the most informative thing in the table**, and
`stranded()` exists to ask exactly that.

The corollary: `send()` **never throws** for a delivery problem — it returns a `Result`. A mailer
that throws is a mailer whose callers write `try { } catch { }`, and that catch block is where the
silent failures lived. `MailException` is still raised for a message that cannot be *built*, because
that is a programming error and burying it among real delivery failures helps nobody.

### Three details that are invisible when wrong

**Header injection is refused, not escaped.** A name containing CRLF produces a perfectly
deliverable message with an extra `Bcc` nobody asked for, and no encoding makes that one header
again. Control characters are checked **before** `trim()`, which would otherwise strip them and
silently accept the input.

**Bcc is in the envelope and not in the headers.** Writing it into a header is how blind copies stop
being blind.

**A lone `.` line is stuffed by the transport, not at build time.** In SMTP's DATA phase a bare dot
ends the message; unescaped it truncates the mail, for the one customer whose text happens to
contain one. Stuffing at build time would mean the logged message was not what was written.

### Deliberately not

- **No mailable class hierarchy, no markdown mail, no attachments.** An application needing
  attachments writes a `Transport` over PHPMailer and loses nothing else.
- **No queue coupling.** Sending asynchronously is `$queue->push(new SendMail(...))` — a
  composition, not a feature.
- **No DKIM signing, no connection pooling, no pipelining.** These are why `SmtpTransport` is 200
  lines rather than 2,000, and why PHPMailer stays an application choice.
