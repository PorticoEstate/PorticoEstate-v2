<?php

namespace App\modules\bookingfrontend\helpers;

/**
 * A submit stopped because a resource line in the cart was priced just now. With the
 * price category hidden (ref #1477), a time slot booked while it was shown has no price,
 * and checkout shows no amount for it, until the applicant submits. The price is stored
 * and nothing is submitted, so the applicant sees the amount before submitting again.
 * Controllers answer it with 409 and the code.
 */
class PriceSetException extends \RuntimeException
{
	public const CODE = 'price_set';

	/** @var int[] */
	private array $applicationIds;

	/**
	 * @param int[] $applicationIds The applications with a line priced
	 */
	public function __construct(array $applicationIds)
	{
		$this->applicationIds = array_values(array_map('intval', $applicationIds));

		parent::__construct('A price was set before submit for application ' . implode(', ', $this->applicationIds));
	}

	/**
	 * @return int[]
	 */
	public function getApplicationIds(): array
	{
		return $this->applicationIds;
	}

	/**
	 * The 409 body: the applicant's text, the code and the applications priced.
	 */
	public function toResponseBody(): array
	{
		return [
			'error' => lang('bookingfrontend.price_set_check_amount'),
			'code' => self::CODE,
			'application_ids' => $this->applicationIds,
		];
	}
}
