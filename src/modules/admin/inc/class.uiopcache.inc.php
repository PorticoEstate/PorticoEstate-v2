<?php

/**
 * PorticoEstate
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2025 Free Software Foundation, Inc. http://www.fsf.org/
 * This file is part of PorticoEstate.
 *
 * phpGroupWare is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * phpGroupWare is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with phpGroupWare; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package phpgwapi
 * @subpackage Admin
 * @version $Id$
 */

use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;

class admin_uiopcache
{

	public $public_functions = array(
		'index' => True,
	);

	private $phpgwapi_common;

	public function __construct()
	{
		Settings::getInstance()->update('flags', ['menu_selection' => 'admin::admin::opcache_monitor']);

		$acl = Acl::getInstance();

		$this->phpgwapi_common = new \phpgwapi_common();

		$is_admin	 = $acl->check('run', Acl::READ, 'admin');

		if (!$is_admin)
		{
			phpgw::no_access();
		}
	}

	public function index()
	{
		$this->phpgwapi_common->phpgw_header(true);

		// Include the OPcache GUI script
		$vendordir = dirname(PHPGW_SERVER_ROOT, 2) . '/vendor';

		require_once $vendordir . '/amnuts/opcache-gui/index.php';
	}
}
