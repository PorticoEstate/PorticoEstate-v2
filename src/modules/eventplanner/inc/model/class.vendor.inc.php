<?php

/**
 * phpGroupWare - eventplanner: a part of a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2016 Free Software Foundation, Inc. http://www.fsf.org/
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
 * @package eventplanner
 * @subpackage vendor
 * @version $Id: $
 */

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;

phpgw::import_class('eventplanner.bovendor');

include_class('phpgwapi', 'model', 'inc/model/');

class eventplanner_vendor extends phpgwapi_model
{

	const STATUS_REGISTERED = 1;
	const STATUS_PENDING = 2;
	const STATUS_REJECTED = 3;
	const STATUS_APPROVED = 4;
	const acl_location = '.vendor';

	protected
		$id,
		$owner_id,
		$active = 1,
		$category_id,
		$created,
		$modified,
		$secret,
		$name,
		$address_1,
		$address_2,
		$zip_code,
		$city,
		$organization_number,
		$contact_name,
		$contact_email,
		$contact_phone,
		$account_number,
		$description,
		$remark,
		$comments,
		$comment;

	public function __construct(int|null $id = null)
	{
		parent::__construct((int)$id);
		$this->field_of_responsibility_name = self::acl_location;
	}

	/**
	 * Implementing classes must return an instance of itself.
	 *
	 * @return the class instance.
	 */
	public static function get_instance()
	{
		return new eventplanner_vendor();
	}

	public static function get_status_list()
	{
		return array(
			self::STATUS_REGISTERED => lang('registered'),
			self::STATUS_PENDING	=> lang('pending'),
			self::STATUS_REJECTED => lang('rejected'),
			self::STATUS_APPROVED	=> lang('approved')
		);
	}

	public static function get_fields($debug = true)
	{
		$currentapp = Settings::getInstance()->get('flags')['currentapp'];

		$fields = array(
			'id' => array(
				'action' => ACL_READ,
				'type' => 'int',
				'label' => 'id',
				'sortable' => true,
				'formatter' => 'JqueryPortico.formatLink',
				'public'	=> true
			),
			'owner_id' => array(
				'action' => ACL_ADD,
				'type' => 'int',
				'required' => false
			),
			'category_id' => array(
				'action' =>  ACL_ADD | ACL_EDIT,
				'type' => 'int'
			),
			'created' => array(
				'action' => ACL_READ,
				'type' => 'date',
				'label' => 'created',
				'sortable' => true,
			),
			'modified' => array(
				'action' => ACL_READ | ACL_EDIT,
				'type' => 'date',
				'label' => 'modified',
				'sortable' => true,
			),
			'secret' => array(
				'action' => ACL_ADD,
				'type' => 'string',
				'label' => 'secret',
				'sortable' => false,
			),
			'name' => array(
				'action' => ACL_READ | ACL_ADD | ACL_EDIT,
				'type' => 'string',
				'label' => 'name',
				'required' => true,
				'query' => true,
				'public'	=> true
			),
			'address_1' => array(
				'action' => ACL_ADD | ACL_EDIT,
				'type' => 'string',
				'required' => true
			),
			'address_2' => array(
				'action' => ACL_ADD | ACL_EDIT,
				'type' => 'string',
				'required' => false
			),
			'zip_code' => array(
				'action' => ACL_ADD | ACL_EDIT,
				'type' => 'string',
				'required' => true
			),
			'city' => array(
				'action' => ACL_ADD | ACL_EDIT,
				'type' => 'string',
				'required' => true
			),
			'account_number' => array(
				'action' => ACL_ADD | ACL_EDIT,
				'type' => 'string',
				'required' => true
			),
			'description' => array(
				'action' => ACL_ADD | ACL_EDIT,
				'type' => 'string',
				'label' => 'description',
				'sortable' => false,
				'required' => true
			),
			'remark' => array(
				'action' => ACL_ADD | ACL_EDIT,
				'type' => 'string',
				'label' => 'description',
				'sortable' => false,
			),
			'contact_name' => array(
				'action' => ACL_ADD | ACL_EDIT,
				'type' => 'string',
				'required' => true,
				'query' => true,
				'label' => 'contact name',
			),
			'contact_email' => array(
				'action' => ACL_READ | ACL_ADD | ACL_EDIT,
				'type' => 'string',
				'required' => true,
				'query' => true,
				'sf_validator' => createObject('booking.sfValidatorEmail', array(), array('invalid' => '%field% is invalid')),
				'label' => 'contact email',
			),
			'contact_phone' => array(
				'action' => ACL_ADD | ACL_EDIT,
				'type' => 'string',
				'required' => true,
				'query' => true,
				'label' => 'contact phone',
			),
			'organization_number' => array(
				'action' => ACL_READ | ACL_ADD | ACL_EDIT,
				'type' => 'string',
				'required' => true,
				'query' => true,
				'sf_validator' => createObject('booking.sfValidatorNorwegianOrganizationNumber', array(), array('invalid' => '%field% is invalid')),
				'label' => 'organization number',
				'public'	=> true
			),
		);

		if ($currentapp == 'eventplanner')
		{
			$backend_fields = array(
				'active' => array(
					'action' => ACL_ADD | ACL_EDIT,
					'type' => 'int',
					'history'	=> true
				),
				'comments' => array(
					'action' => ACL_ADD | ACL_EDIT,
					'type' => 'string',
					'manytomany' => array(
						'input_field' => 'comment_input',
						'table' => 'eventplanner_vendor_comment',
						'key' => 'vendor_id',
						'column' => array('time', 'author', 'comment', 'type'),
						'order' => array('sort' => 'time', 'dir' => 'ASC')
					)
				),
				'comment' => array(
					'action' => ACL_ADD | ACL_EDIT,
					'type' => 'string',
					'related' => true,
				)
			);

			foreach ($backend_fields as $key => $field_info)
			{
				$fields[$key] = $field_info;
			}
		}


		if ($debug)
		{
			foreach ($fields as $field => $field_info)
			{
				if (!property_exists('eventplanner_vendor', $field))
				{
					Cache::message_set('$' . "{$field},", 'error');
				}
			}
		}
		return $fields;
	}

	/**
	 * Implement in subclasses to perform actions on entity before validation
	 */
	protected function preValidate(&$entity)
	{
		if (!empty($entity->comment))
		{
			$entity->comment_input = array(
				'time' => time(),
				'author' => $this->userSettings['fullname'],
				'comment' => $entity->comment,
				'type' => 'comment'
			);
		}

		$entity->modified = time();
		$entity->active = (int)$entity->active;

		if (!$entity->get_id())
		{
			$entity->status = eventplanner_vendor::STATUS_REGISTERED;
			$entity->secret = self::generate_secret();
			$entity->owner_id = $this->userSettings['account_id'];
		}
	}

	protected function doValidate($entity, &$errors)
	{
		$organization_number = $entity->organization_number;
		$duplicate_name = eventplanner_sovendor::get_instance()->check_duplicate_organization($organization_number, $entity->get_id());
		if ($duplicate_name)
		{
			$errors['organization_number'] = lang('organization number already exists for %1', $duplicate_name);
		}
	}


	public function serialize()
	{
		return self::toArray();
	}

	public function store()
	{
		return eventplanner_bovendor::get_instance()->store($this);
	}

	public function read_single($id)
	{
		return eventplanner_bovendor::get_instance()->read_single($id, true);
	}
}
