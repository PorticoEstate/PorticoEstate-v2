<?php

/**
 * phpGroupWare - SMS: A SMS Gateway.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package admin
 * @subpackage config
 * @version $Id: class.soconfig.inc.php 3613 2009-09-18 16:19:49Z sigurd $
 */

use App\modules\phpgwapi\services\ConfigLocation;

/**
 * Description
 * @package admin
 */

class admin_soconfig extends ConfigLocation
{
	public function __construct($location_id = 0)
	{
		parent::__construct($location_id);
	}
}
