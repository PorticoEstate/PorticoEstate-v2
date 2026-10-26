<?php

namespace App\modules\email\services;

final class GoogleSmtpOAuthTokenProvider implements \PHPMailer\PHPMailer\OAuthTokenProvider
{
	public function __construct(private string $emailAddress, private string $accessToken)
	{
		if ($this->emailAddress === '' || $this->accessToken === '')
		{
			throw new \InvalidArgumentException('A Gmail address and OAuth access token are required');
		}
	}

	public function getOauth64(): string
	{
		return base64_encode('user=' . $this->emailAddress . "\001auth=Bearer " . $this->accessToken . "\001\001");
	}
}