<?php

/**
 * EMail - Mail Runtime Bridge
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Exposes the legacy mail runtime through a namespaced service for controllers and other mail services.
 */

namespace App\modules\email\services;

require_once dirname(__DIR__) . '/inc/class.mail_msg.inc.php';

final class MailMessage extends \mail_msg
{
}