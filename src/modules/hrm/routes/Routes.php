<?php

use App\modules\hrm\helpers\RedirectHelper;
use App\modules\hrm\controllers\HrmCategoryController;
use App\modules\hrm\controllers\HrmJobController;
use App\modules\hrm\controllers\HrmPlaceController;
use App\modules\hrm\controllers\HrmUserController;
use App\modules\hrm\viewcontrollers\HrmCategoryViewController;
use App\modules\hrm\viewcontrollers\HrmJobViewController;
use App\modules\hrm\viewcontrollers\HrmPlaceViewController;
use App\modules\hrm\viewcontrollers\HrmUserViewController;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Slim\Routing\RouteCollectorProxy;

/** @var \Slim\App $app */
/** @var \DI\Container $container */

$app->group('/hrm', function (RouteCollectorProxy $group)
{
	$group->get('/view/users', HrmUserViewController::class . ':index');
	$group->get('/view/users/{id:[0-9]+}/training', HrmUserViewController::class . ':training');
	$group->get('/view/users/{id:[0-9]+}/training/cv', HrmUserViewController::class . ':viewCv');
	$group->get('/view/users/{id:[0-9]+}/training/{trainingId:[0-9]+}', HrmUserViewController::class . ':view');
	$group->map(['GET', 'POST'], '/view/users/{id:[0-9]+}/training/new', HrmUserViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/users/{id:[0-9]+}/training/{trainingId:[0-9]+}/edit', HrmUserViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/users/{id:[0-9]+}/training/{trainingId:[0-9]+}/delete', HrmUserViewController::class . ':delete');
	$group->get('/view/places', HrmPlaceViewController::class . ':index');
	$group->map(['GET', 'POST'], '/view/places/new', HrmPlaceViewController::class . ':edit');
	$group->get('/view/places/{id:[0-9]+}', HrmPlaceViewController::class . ':view');
	$group->map(['GET', 'POST'], '/view/places/{id:[0-9]+}/edit', HrmPlaceViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/places/{id:[0-9]+}/delete', HrmPlaceViewController::class . ':delete');
	$group->get('/view/categories/{type}', HrmCategoryViewController::class . ':index');
	$group->map(['GET', 'POST'], '/view/categories/{type}/new', HrmCategoryViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/categories/{type}/{id:[0-9]+}/edit', HrmCategoryViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/categories/{type}/{id:[0-9]+}/delete', HrmCategoryViewController::class . ':delete');
	$group->get('/view/jobs', HrmJobViewController::class . ':index');
	$group->get('/view/jobs/hierarchy', HrmJobViewController::class . ':hierarchy');
	$group->map(['GET', 'POST'], '/view/jobs/reset-hierarchy', HrmJobViewController::class . ':resetHierarchy');
	$group->map(['GET', 'POST'], '/view/jobs/pdf', HrmJobViewController::class . ':printPdf');
	$group->get('/view/qualification-types', HrmJobViewController::class . ':qualificationTypes');
	$group->map(['GET', 'POST'], '/view/qualification-types/new', HrmJobViewController::class . ':editQualificationType');
	$group->map(['GET', 'POST'], '/view/qualification-types/{qualificationTypeId:[0-9]+}/edit', HrmJobViewController::class . ':editQualificationType');
	$group->map(['GET', 'POST'], '/view/jobs/new', HrmJobViewController::class . ':edit');
	$group->get('/view/jobs/{jobId:[0-9]+}/tasks', HrmJobViewController::class . ':tasks');
	$group->map(['GET', 'POST'], '/view/jobs/{jobId:[0-9]+}/tasks/new', HrmJobViewController::class . ':editTask');
	$group->get('/view/jobs/{jobId:[0-9]+}/tasks/{taskId:[0-9]+}', HrmJobViewController::class . ':viewTask');
	$group->map(['GET', 'POST'], '/view/jobs/{jobId:[0-9]+}/tasks/{taskId:[0-9]+}/edit', HrmJobViewController::class . ':editTask');
	$group->map(['GET', 'POST'], '/view/jobs/{jobId:[0-9]+}/tasks/{taskId:[0-9]+}/delete', HrmJobViewController::class . ':deleteTask');
	$group->get('/view/jobs/{jobId:[0-9]+}/tasks/{taskId:[0-9]+}/move/{direction:up|down}', HrmJobViewController::class . ':moveTask');
	$group->get('/view/jobs/{jobId:[0-9]+}/qualifications', HrmJobViewController::class . ':qualifications');
	$group->map(['GET', 'POST'], '/view/jobs/{jobId:[0-9]+}/qualifications/new', HrmJobViewController::class . ':editQualification');
	$group->get('/view/jobs/{jobId:[0-9]+}/qualifications/{qualificationId:[0-9]+}', HrmJobViewController::class . ':viewQualification');
	$group->map(['GET', 'POST'], '/view/jobs/{jobId:[0-9]+}/qualifications/{qualificationId:[0-9]+}/edit', HrmJobViewController::class . ':editQualification');
	$group->map(['GET', 'POST'], '/view/jobs/{jobId:[0-9]+}/qualifications/{qualificationId:[0-9]+}/delete', HrmJobViewController::class . ':deleteQualification');
	$group->get('/view/jobs/{jobId:[0-9]+}/qualifications/{qualificationId:[0-9]+}/move/{direction:up|down}', HrmJobViewController::class . ':moveQualification');
	$group->get('/view/jobs/{id:[0-9]+}', HrmJobViewController::class . ':view');
	$group->map(['GET', 'POST'], '/view/jobs/{id:[0-9]+}/edit', HrmJobViewController::class . ':edit');
	$group->map(['GET', 'POST'], '/view/jobs/{id:[0-9]+}/delete', HrmJobViewController::class . ':delete');
	$group->get('/users', HrmUserController::class . ':index');
	$group->get('/users/{id:[0-9]+}/training', HrmUserController::class . ':training');
	$group->get('/places', HrmPlaceController::class . ':index');
	$group->get('/categories/{type}', HrmCategoryController::class . ':index');
	$group->get('/jobs', HrmJobController::class . ':index');
	$group->get('/qualification-types', HrmJobController::class . ':qualificationTypes');
	$group->get('/jobs/{jobId:[0-9]+}/tasks', HrmJobController::class . ':tasks');
	$group->get('/jobs/{jobId:[0-9]+}/qualifications', HrmJobController::class . ':qualifications');
})
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));

$app->get('/hrm[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
