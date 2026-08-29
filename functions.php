<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - factory functions
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

use PDO;

if (!function_exists(__NAMESPACE__ . '\message')) {

    function message(): Message
    {
        return new Message();
    }

    function mailer(
        Transport $transport,
        MailLog $log,
        ?BodyRenderer $renderer = null,
        ?string $from_email = null,
        string $from_name = ''
    ): Mailer {
        return new Mailer($transport, $log, $renderer, $from_email, $from_name);
    }

    function pdo_mail_log(PDO $pdo, string $table = PdoMailLog::DEFAULT_TABLE): PdoMailLog
    {
        return new PdoMailLog($pdo, $table);
    }

    function null_transport(): NullTransport
    {
        return new NullTransport();
    }
}
