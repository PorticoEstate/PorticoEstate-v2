<?php

/**
 * Custom object factory
 *
 * @package phpgroupware
 * @subpackage property
 */
class property_ofproperty extends phpgwapi_object_factory
{

	/**
	 * Instantiate a class
	 *
	 * @return object the instantiated class
	 */
	public static function createObject(
		$class,
		$p1 = '_UNDEF_',
		$p2 = '_UNDEF_',
		$p3 = '_UNDEF_',
		$p4 = '_UNDEF_',
		$p5 = '_UNDEF_',
		$p6 = '_UNDEF_',
		$p7 = '_UNDEF_',
		$p8 = '_UNDEF_',
		$p9 = '_UNDEF_',
		$p10 = '_UNDEF_',
		$p11 = '_UNDEF_',
		$p12 = '_UNDEF_',
		$p13 = '_UNDEF_',
		$p14 = '_UNDEF_',
		$p15 = '_UNDEF_',
		$p16 = '_UNDEF_'
	)
	{
		list($appname, $classname) = explode('.', $class, 2);
		switch ($classname)
		{
			case 'custom_fields':
				include_class($appname, $classname);
				$_appname   = ($p1 !== '_UNDEF_') ? $p1 : null;
				return \property_custom_fields::getInstance($_appname);

			case 'botts':
				include_class($appname, $classname);
				return \property_botts::getInstance();

			case 'sotts':
				include_class($appname, $classname);
				return \property_sotts::getInstance();

			case 'soproject':
				include_class($appname, $classname);
				return \property_soproject::getInstance();

			case 'soworkorder':
				include_class($appname, $classname);
				return \property_soworkorder::getInstance();

			case 'soentity':
				$entity_id = ($p1 !== '_UNDEF_') ? $p1 : '.';
				$cat_id = ($p2 !== '_UNDEF_') ? $p2 : null;
				include_class($appname, $classname);
				return new property_soentity($entity_id, $cat_id);

			default:
				return parent::createObject(
					$class,
					$p1,
					$p2,
					$p3,
					$p4,
					$p5,
					$p6,
					$p7,
					$p8,
					$p9,
					$p10,
					$p11,
					$p12,
					$p13,
					$p14,
					$p15,
					$p16
				);
		}
	}
}
