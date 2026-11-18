<?php
	$GLOBALS['phpgw_info']['flags'] = array(
		'currentapp' => 'email',
		'enable_network_class' => True,
		'noheader' => True,
		'nonavbar' => True
	);

	include('../header.inc.php');

	$query = $_GET;
	$msgball = is_array($query['msgball'] ?? null) ? $query['msgball'] : [];
	$msgball['acctnum'] = $msgball['acctnum'] ?? ($query['acctnum'] ?? null);
	$msgball['folder'] = $msgball['folder'] ?? ($query['folder'] ?? ($query['mailbox'] ?? null));
	$msgball['msgnum'] = $msgball['msgnum'] ?? ($query['m'] ?? null);
	$msgball['part_no'] = $msgball['part_no'] ?? ($query['p'] ?? null);

	if (
		!is_scalar($msgball['acctnum']) || !is_numeric($msgball['acctnum'])
		|| !is_string($msgball['folder']) || $msgball['folder'] === ''
		|| !is_scalar($msgball['msgnum']) || !is_numeric($msgball['msgnum'])
		|| !is_string($msgball['part_no']) || !preg_match('/^\d+(?:\.\d+)*$/', $msgball['part_no'])
	)
	{
		http_response_code(400);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Invalid image reference';
		exit;
	}

	header('Location: ' . \phpgw::link('/email/api/attachment', [
		'msgball[acctnum]' => $msgball['acctnum'],
		'msgball[folder]' => $msgball['folder'],
		'msgball[msgnum]' => $msgball['msgnum'],
		'msgball[part_no]' => $msgball['part_no'],
		'inline' => '1',
	], true), true, 302);
	exit;