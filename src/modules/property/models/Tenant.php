<?php

namespace App\modules\property\models;

use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="Tenant",
 *     type="object",
 *     title="Tenant",
 *     description="Expanded Tenant model"
 * )
 * @Exclude
 */
class Tenant
{
	use SerializableTrait;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $id;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $member_of;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $entry_date;
	
	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $first_name;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $last_name;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $contact_phone;
	
	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $contact_email;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $category;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $phpgw_account_id;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $account_lid;

	/**
	 * @OA\Property(type="string")
	 * @Exclude
	 */
	public $account_pwd;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $account_status;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $owner_id;

	/**
	 * @OA\Property(type="string")
	 * @Exclude
	 */
	public $ssn;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $location_code;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $address;

	public function __construct($data = [])
	{
		if (!empty($data))
		{
			$this->populate($data);
		}
	}

	public function populate(array $data)
	{
		$this->id = $data['id'] ?? null;
		$this->member_of = $data['member_of'] ?? '';
		$this->entry_date = $data['entry_date'] ?? '';
		$this->first_name = $data['first_name'] ?? '';
		$this->last_name = $data['last_name'] ?? '';
		$this->contact_phone = $data['contact_phone'] ?? '';
		$this->contact_email = $data['contact_email'] ?? '';
		$this->category = $data['category'] ?? '';
		$this->phpgw_account_id = $data['phpgw_account_id'] ?? '';
		$this->account_lid = $data['account_lid'] ?? '';
		$this->account_pwd = $data['account_pwd'] ?? '';
		$this->account_status = $data['account_status'] ?? '';
		$this->owner_id = $data['owner_id'] ?? '';
		$this->ssn = $data['ssn'] ?? '';
		$this->location_code = $data['location_code'] ?? '';
		$this->address = $data['street'] ? $data['street'] . ' ' . $data['street_number'] . ', ' . $data['etasje']: '';

	}
}
