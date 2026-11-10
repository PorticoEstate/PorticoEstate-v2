<?php

/**
 * EMail - Mail Configuration Errors
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Identifies mailbox configuration failures that callers can report as actionable account errors.
 */

namespace App\modules\email\services;

final class EmailMailConfigurationException extends \RuntimeException
{
}