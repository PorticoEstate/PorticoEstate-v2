<?php

use App\modules\email\controllers\EmailInboxController;
use App\modules\email\controllers\EmailComposeController;
use App\modules\email\controllers\EmailAttachmentController;
use App\modules\email\controllers\EmailAddressBookController;
use App\modules\email\controllers\EmailSearchController;
use App\modules\email\controllers\EmailFilterController;
use App\modules\email\controllers\EmailPreferencesController;
use App\modules\email\controllers\EmailMicrosoftOAuthController;
use App\modules\email\controllers\EmailGoogleOAuthController;
use App\modules\email\viewcontrollers\EmailComposeViewController;
use App\modules\email\viewcontrollers\EmailInboxViewController;
use App\modules\email\viewcontrollers\EmailSearchViewController;
use App\modules\email\viewcontrollers\EmailFilterViewController;
use App\modules\email\viewcontrollers\EmailPreferencesViewController;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\phpgwapi\security\AccessVerifier;
use Slim\Csrf\Guard;
use Slim\Routing\RouteCollectorProxy;

/** @var \Slim\App $app */
/** @var \DI\Container $container */

$responseFactory = $app->getResponseFactory();
$emailCsrf = function ($request, $handler) use ($responseFactory)
{
	if (strtoupper((string) $request->getMethod()) === 'POST')
	{
		$name = $request->getHeaderLine('X-CSRF-NAME') ?: (string) ($request->getQueryParams()['csrf_name'] ?? '');
		$value = $request->getHeaderLine('X-CSRF-VALUE') ?: (string) ($request->getQueryParams()['csrf_value'] ?? '');
		if ($name !== '') $request = $request->withHeader('csrf_name', $name);
		if ($value !== '') $request = $request->withHeader('csrf_value', $value);
		$parsed = $request->getParsedBody();
		if (!is_array($parsed) && str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json'))
		{
			$body = json_decode((string) $request->getBody(), true);
			if (is_array($body)) $request = $request->withParsedBody($body);
		}
	}

	$failure = static function () use ($responseFactory)
	{
		$response = $responseFactory->createResponse(400);
		$response->getBody()->write('{"error":"Failed CSRF check"}');
		return $response->withHeader('Content-Type', 'application/json');
	};
	$csrfStorage = null;
	$guard = new Guard($responseFactory, 'csrf', $csrfStorage, $failure, 200, 16, true);
	return $guard->process($request, $handler);
};

$app->group('/email', function (RouteCollectorProxy $group) use ($emailCsrf)
{
	$group->get('/api/inbox', EmailInboxController::class . ':index');
	$group->get('/api/message', EmailInboxController::class . ':message');
	$group->post('/api/messages/move', EmailInboxController::class . ':move')->add($emailCsrf);
	$group->post('/api/messages/seen', EmailInboxController::class . ':seen')->add($emailCsrf);
	$group->post('/api/messages/action', EmailInboxController::class . ':action')->add($emailCsrf);
	$group->get('/api/attachment', EmailInboxController::class . ':attachment');
	$group->get('/api/folders', EmailInboxController::class . ':folders');
	$group->post('/api/folders/action', EmailInboxController::class . ':folderAction')->add($emailCsrf);
	$group->post('/api/send', EmailComposeController::class . ':send')->add($emailCsrf);
	$group->post('/api/spellcheck', EmailComposeController::class . ':spellcheck')->add($emailCsrf);
	$group->get('/api/compose', EmailComposeController::class . ':compose');
	$group->post('/api/drafts', EmailComposeController::class . ':draft')->add($emailCsrf);
	$group->get('/api/attachments', EmailAttachmentController::class . ':index');
	$group->get('/api/addressbook', EmailAddressBookController::class . ':search');
	$group->post('/api/attachments', EmailAttachmentController::class . ':upload')->add($emailCsrf);
	$group->post('/api/attachments/delete', EmailAttachmentController::class . ':delete')->add($emailCsrf);
	$group->post('/api/search', EmailSearchController::class . ':search')->add($emailCsrf);
	$group->get('/api/filters', EmailFilterController::class . ':index');
	$group->get('/api/filters/editor', EmailFilterController::class . ':editor');
	$group->post('/api/filters/save', EmailFilterController::class . ':save')->add($emailCsrf);
	$group->post('/api/filters/action', EmailFilterController::class . ':action')->add($emailCsrf);
	$group->post('/api/filters/run', EmailFilterController::class . ':run')->add($emailCsrf);
	$group->get('/api/preferences', EmailPreferencesController::class . ':index');
	$group->post('/api/preferences', EmailPreferencesController::class . ':save')->add($emailCsrf);
	$group->get('/api/preferences/accounts', EmailPreferencesController::class . ':accounts');
	$group->get('/api/preferences/account', EmailPreferencesController::class . ':accountEditor');
	$group->post('/api/preferences/account', EmailPreferencesController::class . ':saveAccount')->add($emailCsrf);
	$group->post('/api/preferences/accounts/delete', EmailPreferencesController::class . ':deleteAccount')->add($emailCsrf);
	$group->get('/oauth/microsoft/authorize', EmailMicrosoftOAuthController::class . ':authorize');
	$group->get('/oauth/microsoft/callback', EmailMicrosoftOAuthController::class . ':callback');
	$group->get('/oauth/google/authorize', EmailGoogleOAuthController::class . ':authorize');
	$group->get('/oauth/google/callback', EmailGoogleOAuthController::class . ':callback');
	$group->get('/view/compose', EmailComposeViewController::class . ':index');
	$group->get('/view/inbox', EmailInboxViewController::class . ':index');
	$group->get('/view/message', EmailInboxViewController::class . ':message');
	$group->get('/view/folders', EmailInboxViewController::class . ':folders');
	$group->get('/view/search', EmailSearchViewController::class . ':index');
	$group->get('/view/filters', EmailFilterViewController::class . ':index');
	$group->get('/view/filter', EmailFilterViewController::class . ':edit');
	$group->get('/view/preferences', EmailPreferencesViewController::class . ':index');
	$group->get('/view/accounts', EmailPreferencesViewController::class . ':accounts');
	$group->get('/view/account', EmailPreferencesViewController::class . ':account');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
