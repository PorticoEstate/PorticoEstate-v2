<?php

/**
 * Account-specific transport creation for the legacy object factory.
 *
 * @package email
 */
class email_ofemail extends phpgwapi_object_factory
{
	public static function createObject($class, ...$parameters)
	{
		if ($class !== 'email.mail_dcom')
		{
			return parent::createObject($class, ...$parameters);
		}

		$protocol = $parameters[0] ?? '_UNDEF_';
		if ($protocol === '_UNDEF_')
		{
			$user = \App\modules\phpgwapi\services\Settings::getInstance()->get('user');
			$protocol = $user['preferences']['email']['mail_server_type'] ?? 'imap';
		}
		$protocol = strtolower((string) $protocol);
		switch ($protocol)
		{
			case 'imap':
			case 'imaps':
				require_once __DIR__ . '/class.mail_dcom.inc.php';
				return new email_mail_dcom();

			case 'pop3':
			case 'pop3s':
				require_once __DIR__ . '/class.mail_dcom_pop3_local.inc.php';
				return new mail_dcom_pop3_local();

			default:
				throw new \InvalidArgumentException('Unsupported email transport protocol: ' . $protocol);
		}
	}
}
