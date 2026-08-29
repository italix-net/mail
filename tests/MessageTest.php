<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail — Message, Address and MIME
 *
 * Header injection is the security property here, and it is invisible in a
 * working send: a name containing CRLF produces a perfectly deliverable message
 * with an extra Bcc nobody asked for. Every string that reaches a header is
 * therefore attacked here rather than merely exercised.
 *
 * Run: php src/Libs/Italix/Mail/tests/MessageTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    fwrite(STDERR, "Could not find an autoloader. Run composer install.\n");
    exit(2);
})();

use Italix\Mail\Address;
use Italix\Mail\MailException;
use Italix\Mail\Mailer;
use Italix\Mail\Message;
use Italix\Mail\Mime;
use Italix\Mail\SmtpTransport;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Mail — Message, Address, MIME');

$throws = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (MailException $e) {
        return [true, $e->getMessage()];
    }
};

// -----------------------------------------------------------------------------
section('addresses');

$plain = new Address('a@b.it');
test('a bare address has no name', $plain->name() === '' && $plain->email() === 'a@b.it');
test('…and renders bare', $plain->to_header() === 'a@b.it');

$named = new Address('a@b.it', 'Mario Rossi');
test('a name is quoted', $named->to_header() === '"Mario Rossi" <a@b.it>', $named->to_header());
test('whitespace is trimmed', (new Address('  a@b.it  ', ' Mario '))->to_header() === '"Mario" <a@b.it>');

$accented = new Address('a@b.it', 'Società Röder');
test('a non-ASCII name is RFC 2047 encoded',
    strpos($accented->to_header(), '=?UTF-8?B?') === 0, $accented->to_header());
test('…and decodes back', base64_decode(
    (string) preg_replace('~^=\?UTF-8\?B\?(.*)\?=.*$~', '$1', $accented->to_header())
) === 'Società Röder');

$quoting = new Address('a@b.it', 'Rossi "il Grande"');
test('a quote inside a name is escaped', strpos($quoting->to_header(), '\\"') !== false, $quoting->to_header());

foreach (['', 'not-an-address', 'a@', '@b.it', 'a b@c.it'] as $bad) {
    [$threw] = $throws(static function () use ($bad): void { new Address($bad); });
    test('refuses ' . var_export($bad, true), $threw);
}

// -----------------------------------------------------------------------------
section('header injection is refused, not escaped');

$attacks = [
    "a@b.it\r\nBcc: attacker@evil.test",
    "a@b.it\nBcc: attacker@evil.test",
    "a@b.it\r\n",
    "a@b.it\x00",
];

foreach ($attacks as $i => $attack) {
    [$threw] = $throws(static function () use ($attack): void { new Address($attack); });
    test("an address carrying a newline is refused (#{$i})", $threw);
}

foreach ($attacks as $i => $attack) {
    [$threw, $message] = $throws(static function () use ($attack): void { new Address('a@b.it', $attack); });
    test("a NAME carrying a newline is refused (#{$i})", $threw);
    test("…saying it is injection (#{$i})", strpos($message, 'injection') !== false, $message);
}

[$threw] = $throws(static function (): void {
    (new Message())->subject("Hello\r\nBcc: attacker@evil.test");
});
test('a subject carrying a newline is refused', $threw);

[$threw] = $throws(static function (): void {
    (new Message())->header('X-Thing', "value\r\nBcc: attacker@evil.test");
});
test('a custom header value carrying a newline is refused', $threw);

[$threw] = $throws(static function (): void { (new Message())->header('X Thing: evil', 'v'); });
test('a header NAME that is not a token is refused', $threw);

[$threw] = $throws(static function (): void { Mime::header_value("a\r\nb"); });
test('Mime::header_value refuses one too', $threw);

// -----------------------------------------------------------------------------
section('the message is immutable');

$base    = (new Message())->to('a@b.it')->subject('Base');
$derived = $base->to('c@d.it')->subject('Derived');

test('the original keeps one recipient', count($base->recipients_to()) === 1);
test('the derived has two', count($derived->recipients_to()) === 2);
test('the original keeps its subject', $base->get_subject() === 'Base');
test('the derived has its own', $derived->get_subject() === 'Derived');

// -----------------------------------------------------------------------------
section('a message that cannot be delivered is refused before anything else');

[$threw, $message] = $throws(static function (): void {
    (new Message())->subject('x')->text('y')->assert_sendable();
});
test('no recipient is refused', $threw);
test('…and says so', strpos($message, 'no recipient') !== false, $message);

[$threw] = $throws(static function (): void {
    (new Message())->to('a@b.it')->text('y')->assert_sendable();
});
test('no subject is refused', $threw);

[$threw] = $throws(static function (): void {
    (new Message())->to('a@b.it')->subject('x')->assert_sendable();
});
test('no body is refused', $threw);

test('bcc alone is a recipient',
    (new Message())->bcc('a@b.it')->subject('x')->text('y')->envelope_recipients() !== []);

// -----------------------------------------------------------------------------
section('the envelope and the headers disagree about bcc, on purpose');

$message = (new Message())
    ->from('from@x.it', 'Sender')
    ->to('to@x.it')
    ->cc('cc@x.it')
    ->bcc('bcc@x.it')
    ->subject('Subject')
    ->text('Body');

test('the envelope includes everyone', count($message->envelope_recipients()) === 3);

$raw = Mime::build($message, 'Body', '');

test('To is in the headers', strpos($raw, 'To: to@x.it') !== false);
test('Cc is in the headers', strpos($raw, 'Cc: cc@x.it') !== false);
test('Bcc is NOT in the headers', strpos($raw, 'bcc@x.it') === false,
    'writing it there is how blind copies stop being blind');

// -----------------------------------------------------------------------------
section('the MIME body');

test('headers end with a blank line', strpos($raw, "\r\n\r\n") !== false);
test('line endings are CRLF', substr_count($raw, "\r\n") > 0 && !preg_match('/(?<!\r)\n/', $raw),
    'SMTP is a line protocol; a bare \n is not a line ending');
test('a text-only message is text/plain', strpos($raw, 'Content-Type: text/plain; charset=UTF-8') !== false);
test('Subject is present', strpos($raw, 'Subject: Subject') !== false);
test('MIME-Version is present', strpos($raw, 'MIME-Version: 1.0') !== false);
test('a Message-ID can be set', strpos(Mime::build($message, 'b', '', 'abc@x.it'), 'Message-ID: <abc@x.it>') !== false);

$html_only = Mime::build($message, '', '<p>Hi</p>');
test('an html-only message is text/html', strpos($html_only, 'Content-Type: text/html') !== false);

$both = Mime::build($message, 'Plain', '<p>Rich</p>');
test('both bodies produce multipart/alternative', strpos($both, 'multipart/alternative') !== false);
test('…with a boundary', preg_match('~boundary="(ix-[0-9a-f]+)"~', $both, $m) === 1);
test('…that appears three times', substr_count($both, '--' . $m[1]) === 3, (string) substr_count($both, '--' . $m[1]));
test('…and closes', strpos($both, '--' . $m[1] . '--') !== false);
test('the plain part comes first', strpos($both, 'text/plain') < strpos($both, 'text/html'),
    'readers pick the last part they understand; plain must not win');

$accented_body = Mime::build($message->subject('Città'), "Perché\nnò", '');
test('a non-ASCII subject is encoded', strpos($accented_body, '=?UTF-8?B?') !== false);
test('a non-ASCII body is quoted-printable', strpos($accented_body, 'Perch=C3=A9') !== false, $accented_body);

// -----------------------------------------------------------------------------
section('the SMTP dot, and the code mapping');

test('a lone dot line is stuffed', Mime::dot_stuff("a\r\n.\r\nb") === "a\r\n..\r\nb",
    'in DATA a bare . ends the message; unescaped it truncates the mail');
test('a leading dot is stuffed', Mime::dot_stuff(".hidden") === "..hidden");
test('a dot mid-line is untouched', Mime::dot_stuff("a.b") === "a.b");
test('the raw message keeps its single dots', strpos(Mime::build($message, "a\n.\nb", ''), "\r\n..\r\n") === false,
    'stuffing happens in the transport so the logged message is what was written');

foreach ([
    535 => 'auth',
    530 => 'auth',
    550 => 'recipient_rejected',
    553 => 'recipient_rejected',
    421 => 'throttled',
    451 => 'throttled',
    500 => 'transport',
    999 => 'transport',
] as $smtp => $expected) {
    test("SMTP {$smtp} maps to {$expected}", SmtpTransport::error_code_for($smtp) === $expected);
}

// -----------------------------------------------------------------------------
section('the generated plain-text alternative keeps the links');

$html = '<p>Ciao <b>Mario</b></p><p><a href="https://x.test/confirm?t=1">Conferma qui</a></p>'
      . '<style>p{color:red}</style>';
$text = Mailer::to_text($html);

test('tags are gone', strpos($text, '<p>') === false);
test('style content is gone', strpos($text, 'color:red') === false);
test('the text survives', strpos($text, 'Ciao Mario') !== false, $text);
test('the link TARGET survives', strpos($text, 'https://x.test/confirm?t=1') !== false, $text);
test('…next to its label', strpos($text, 'Conferma qui ( https://x.test/confirm?t=1 )') !== false, $text);
test('…in parentheses, not angle brackets', strpos($text, '<https://') === false,
    'strip_tags() runs afterwards and would eat an angle-bracketed URL');
test('entities are decoded', strpos(Mailer::to_text('<p>a&amp;b</p>'), 'a&b') !== false);

exit(summary());
