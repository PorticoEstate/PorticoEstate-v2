<?php

use App\modules\phpgwapi\controllers\ServerSettingsController;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\modules\phpgwapi\controllers\StartPoint;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use App\modules\preferences\helpers\PreferenceHelper;
use App\modules\phpgwapi\helpers\HomeHelper;
use App\modules\phpgwapi\helpers\LoginHelper;
use App\modules\phpgwapi\helpers\RedirectHelper;
use Slim\Routing\RouteCollectorProxy;


$app->get('/', StartPoint::class . ':run')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/', StartPoint::class . ':run')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/index.php', StartPoint::class . ':run')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/index.php', StartPoint::class . ':run')->add(new SessionsMiddleware($app->getContainer()));


$app->get('/preferences/', PreferenceHelper::class . ':index')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/preferences/', PreferenceHelper::class . ':index')->add(new SessionsMiddleware($app->getContainer()));

// Define a factory for the Preferences singleton in the container
$container->set(PreferenceHelper::class, function ($container)
{
    return PreferenceHelper::getInstance();
});

$app->get('/preferences/section', PreferenceHelper::class . ':section')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/preferences/section', PreferenceHelper::class . ':section')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/preferences/changepassword', PreferenceHelper::class . ':changepassword')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/preferences/changepassword', PreferenceHelper::class . ':changepassword')->add(new SessionsMiddleware($app->getContainer()));

$app->get('/home/', HomeHelper::class . ':processHome')->add(new SessionsMiddleware($app->getContainer()));


$app->get('/swagger[/{params:.*}]', function (Request $request, Response $response)
{
    $json_file = __DIR__ . '/../../swagger.json';
    $json = file_get_contents($json_file);
    $response = $response->withHeader('Content-Type', 'application/json');
    $response->getBody()->write($json);
    return $response;
});


$app->get('/redirect.php', RedirectHelper::class . ':processRedirect');

$app->get('/login.php', LoginHelper::class . ':processLogin');
$app->post('/login.php', LoginHelper::class . ':processLogin');
$app->get('/login_ui[/{params:.*}]', LoginHelper::class . ':processLogin');
$app->post('/login_ui[/{params:.*}]', LoginHelper::class . ':processLogin');

$app->get('/login[/{params:.*}]', function (Request $request, Response $response) use ($phpgw_domain)
{

    $last_domain = \Sanitizer::get_var('last_domain', 'string', 'COOKIE', false);
    $domainOptions = '';
    foreach (array_keys($phpgw_domain) as $domain)
    {
        $selected = ($domain === $last_domain) ? 'selected' : '';
        $domainOptions .= "<option value=\"$domain\" $selected>$domain</option>";
    }

    $sectionOptions = "<option value=\"\">None</option>";
    $sections = ['activitycalendarfrontend', 'bookingfrontend', 'eventplannerfrontend', 'mobilefrontend'];
    foreach ($sections as $section)
    {
        $sectionOptions .= "<option value=\"$section\">$section</option>";
    }


    $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
        </head>
        <body>
            <div class="container">
                <form method="POST" action="./login">
                    <div class="mb-3">
                        <label for="login" class="form-label">Login:</label>
                        <input type="text" class="form-control" id="login" name="login">
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password:</label>
                        <input type="password" class="form-control" id="password" name="passwd">
                    </div>
                    <div class="mb-3">
                        <label for="logindomain">Domain:</label>
                        <select class="form-select" id="logindomain" name="logindomain">
                            ' . $domainOptions . '
                        </select>
                    </div>
					<div class="mb-3">
						<label for="section">Section:</label>
						<select class="form-select" id="section" name="section">
							' . $sectionOptions . '
						</select>
					</div>
                    <button type="submit" class="btn btn-primary">Submit</button>
                </form>
            </div>
        </body>
        </html>
    ';
    $response = $response->withHeader('Content-Type', 'text/html');
    $response->getBody()->write($html);
    return $response;
});


$app->post('/login', function (Request $request, Response $response)
{
    // Get the session ID
    $session_id = session_id();

    // Prepare the response
    $json = json_encode(['session_name' => session_name(),'session_id' => $session_id]);
    $response = $response->withHeader('Content-Type', 'application/json');
    $response->getBody()->write($json);
    return $response;
})
    ->addMiddleware(new App\modules\phpgwapi\middleware\LoginMiddleware($container));

$app->get('/refreshsession[/{params:.*}]', function (Request $request, Response $response)
{
    $sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
    if (!$sessions->verify())
    {
        $response_str = json_encode(['message' => 'Du er ikke logget inn']);
        $response->getBody()->write($response_str);
        return $response->withHeader('Content-Type', 'application/json');
    } else
    {
        $session_id = $sessions->get_session_id();
        $response_str = json_encode(['session_id' => $session_id, 'fullname' => $sessions->get_user()['fullname']]);
        $response->getBody()->write($response_str);
        return $response->withHeader('Content-Type', 'application/json');
    }
});

$app->get('/logout[/{params:.*}]', function (Request $request, Response $response)
{
    $sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
    if (!$sessions->verify())
    {
        $response_str = json_encode(['message' => 'Du er ikke logget inn']);
        $response->getBody()->write($response_str);
        return $response->withHeader('Content-Type', 'application/json');
    } else
    {
        $session_id = $sessions->get_session_id();
        $sessions->destroy($session_id);
        $response_str = json_encode(['message' => 'Du er logget ut']);
        $response->getBody()->write($response_str);
        return $response->withHeader('Content-Type', 'application/json');
    }
});


$app->get('/logout_ui[/{params:.*}]', function (Request $request, Response $response)
{
    $sessions = \App\modules\phpgwapi\security\Sessions::getInstance();
    $session_id = $sessions->get_session_id();
    if ($session_id)
    {
        $sessions->destroy($session_id);
    }
    phpgw::redirect_link('/login_ui', array('cd' => 1, 'logout' => 1));
});

$app->group('/api', function (RouteCollectorProxy $group)
{
    $group->get('/server-settings', ServerSettingsController::class . ':index');
});

