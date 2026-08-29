<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail — the Mailer, and the ordering that is the whole point
 *
 * This library exists because delivery failures on a real system were silent
 * for months: every mailer caught its own SMTP error and reported it with
 * `error_log()`, which wrote nowhere. The interface said "sent" either way.
 *
 * So the assertions that matter are not "a mail was sent". They are: the log
 * row exists *before* the transport is touched, a failure updates that row, a
 * transport that explodes cannot escape past it, and `send()` never throws for
 * a delivery problem — because a throwing mailer is a mailer whose callers
 * write the catch block this history lived in.
 *
 * Run: php src/Libs/Italix/Mail/tests/MailerTest.php
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

use Italix\Mail\BodyRenderer;
use Italix\Mail\LogEntry;
use Italix\Mail\MailException;
use Italix\Mail\MailLog;
use Italix\Mail\Mailer;
use Italix\Mail\Message;
use Italix\Mail\NullTransport;
use Italix\Mail\PdoMailLog;
use Italix\Mail\Transport;
use Italix\Mail\TransportFailure;
use Italix\Testing\Database;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Mail — Mailer');

// -----------------------------------------------------------------------------
// Fixtures

/** Records every call, in order, so the ordering can be asserted. */
final class SpyLog implements MailLog
{
    /** @var string[] */
    public array $calls = [];

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    private int $next_id = 1;

    public function open(string $to_email, string $subject, string $context_c, string $transport_c): int
    {
        $id = $this->next_id++;

        $this->calls[]   = "open:{$id}";
        $this->rows[$id] = [
            'state_c'     => LogEntry::QUEUED,
            'to_email'    => $to_email,
            'subject'     => $subject,
            'context_c'   => $context_c,
            'transport_c' => $transport_c,
            'error_c'     => null,
            'detail'      => null,
        ];

        return $id;
    }

    public function settle(int $log_id, string $state_c, ?string $error_c = null, ?string $detail = null): void
    {
        $this->calls[] = "settle:{$log_id}:{$state_c}";

        $this->rows[$log_id]['state_c'] = $state_c;
        $this->rows[$log_id]['error_c'] = $error_c;
        $this->rows[$log_id]['detail']  = $detail;
    }

    public function recent(int $since_t, ?string $state_c = null, int $limit_n = 100): array { return []; }
    public function summary(int $since_t): array { return []; }
    public function stranded(int $before_t, int $limit_n = 50): array { return []; }

    public function reset(): void
    {
        $this->calls = [];
        $this->rows  = [];
    }
}

/** Reports what the log looked like at the moment it was asked to send. */
final class ObservingTransport implements Transport
{
    public array $log_state_at_send = [];
    private SpyLog $log;

    public function __construct(SpyLog $log) { $this->log = $log; }

    public function send(Message $message, string $raw): void
    {
        $this->log_state_at_send = $this->log->calls;
    }

    public function describe(): string { return 'observing'; }
}

final class ExplodingTransport implements Transport
{
    public function send(Message $message, string $raw): void
    {
        throw new ErrorException('something entirely unexpected');
    }

    public function describe(): string { return 'exploding'; }
}

final class StaticRenderer implements BodyRenderer
{
    public array $seen = [];

    public function render(string $path, array $data): string
    {
        $this->seen[] = [$path, $data];

        return '<p>Ciao ' . ($data['name'] ?? '') . '</p>';
    }
}

$base = static function (): Message {
    return (new Message())
        ->to('customer@x.test', 'Mario Rossi')
        ->subject('Conferma')
        ->text('Corpo')
        ->context('invoice 4471');
};

// -----------------------------------------------------------------------------
section('the log row is written BEFORE the transport is touched');

$log       = new SpyLog();
$observing = new ObservingTransport($log);
$mailer    = new Mailer($observing, $log, null, 'noreply@x.test', 'X');

$result = $mailer->send($base());

test('the transport saw a log row already open', $observing->log_state_at_send === ['open:1'],
    json_encode($observing->log_state_at_send)
    . ' — a row written afterwards leaves nothing when the process dies mid-send');
test('the calls are open then settle', $log->calls === ['open:1', 'settle:1:sent'],
    json_encode($log->calls));
test('the row ends sent', $log->rows[1]['state_c'] === LogEntry::SENT);
test('the result says sent', $result->is_sent());
test('…with no error code', $result->error_code() === '');
test('…and names the row it wrote', $result->log_id() === 1);

// -----------------------------------------------------------------------------
section('what the row records');

test('the recipient', $log->rows[1]['to_email'] === 'customer@x.test');
test('the subject', $log->rows[1]['subject'] === 'Conferma');
test('the context — the field worth having',
    $log->rows[1]['context_c'] === 'invoice 4471',
    '"delivery failed" tells you nothing at 9am; "invoice 4471" tells you who to call');
test('which transport was used', $log->rows[1]['transport_c'] === 'observing');

// -----------------------------------------------------------------------------
section('a failure updates the row it already has, and never throws');

foreach ([
    'auth'               => false,
    'recipient_rejected' => false,
    'transport'          => true,
    'timeout'            => true,
    'throttled'          => true,
] as $error_c => $retryable_flag) {
    $log    = new SpyLog();
    $mailer = new Mailer((new NullTransport())->fail_with($error_c, "550 {$error_c}"), $log, null, 'noreply@x.test');

    $result = $mailer->send($base());

    test("a {$error_c} failure returns rather than throwing", !$result->is_sent());
    test("…carrying error_c = {$error_c}", $result->error_code() === $error_c);
    test("…and the row is failed", $log->rows[1]['state_c'] === LogEntry::FAILED);
    test("…recording the code", $log->rows[1]['error_c'] === $error_c);
    test("…and the server's own words", $log->rows[1]['detail'] === "550 {$error_c}",
        'the useful half of an SMTP diagnostic is the part nobody anticipated');
    test(
        "{$error_c} is " . ($retryable_flag ? 'retryable' : 'permanent'),
        $result->is_retryable() === $retryable_flag,
        'a queue that retries a wrong password turns one misconfiguration into a thousand log lines'
    );
}

// -----------------------------------------------------------------------------
section('a transport that explodes cannot escape past its log row');

$log    = new SpyLog();
$mailer = new Mailer(new ExplodingTransport(), $log, null, 'noreply@x.test');

$threw  = false;
$result = null;

try {
    $result = $mailer->send($base());
} catch (Throwable $e) {
    $threw = true;
}

test('an unexpected exception does not escape', !$threw,
    'it would leave a queued row and a 500, which is the old behaviour exactly');
test('…it becomes a failed result', $result !== null && !$result->is_sent());
test('…with error_c = transport', $result->error_code() === 'transport');
test('…and the row is settled, not left queued', $log->rows[1]['state_c'] === LogEntry::FAILED);
test('…naming the exception class', strpos((string) $log->rows[1]['detail'], 'ErrorException') !== false,
    (string) $log->rows[1]['detail']);

// -----------------------------------------------------------------------------
section('a message that cannot be BUILT is a bug, and is not logged as a failure');

$log      = new SpyLog();
$mailer   = new Mailer(new NullTransport(), $log, null, 'noreply@x.test');
$refusals = [
    'no recipient' => (new Message())->subject('x')->text('y'),
    'no subject'   => (new Message())->to('a@b.it')->text('y'),
    'no body'      => (new Message())->to('a@b.it')->subject('x'),
];

foreach ($refusals as $label => $message) {
    $threw = false;

    try {
        $mailer->send($message);
    } catch (MailException $e) {
        $threw = true;
    }

    test("{$label} throws rather than returning a Result", $threw);
}

test('…and wrote no log row', $log->calls === [],
    'burying a programming error among real delivery failures helps nobody');

// -----------------------------------------------------------------------------
section('bodies');

$log      = new SpyLog();
$renderer = new StaticRenderer();
$transport = new NullTransport();
$mailer   = new Mailer($transport, $log, $renderer, 'noreply@x.test', 'X');

$mailer->send($base()->text('')->view('mail/welcome', ['name' => 'Mario']));

test('the renderer was asked for the template', $renderer->seen[0][0] === 'mail/welcome');
test('…with the data', $renderer->seen[0][1] === ['name' => 'Mario']);
test('the html reached the wire', strpos($transport->last_raw(), 'Ciao Mario') !== false);
test('a plain-text alternative was generated', strpos($transport->last_raw(), 'multipart/alternative') !== false,
    'html-only scores worse with every spam filter there is');

$threw = false;
try {
    (new Mailer(new NullTransport(), new SpyLog(), null, 'noreply@x.test'))
        ->send($base()->text('')->view('mail/welcome'));
} catch (MailException $e) {
    $threw = strpos($e->getMessage(), BodyRenderer::class) !== false;
}
test('a view with no renderer says which interface is missing', $threw);

$transport->reset();
$mailer->send($base());
test('a From is applied from the mailer default', strpos($transport->last_raw(), 'From: "X" <noreply@x.test>') !== false,
    $transport->last_raw());

$transport->reset();
$mailer->send($base()->from('other@x.test', 'Other'));
test('…and an explicit From wins', strpos($transport->last_raw(), 'other@x.test') !== false);

// -----------------------------------------------------------------------------
section('the log, against a real database');

$pdo = Database::connect();

if ($pdo === null) {
    echo Database::skip_notice('the PdoMailLog half');
    exit(summary());
}

$table = 'ix_mail_log_test';
$pdo->exec("DROP TABLE IF EXISTS {$table}");

try {
    $store = new PdoMailLog($pdo, $table);
    $store->install();
    test('install() creates the table', (bool) $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn());
    $store->install();
    test('install() is idempotent', true);

    $mailer    = new Mailer(new NullTransport(), $store, null, 'noreply@x.test', 'X');
    $sent      = $mailer->send($base());
    $failed_id = $mailer->send($base()->to('other@x.test'))->log_id();

    test('a send writes a row', $sent->log_id() > 0);
    test('summary counts it', ($store->summary(time() - 60)[LogEntry::SENT] ?? 0) === 2);

    $mailer = new Mailer((new NullTransport())->fail_with('auth', '535 nope'), $store, null, 'noreply@x.test');
    $mailer->send($base());

    test('a failure is counted separately', ($store->summary(time() - 60)[LogEntry::FAILED] ?? 0) === 1);

    $recent = $store->recent(time() - 60, LogEntry::FAILED);
    test('recent() filters by state', count($recent) === 1);
    test('…and carries the error code', $recent[0]->error_c === 'auth');
    test('…and the server reply', $recent[0]->detail === '535 nope');
    test('…and the context', $recent[0]->context_c === 'invoice 4471');

    // The query the whole design exists to make possible.
    $store->open('stuck@x.test', 'Never settled', 'deal 99', 'null');
    $stranded = $store->stranded(time() + 3600);

    test('a row opened and never settled is stranded', count($stranded) === 1, (string) count($stranded));
    test('…and it is the right one', $stranded[0]->to_email === 'stuck@x.test');
    test('…still queued', $stranded[0]->state_c === LogEntry::QUEUED,
        'a row still queued an hour later is the most informative thing in the table');
    test('settled rows are not stranded', count($store->stranded(time() - 3600)) === 0);

    $threw = false;
    try { new PdoMailLog($pdo, 'bad name'); } catch (MailException $e) { $threw = true; }
    test('a table name that is not an identifier is refused', $threw);

} finally {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}

test('the scratch table was dropped', $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn() === false);

exit(summary());
