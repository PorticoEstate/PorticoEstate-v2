<?php

namespace App\modules\booking\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\booking\services\CommitTracker;

/**
 * Exposes the running git commit of the Slim instance and the deploy history
 * recorded in bb_running_commit. Mirrors the Next.js /api/version endpoint so
 * the same kind of deploy indicator can be built for the admin side.
 */
class VersionController
{
	private CommitTracker $tracker;

	public function __construct()
	{
		$this->tracker = new CommitTracker();
	}

	/**
	 * GET /booking/version
	 * The currently running commit and when it was first observed.
	 */
	public function current(Request $request, Response $response): Response
	{
		$response->getBody()->write(json_encode($this->tracker->getCurrent()));
		return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
	}

	/**
	 * GET /booking/version/history
	 * Every recorded commit, newest first.
	 */
	public function index(Request $request, Response $response): Response
	{
		$response->getBody()->write(json_encode($this->tracker->all()));
		return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
	}
}
