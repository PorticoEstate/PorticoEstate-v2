<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\controllers\Locations;

abstract class activitycalendar_model
{

	protected $validation_errors = array();
	protected $validation_warnings = array();
	protected $consistency_warnings = array();
	protected $field_of_responsibility_id;
	protected $field_of_responsibility_name;
	protected $permission_array;
	protected $id;
	private $acl;

	public function __construct(int $id)
	{
		$this->id = (int)$id;
		$this->acl = Acl::getInstance();
	}

	public function get_id()
	{
		return $this->id;
	}

	public function set_id($id)
	{
		$this->id = $id;
	}

	/**
	 * Retrieve the name of the 'field of responsibility' this object belongs to.
	 * The default name is the root location (.)
	 *
	 * @return the field name
	 */
	public function get_field_of_responsibility_name()
	{
		if (!isset($this->field_of_responsibility_name))
		{
			if (isset($this->field_of_responsibility_id))
			{
				$location_obj = new Locations();
				$array = $location_obj->get_name($this->field_of_responsibility_id);
				$flags = Settings::getInstance()->get('flags');

				if ($array['appname'] = $flags['currentapp'])
				{
					$this->field_of_responsibility_name = $array['location'];
				}
			}
			else
			{
				$this->field_of_responsibility_name = '.';
			}
			return $this->field_of_responsibility_name;
		}
		else
		{
			return $this->field_of_responsibility_name;
		}
	}

	/**
	 * Check if the current user has been given permission for a given action
	 *
	 * @param $permission
	 * @return true if current user has permission, false otherwise
	 */
	public function has_permission($permission = ACL_PRIVATE)
	{
		return $this->acl->check($this->get_field_of_responsibility_name(), $permission, 'bkbooking');
	}

	/**
	 * Set the identifier for the field of responsibility this object belongs to
	 *
	 * @param $id the ocation identifier
	 */
	public function set_field_of_responsibility_id($id)
	{
		$this->field_of_responsibility_id = $id;
	}

	/**
	 * Retrieve an array with the different permission levels the current user has for this object
	 *
	 * @return an array with permissions [PERMISSION_BITMASK => true/false]
	 */
	public function get_permission_array()
	{
		$location_name = $this->get_field_of_responsibility_name();
		return array(
			ACL_READ => $this->acl->check($location_name, ACL_READ, 'bkbooking'),
			ACL_ADD => $this->acl->check($location_name, ACL_ADD, 'bkbooking'),
			ACL_EDIT => $this->acl->check($location_name, ACL_EDIT, 'bkbooking'),
			ACL_DELETE => $this->acl->check($location_name, ACL_DELETE, 'bkbooking'),
			ACL_PRIVATE => $this->acl->check($location_name, ACL_PRIVATE, 'bkbooking')
		);
	}

	/**
	 * Validate the object according to the database setup and custom rules.  This function
	 * can be overridden in subclasses.  It is then up to the subclasses to call this parent method
	 * in order to validate against the standard database rules.  The subclasses can in addition
	 * add their own specific validation logic.
	 *
	 * @return bool true if the object is valid, false otherwise
	 */
	public function validates()
	{
		return true;
	}

	public function check_consistency()
	{
		return true;
	}

	public function validate_numeric()
	{
		return true;
	}

	public function set_validation_error(string $rule_name, string $error_language_key)
	{
		$this->validation_errors[$rule_name] = $error_language_key;
	}

	public function get_validation_errors()
	{
		return $this->validation_errors;
	}

	public function set_validation_warning(string $warning_language_key)
	{
		$this->validation_warnings[] = $warning_language_key;
	}

	public function set_consistency_warning(string $warning_language_key)
	{
		$this->consistency_warnings[] = array('warning' => $warning_language_key);
	}

	public function get_consistency_warnings()
	{
		return $this->consistency_warnings;
	}

	public function get_validation_warnings()
	{
		return $this->validation_warnings;
	}

	/**
	 * Gets the value of the class attribute with the given name.  As such this function translates from
	 * string to variable.
	 *
	 * @param $field the name of the class attribute to get
	 * @return mixed the value of the attribute
	 */
	public function get_field($field)
	{
		return $this->{"$field"};
	}

	/**
	 * Sets the value of the class attribute with the given name.  As such this function translates from
	 * string to variable name.
	 *
	 * @param $field the name of the class attribute to set
	 * @param $value the value to set
	 */
	public function set_field($field, $value)
	{
		$this->{"$field"} = $value;
	}

	public abstract function serialize();
}
