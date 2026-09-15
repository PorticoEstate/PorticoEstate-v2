<?php

use App\modules\calendar\controllers\CalendarController;
use App\modules\calendar\controllers\HolidayController;
use App\modules\calendar\controllers\CustomFieldsController;
use App\modules\calendar\viewcontrollers\CalendarViewController;
use App\modules\calendar\viewcontrollers\HolidayViewController;
use App\modules\calendar\viewcontrollers\CustomFieldsViewController;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Slim\Routing\RouteCollectorProxy;

/** @var \Slim\App $app */
/** @var \DI\Container $container */

$app->group('/calendar', function (RouteCollectorProxy $group)
{
    $group->get('', CalendarViewController::class . ':index');
    $group->get('/view/{view:day|week|week-new|month|year}', CalendarViewController::class . ':index');
    $group->get('/view/event/{id:[0-9]+}', CalendarViewController::class . ':event');
    $group->get('/events', CalendarController::class . ':events');
    $group->get('/events/{id:[0-9]+}', CalendarController::class . ':show');
    $group->map(['DELETE', 'POST'], '/events/{id:[0-9]+}', CalendarController::class . ':destroy');

    $group->group('/view/holidays', function (RouteCollectorProxy $view)
    {
        $view->get('', HolidayViewController::class . ':index');
        $view->get('/new', HolidayViewController::class . ':edit');
        $view->get('/{locale:[A-Za-z]{2}}', HolidayViewController::class . ':holidays');
        $view->get('/{locale:[A-Za-z]{2}}/new', HolidayViewController::class . ':edit');
        $view->get('/{locale:[A-Za-z]{2}}/{id:[0-9]+}/edit', HolidayViewController::class . ':edit');
    });

    $group->get('/view/custom-fields', CustomFieldsViewController::class . ':index');

    $group->get('/holidays/locales', HolidayController::class . ':locales');
    $group->get('/holidays/{locale:[A-Za-z]{2}}', HolidayController::class . ':index');
    $group->get('/holidays/{id:[0-9]+}', HolidayController::class . ':show');
    $group->post('/holidays', HolidayController::class . ':store');
    $group->put('/holidays/{id:[0-9]+}', HolidayController::class . ':update');
    $group->map(['DELETE', 'POST'], '/holidays/{id:[0-9]+}', HolidayController::class . ':destroy');
    $group->map(['DELETE', 'POST'], '/holidays/locale/{locale:[A-Za-z]{2}}', HolidayController::class . ':destroyLocale');
    $group->get('/custom-fields', CustomFieldsController::class . ':index');
    $group->put('/custom-fields', CustomFieldsController::class . ':update');
})
    ->addMiddleware(new AccessVerifier($container))
    ->addMiddleware(new SessionsMiddleware($container));
