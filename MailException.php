<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - Exception
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

use RuntimeException;

/**
 * The message cannot be built or the mailer cannot be configured: an address
 * that is not an address, a header carrying a newline, a template that does not
 * exist.
 *
 * **Not** what a delivery failure raises. A refused recipient or an unreachable
 * server is an ordinary outcome — it happens constantly and the caller must be
 * able to react — so `send()` returns a `Result` with an `error_code()`. Throwing
 * there is how mail failures end up in a `catch` that logs nowhere, which is
 * the exact history this library was written against.
 */
final class MailException extends RuntimeException
{
}
