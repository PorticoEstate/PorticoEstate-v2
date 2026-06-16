<?php

namespace App\modules\bookingfrontend\controllers;

use App\helpers\ResponseHelper;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Config;
use App\modules\phpgwapi\services\Hooks;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Sanitizer;

require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';


/**
 * @OA\Tag(
 *     name="Authentication",
 *     description="API Endpoints for authentication"
 * )
 */
class LoginController
{
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @OA\Post(
     *     path="/bookingfrontend/auth/login",
     *     summary="Initialize anonymous session",
     *     description="Creates anonymous session using configured credentials",
     *     tags={"Authentication"},
     *     @OA\Response(
     *         response=200,
     *         description="Login successful",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="sessionId", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Authentication failed",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string")
     *         )
     *     )
     * )
     */
    public function login(Request $request, Response $response): Response
    {
        $sessions = Sessions::getInstance();

        // Start with a clean slate
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $config = (new Config('bookingfrontend'))->read();

        $login = $config['anonymous_user'];
        $logindomain = Sanitizer::get_var('domain', 'string', 'GET');
        if ($logindomain && strstr($login, '#') === false) {
            $login .= "#{$logindomain}";
        }

        $passwd = $config['anonymous_passwd'];
        $_POST['submitit'] = "";

        $sessionid = $sessions->create($login, $passwd);

        if (!$sessionid) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Anonymous session creation failed'],
                401
            );
        }

        $bouser = new UserHelper();
        if (!$bouser->log_in()) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'User session initialization failed'],
                401
            );
        }

        // Force session write
        session_write_close();

        // Get cookie parameters
        $cookieParams = session_get_cookie_params();

        // Add the session cookie to response
        $response = $response->withHeader(
            'Set-Cookie',
            sprintf(
                '%s=%s; Path=%s; Domain=%s; SameSite=%s%s%s',
                session_name(),
                $sessionid,
                $cookieParams['path'],
                $cookieParams['domain'],
                $cookieParams['samesite'],
                $cookieParams['secure'] ? '; Secure' : '',
                $cookieParams['httponly'] ? '; HttpOnly' : ''
            )
        );

        // Verify everything is set
//        $orgnr = Cache::session_get('bookingfrontend', UserHelper::ORGNR_SESSION_KEY);
//        $org_id = Cache::session_get('bookingfrontend', UserHelper::ORGID_SESSION_KEY);

        return ResponseHelper::sendErrorResponse(
            [
                'success' => true,
                'sessionId' => $sessionid,
//                'debug' => [
//                    'orgnr' => $orgnr,
//                    'org_id' => $org_id,
//                    'session_name' => session_name(),
//                    'cookie_params' => $cookieParams
//                ]
            ],
            200,
            $response
        );
    }
    /**
     * @OA\Post(
     *     path="/bookingfrontend/auth/logout",
     *     summary="Logout current session",
     *     description="Destroys current session and handles external logout",
     *     tags={"Authentication"},
     *     @OA\Response(
     *         response=200,
     *         description="Logout successful",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="success",
     *                 type="boolean",
     *                 description="Whether logout was successful"
     *             ),
     *             @OA\Property(
     *                 property="external_logout_url",
     *                 type="string",
     *                 description="URL for external logout if configured",
     *                 nullable=true
     *             )
     *         )
     *     )
     * )
     */
    public function logout(Request $request, Response $response): Response
    {
        $sessions = Sessions::getInstance();
        $sessionid = Sanitizer::get_var('bookingfrontendsession');

        $verified = $sessions->verify();

        $external_logout = '';
        if ($verified) {
            $config = new Config('bookingfrontend');
            $config->read();

            $bookingfrontend_host = isset($config->config_data['bookingfrontend_host']) && $config->config_data['bookingfrontend_host'] ?
                rtrim($config->config_data['bookingfrontend_host'], '/') : '';

            $external_logout = isset($config->config_data['external_logout']) && $config->config_data['external_logout'] ?
                $config->config_data['external_logout'] : '';

            $frontend_user = new UserHelper();
            $frontend_user->log_off();

            \execMethod('phpgwapi.menu.clear');  // Added backslash for global namespace
            $hooks = new Hooks();
            $hooks->process('logout');
            $sessions->destroy($sessionid);
        }

        $response_data = [
            'success' => true
        ];

        if ($external_logout) {
            $result_redirect = '';
            if (substr($external_logout, -1) == '=') {
                $external_logout = rtrim($external_logout, '=');
                $result_redirect = \phpgw::link('/bookingfrontend/', [], true);
            }
            $response_data['external_logout_url'] = "{$external_logout}{$bookingfrontend_host}{$result_redirect}";
        }

        return ResponseHelper::sendErrorResponse(
            $response_data,
            200
        );
    }
}