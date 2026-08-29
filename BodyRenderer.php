<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Mail - BodyRenderer
 *
 * @package Italix\Mail
 */

declare(strict_types=1);

namespace Italix\Mail;

/**
 * Turns a template path and some data into an HTML body.
 *
 * Declared here rather than taking `Italix\Mvc\ViewRenderer` directly, because
 * house rule 13 keeps this library a leaf. The application supplies a two-line
 * adapter, and in exchange mail templates get the same escaping guarantee, the
 * same partials and the same theme fallback as every other page.
 *
 *     final class ViewBodies implements BodyRenderer
 *     {
 *         public function render(string $path, array $data): string
 *         {
 *             return $this->view->render($path, $data);
 *         }
 *     }
 */
interface BodyRenderer
{
    /**
     * @param  array<string, mixed> $data
     * @throws MailException when the template cannot be resolved
     */
    public function render(string $path, array $data): string;
}
