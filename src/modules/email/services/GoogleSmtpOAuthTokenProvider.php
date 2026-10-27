<?php

/**
 * EMail - Gmail SMTP Authentication
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Supplies PHPMailer with the SASL XOAUTH2 payload for a Gmail address and access token.
 */

namespace App\modules\email\services;

final class GoogleSmtpOAuthTokenProvider implements \PHPMailer\PHPMailer\OAuthTokenProvider
{
	/**
	 * Validate and retain the Gmail address and access token for SMTP authentication.
	 *
	 * @param string $emailAddress The full Gmail mailbox address to bind to authorization.
	 * @param string $accessToken The current provider access token.
	 */
	public function __construct(private string $emailAddress, private string $accessToken)
	{
		if ($this->emailAddress === '' || $this->accessToken === '')
		{
			throw new \InvalidArgumentException('A Gmail address and OAuth access token are required');
		}
	}

	/**
	 * Build the SASL XOAUTH2 authentication payload expected by PHPMailer.
	 *
	 * @return string The base64-encoded user and bearer-token payload.
	 */
	public function getOauth64(): string
	{
		return base64_encode('user=' . $this->emailAddress . "\001auth=Bearer " . $this->accessToken . "\001\001");
	}
}