<?php

namespace App\helpers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseFactoryInterface;
use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\services\Log;
use App\Database\Db;
use Psr\Http\Server\RequestHandlerInterface;
//use Psr\Http\Server\MiddlewareInterface;
use Throwable;
use ErrorException;
use App\modules\phpgwapi\services\Settings;
use Sanitizer;

$serverSettings  = Settings::getInstance()->get('server');

if (isset($serverSettings['log_levels']['global_level']))
{
	switch ($serverSettings['log_levels']['global_level'])
	{
		case 'F': // Fatal
		case 'E': // Error
			error_reporting(E_ERROR | E_USER_ERROR | E_PARSE);
			break;

		case 'W': // Warn
		case 'I': // Info
			error_reporting(E_ERROR | E_USER_ERROR | E_WARNING | E_USER_WARNING | E_PARSE);
			break;

		case 'N': // Notice
		case 'D': // Debug
			error_reporting(E_ERROR | E_USER_ERROR | E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE | E_PARSE);
			break;

		case 'S': // Strict
			error_reporting(E_STRICT | E_PARSE);
			break;

		case 'DP': // Deprecated
			error_reporting(E_ERROR | E_USER_ERROR | E_DEPRECATED | E_USER_DEPRECATED | E_PARSE | E_ALL);
			break;
		case 'A': // All
			error_reporting(E_ALL);
			break;
	}
}


/**
 * phpGroupWare Information level "error"
 */
define('PHPGW_E_INFO', -512);

/**
 * phpGroupWare debug level "error"
 */
define('PHPGW_E_DEBUG', -1024);

/**
 * Class MyErrorHandler
 * @package App\helpers
 */


class ErrorHandler
{
	protected $responseFactory;
	private $serverSettings;
	private $userSettings;
	private $Log;
	private $db;
	
	private $path;

	/**
	 * ErrorHandler constructor.
	 * @param ResponseFactoryInterface $responseFactory
	 */
	public function __construct(ResponseFactoryInterface $responseFactory)
	{
		$this->responseFactory = $responseFactory;
		$this->serverSettings  = Settings::getInstance()->get('server');
		$this->userSettings  = Settings::getInstance()->get('user');
		$this->Log = new Log();
		$this->db = Db::getInstance();

		set_error_handler([$this, 'phpgw_handle_error']);
	}

	/**
	 * @param Request $request
	 * @param Throwable $exception
	 * @param bool $displayErrorDetails
	 * @return Response
	 */
	public function __invoke(Request $request, Throwable $exception, bool $displayErrorDetails): Response
	{
		//Catch the user
		Sessions::getInstance()->verify();

		$path = $request->getUri()->getPath();
		$this->path = $path;
		$routePath_arr = explode('/', $path);
		$currentApp = $routePath_arr[1];
		$flags = [
			'currentapp' => $currentApp
		];

		if (empty(Settings::getInstance()->get('flags')['currentapp']))
		{
			Settings::getInstance()->set('flags', $flags);
		}

		if ($exception instanceof ErrorException && $exception->getSeverity() === E_USER_ERROR)
		{
			// Handle user errors
			$this->phpgw_handle_error($exception->getSeverity(), $exception->getMessage(), $exception->getFile(), $exception->getLine());
			// create a response, set the status code, and write the response body
			$response = $this->responseFactory->createResponse();
			$response->getBody()->write("A user error occurred!");
		}
		else
		{
			// Handle other exceptions
			$this->phpgw_handle_exception($exception);
			$response = $this->responseFactory->createResponse();
			$response->getBody()->write("An exception occurred!");
		}

		return $response;
	}

	/**
	 * @param int $error_level
	 * @param string $error_msg
	 * @param string $error_file
	 * @param int $error_line
	 * @return bool
	 */
	function phpgw_handle_error($error_level, $error_msg, $error_file, $error_line)
	{
		if (!(error_reporting() & $error_level)) // 0 == @function() so we ignore it, as the dev requested
		{
			return true;
		}

		$path = $this->path;
		if (isset($this->serverSettings['log_levels']['global_level']))
		{
			switch ($this->serverSettings['log_levels']['global_level'])
			{
				case 'F': // Fatal
				case 'E': // Error
					$error_reporting = E_ERROR | E_USER_ERROR | E_PARSE;
					break;

				case 'W': // Warn
				case 'I': // Info
					$error_reporting = E_ERROR | E_USER_ERROR | E_WARNING | E_USER_WARNING | E_PARSE;
					break;

				case 'N': // Notice
				case 'D': // Debug
					$error_reporting = E_ERROR | E_USER_ERROR | E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE | E_PARSE;
					break;

				case 'S': // Strict
					$error_reporting = E_STRICT | E_PARSE;
					break;

				case 'DP': // Deprecated
					$error_reporting = E_ERROR | E_USER_ERROR | E_DEPRECATED | E_USER_DEPRECATED;
					break;
				case 'A': // All
					$error_reporting = E_ALL;
					break;
			}

			if (!(!!($error_reporting & $error_level)))
			{
				return true;
			}
		}

		$log = $this->Log;

		if (!isset($this->userSettings['apps']['admin']))
		{
			$error_file = str_replace(SRC_ROOT_PATH, '/path/to/portico', $error_file);
		}

		$bt = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS);

		$IP_address = Sanitizer::get_ip_address(false);

		$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
		$referer = str_replace('?', "\n?", $referer);
		$referer = str_replace('&', "\n&", $referer);
		$parametres = $_GET;
		$parametres = print_r($parametres, true);
		$trace = "IP_address: {$IP_address}</b>\n Referer: {$referer} </b>\nParameters: {$parametres}</b>\nPath: {$path}</b>\n" . $this->phpgw_parse_backtrace($bt);

		$log_args = array(
			'file'	=> $error_file,
			'line'	=> $error_line,
			'text'	=> "$error_msg\n" . $trace
		);
		$message = '';
		switch ($error_level)
		{
			case E_USER_ERROR:
			case E_ERROR:
				$log_args['severity'] = 'F'; //all "ERRORS" should be fatal
				$log->fatal($log_args);
				if (ini_get('display_errors'))
				{
					echo '<p class="msg">' . lang('ERROR: %1 in %2 at line %3', $error_msg, $error_file, $error_line) . "</p>\n";
					die('<pre>' . $trace . "</pre>\n");
				}
				else
				{
					die('Error');
				}
			case E_WARNING:
			case E_USER_WARNING:
				$log_args['severity'] = 'W';
				$log->warn($log_args);
				$message .= '<p class="msg">' . lang('Warning: %1 in %2 at line %3', $error_msg, $error_file, $error_line) . "</p>\n";
				$message .= '<pre>' . $trace . "</pre>\n";
				break;

			case PHPGW_E_INFO:
				$log_args['severity'] = 'I';
				$log->info($log_args);
				break;

			case PHPGW_E_DEBUG:
				$log_args['severity'] = 'D';
				$log->info($log_args);
				break;

			case E_NOTICE:
			case E_USER_NOTICE:
				$log_args['severity'] = 'N';
				$log->notice($log_args);
				if (isset($this->serverSettings['log_levels']['global_level']) && $this->serverSettings['log_levels']['global_level'] == 'N')
				{
					$message .=  '<p>' . lang('Notice: %1 in %2 at line %3', $error_msg, $error_file, $error_line) . "</p>\n";
					$message .=  '<pre>' . $trace . "</pre>\n";
				}
				break;

			case E_DEPRECATED:
			case E_USER_DEPRECATED:
				$log_args['severity'] = 'DP';
				$log->deprecated($log_args);
				$message .=  '<p class="msg">' . lang('deprecated: %1 in %2 at line %3', $error_msg, $error_file, $error_line) . "</p>\n";
				$message .=  '<pre>' . $trace . "</pre>\n";
				break;
		}

		if (ini_get('display_errors'))
		{
			echo $message;
		}
	}

	/**
	 * cleans up a backtrace array and converts it to a string
	 *
	 * @internal this is such an ugly piece of code due to a reference to the error context
	 * being in the backtrace and the error context can not be edited - see php.net/set_error_handler
	 * @param array $bt php backtrace
	 * @return string the formatted backtrace, empty if the user is not an admin
	 */
	function phpgw_parse_backtrace($bt)
	{
		if (!is_array($bt))
		{
			return '';
		}

		// we don't need the call to the error handler
		unset($bt[0]);

		$trace = '&nbsp;';
		$i = 0;
		foreach ($bt as $entry)
		{
			$line = "#{$i}\t";

			if (isset($entry['type']) && isset($entry['class']))
			{
				$line .= "{$entry['class']}{$entry['type']}{$entry['function']}";
			}
			else
			{
				$line .= $entry['function'];
			}

			$line .= '(';

			if (isset($entry['args']) && is_array($entry['args']) && count($entry['args']))
			{
				$args_count = count($entry['args']);
				foreach ($entry['args'] as $anum => $arg)
				{
					if (is_array($arg))
					{
						$line .= 'serialized_value = ' . json_encode($arg, JSON_PRETTY_PRINT);
						continue;
					}

					// Drop passwords from backtrace
					if (
						isset($this->serverSettings['header_admin_password']) && $arg == $this->serverSettings['header_admin_password']
						|| (isset($this->serverSettings['db_pass']) && $arg == $this->serverSettings['db_pass'])
						|| (isset($this->userSettings['passwd']) && $arg == $this->userSettings['passwd'])
					)
					{
						$line .= '***REMOVED_FOR_SECURITY***';
					}
					else if (is_object($arg))
					{
						continue;
					}
					else
					{
						$line .= $arg;
					}

					if (($anum + 1) != $args_count)
					{
						$line .= ', ';
					}
				}
			}

			$file = 'unknown';
			if (isset($entry['file']))
			{
				if (!isset($this->userSettings['apps']['admin']))
				{
					$file = '/path/to/portico/' . substr($entry['file'], strlen(SRC_ROOT_PATH));
				}
				else
				{
					$file = $entry['file'];
				}
			}

			if (isset($entry['line']))
			{	//	$bt = array_reverse($bt);

			}

			$line .= ") [$file]";
			$trace .= "$line\n";
			++$i;
		}

		return print_r($trace, true);
	}

	/**
	 * Last resort exception handler
	 *
	 * @param object $e the Exception that was thrown
	 * @param string $path the path where the error occurred
	 */
	function phpgw_handle_exception($e)
	{
		
		$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
		$referer = str_replace('?', "\n?", $referer);
		$referer = str_replace('&', "\n&", $referer);
		$path = $this->path;
		$IP_address = Sanitizer::get_ip_address(false);
		$parametres = $_GET;
		$parametres = print_r($parametres, true);
		$trace = "IP_address: {$IP_address}</b>\nReferer: {$referer} </b>\nPath: {$path}</b>\nParameters: {$parametres}</b>\n" . $e->getTraceAsString();
		
		$tables = $this->db->table_names();
		if (in_array('phpgw_log', $tables))
		{
			$log = $this->Log;

			if ($this->db->get_transaction())
			{
				$this->db->transaction_abort();
			}

			$log->fatal(array(
				'text'	=> "<b>Uncaught Exception:</b>\n" . $e->getMessage() . "\n" . $trace,
				'line'	=> $e->getline(),
				'file'	=> $e->getfile()
			));
		}

		$userLang = isset($this->userSettings['preferences']['common']['lang'])
			? $this->userSettings['preferences']['common']['lang']
			: $this->serverSettings['default_lang'];

		/**
		 * Friendly message.. in norwegian at least..
		 */
		switch ($userLang)
		{
			case 'no':
			case 'nn':
				$error_header = 'Der kom du over en feil';
				$error_msg = 'Feilen er logget til databasen';
				$help = 'Ta kontakt med brukerstøtte for å få hjelp.';
				break;

			default:
				$error_header = 'Uncaught Exception';
				$error_msg = 'Error is logged';
				$help = 'Please contact your administrator for assistance.';
				break;
		}

		if (!ini_get('display_errors'))
		{
			echo <<<HTML
				<h1>{$error_header}</h1>
				<p>{$help}</p>
				<p>{$error_msg}</p>
HTML;
			exit;
		}

		$msg = $e->getMessage();
		$trace = $trace;
		echo <<<HTML
			<h1>{$error_header}:</h1>
			<strong>{$msg}</strong>
			<p>{$help}</p>
			<p>{$error_msg}</p>
			<h2>Backtrace:</h2>
			<pre>
{$trace}
			</pre>

HTML;
		// all exceptions that make it this far are fatal
		exit;
	}
}
