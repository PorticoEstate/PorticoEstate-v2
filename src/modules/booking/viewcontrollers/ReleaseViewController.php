<?php

namespace App\modules\booking\viewcontrollers;

use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use App\modules\booking\services\CommitTracker;
use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

/**
 * Renders the release history page: the recorded running commits (from
 * bb_running_commit) become version boundaries, and the browser fetches the
 * commits between each boundary from the GitHub compare API.
 *
 * Public page — rendered standalone via _layout.twig rather than through the
 * login-bound legacy portico frame, so it works without a session.
 */
class ReleaseViewController
{
	// Public repo the running commits live in — same repo the Next.js deploy
	// indicator compares against.
	private const GITHUB_REPO = 'PorticoEstate/PorticoEstate-v2';

	protected TwigHelper $twig;
	protected CommitTracker $tracker;

	public function __construct()
	{
		// This page is unauthenticated, so the session-scoped settings TwigHelper
		// relies on may be unset — default them before constructing it.
		$settings = Settings::getInstance();
		if (!is_array($settings->get('user'))) {
			$settings->set('user', []);
		}
		$flags = $settings->get('flags') ?: [];
		$flags['currentapp'] = 'booking';
		$settings->set('flags', $flags);

		$this->twig = new TwigHelper('booking');
		$this->tracker = new CommitTracker();
	}

	public function index(Request $request, Response $response): Response
	{
		try {
			// Recorded commits, newest first — these are the version boundaries.
			$versions = $this->tracker->all();

			$html = $this->twig->render('@views/releases/releases.twig', [
				'layout'      => '@views/_layout.twig',
				'versions'    => $versions,
				'repo'        => self::GITHUB_REPO,
				// _layout.twig loops this (strict_variables); the designsystemet
				// CSS is linked explicitly in the template instead.
				'stylesheets' => [],
			]);

			$response->getBody()->write($html);
			return $response->withHeader('Content-Type', 'text/html');
		} catch (Exception $e) {
			return ResponseHelper::sendErrorResponse(
				['error' => 'Error loading release history: ' . $e->getMessage()],
				500
			);
		}
	}
}
