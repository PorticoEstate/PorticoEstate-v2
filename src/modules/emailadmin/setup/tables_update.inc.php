<?php

/**************************************************************************\
 * EGroupWare - EMailadmin                                                  *
 * http://www.egroupware.org                                                *
 * http://www.phpgw.de                                                      *
 * Author: lkneschke@phpgw.de                                               *
 * --------------------------------------------                             *
 *  This program is free software; you can redistribute it and/or modify it *
 *  under the terms of the GNU General Public License as published by the   *
 *  Free Software Foundation; either version 2 of the License, or (at your  *
 *  option) any later version.                                              *
	\**************************************************************************/
/* $Id$ */


$test[] = '1.5.003';
function emailadmin_upgrade1_5_003($oProc)
{
	$oProc->m_odb->transaction_begin();

	//EMAIL_MICROSOFT_TENANT_ID
	$oProc->AddColumn('egw_emailadmin', 'microsoft_tenant_id', array(
		'type' => 'varchar',
		'precision' => 255,
		'nullable' => True
	));

	//EMAIL_MICROSOFT_CLIENT_ID
	$oProc->AddColumn('egw_emailadmin', 'microsoft_client_id', array(
		'type' => 'varchar',
		'precision' => 255,
		'nullable' => True
	));
	//EMAIL_MICROSOFT_CLIENT_SECRET
	$oProc->AddColumn('egw_emailadmin', 'microsoft_client_secret', array(
		'type' => 'varchar',
		'precision' => 255,
		'nullable' => True
	));
	//EMAIL_MICROSOFT_REDIRECT_URI
	$oProc->AddColumn('egw_emailadmin', 'microsoft_redirect_uri', array(
		'type' => 'varchar',
		'precision' => 255,
		'nullable' => True
	));

	if ($oProc->m_odb->transaction_commit())
	{
		return	'1.5.004';
	}
}

$test[] = '1.5.004';
function emailadmin_upgrade1_5_004($oProc)
{
	$oProc->m_odb->transaction_begin();
	foreach (['google_client_id', 'google_client_secret', 'google_redirect_uri'] as $field)
	{
		$oProc->AddColumn('egw_emailadmin', $field, array(
			'type' => 'varchar',
			'precision' => 255,
			'nullable' => True
		));
	}
	if ($oProc->m_odb->transaction_commit())
	{
		return '1.5.005';
	}
}

$test[] = '1.5.005';
function emailadmin_upgrade1_5_005($oProc)
{
	$oProc->m_odb->transaction_begin();
	if (!$oProc->RenameTable('egw_emailadmin', 'phpgw_emailadmin'))
	{
		$oProc->m_odb->transaction_abort();
		return false;
	}
	if ($oProc->m_odb->transaction_commit())
	{
		return '1.5.006';
	}
}
