<?php

/**
 * phpGroupWare - rental: a part of a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2011,2012 Free Software Foundation, Inc. http://www.fsf.org/
 * This file is part of phpGroupWare.
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
 * @internal Development of this application was funded by http://www.bergen.kommune.no/
 * @package rental
 * @subpackage moveout
 * @version $Id: class.uitts.inc.php 14728 2016-02-11 22:28:46Z sigurdne $
 */

use App\modules\phpgwapi\services\Settings;

phpgw::import_class('rental.uicontract');

class mobilefrontend_uicontract extends rental_uicontract
{

	public function __construct()
	{
		parent::__construct();
		Settings::getInstance()->update('flags', ['nonavbar' => true]);
	}
}
