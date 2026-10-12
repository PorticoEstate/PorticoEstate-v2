<?php
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\Settings;

	/***************************************************************************\
	* EGroupWare - EMailAdmin                                                   *
	* http://www.egroupware.org                                                 *
	* Written by : Lars Kneschke [lkneschke@linux-at-work.de]                   *
	* -------------------------------------------------                         *
	* This program is free software; you can redistribute it and/or modify it   *
	* under the terms of the GNU General Public License as published by the     *
	* Free Software Foundation; either version 2 of the License, or (at your    *
	* option) any later version.                                                *
	\***************************************************************************/
	/* $Id$ */

	class defaultsmtp
	{
		var $phpgwapi_common;

		var $smtpAuth = false;
		
		var $editForwardingAddress = false;		

		var $host;
		
		var $port;
		
		var $username;
		
		var $password;
		
		var $defaultDomain;
		
		// the constructor
		function __construct($defaultDomain=null)
		{
			$this->phpgwapi_common = new \phpgwapi_common();
			$this->defaultDomain = $defaultDomain ? $defaultDomain : Settings::getInstance()->get('server')['mail_suffix'];
		}
		
		// add a account
		function addAccount($_hookValues)
		{
			return true;
		}
		
		// delete a account
		function deleteAccount($_hookValues)
		{
			return true;
		}
		
		function getAccountEmailAddress($_accountName)
		{
			$accounts_obj = new Accounts();
			$accountID = $accounts_obj->name2id($_accountName);
			$emailAddress = $accounts_obj->id2name($accountID);
			if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL))
				$emailAddress = $_accountName.'@'.$this->defaultDomain;

			$realName = trim(Settings::getInstance()->get('user')['firstname'] . (!empty(Settings::getInstance()->get('user')['firstname']) ? ' ' : '') . Settings::getInstance()->get('user')['lastname']);

			return array(
				array(
					'name'		=> $realName, 
					'address'	=> $emailAddress, 
					'type'		=> 'default'
				)
			);
		}

		function getUserData($_uidnumber) {
			$userData = array();
			
			return $userData;
		}

		function saveSMTPForwarding($_accountID, $_forwardingAddress, $_keepLocalCopy) {
			return true;
		}

		function setUserData($_uidnumber, $_mailAlternateAddress, $_mailForwardingAddress, $_deliveryMode, $_accountStatus = null, $_mailLocalAddress = null) {
			return true;
		}
		
		// update a account
		function updateAccount($_hookValues) {
			return true;
		}
	}
