<?php

/**
 * EMail - POP Retrieval Errors
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Identifies POP connection and protocol failures that synchronization can distinguish from local storage errors.
 */

namespace App\modules\email\services;

final class PopMailboxSourceException extends \RuntimeException
{
}