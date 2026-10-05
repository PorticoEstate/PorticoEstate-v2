<?php

namespace App\modules\bookingfrontend\helpers;

/**
 * A citizen's price choice for a resource cannot be accepted: it was missing
 * where the resource has several prices and no default, or it named a price
 * the resource does not offer today (another mapping's, an inactive or a
 * future one). Controllers answer it with 422 and the code, so the client can
 * tell the two apart without reading the message.
 */
class PriceChoiceException extends \InvalidArgumentException
{
	public const REQUIRED = 'price_choice_required';
	public const INVALID = 'price_choice_invalid';

	private string $reason;
	private int $articleMappingId;

	public function __construct(string $reason, int $articleMappingId)
	{
		$this->reason = $reason;
		$this->articleMappingId = $articleMappingId;

		parent::__construct($reason === self::REQUIRED
			? "A price choice is required for article mapping {$articleMappingId}"
			: "The price choice is not offered for article mapping {$articleMappingId}");
	}

	public function getReason(): string
	{
		return $this->reason;
	}

	public function getArticleMappingId(): int
	{
		return $this->articleMappingId;
	}

	/**
	 * The 422 body: the shape the upload refusal uses, plus the code and mapping.
	 */
	public function toResponseBody(): array
	{
		return [
			'error' => $this->getMessage(),
			'errors' => [$this->getMessage()],
			'code' => $this->reason,
			'article_mapping_id' => $this->articleMappingId,
		];
	}
}
