<?php

try
{
	$script = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
	$context = stream_context_create(isset($script['tls']['certificate']) ? [
		'ssl' => [
			'local_cert' => $script['tls']['certificate'],
			'local_pk' => $script['tls']['key'],
		],
	] : []);
	$server = stream_socket_server('tcp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
	if ($server === false)
	{
		throw new RuntimeException('Cannot start loopback server: ' . $message);
	}
	echo stream_socket_get_name($server, false) . "\n";
	flush();
	$client = stream_socket_accept($server, 5);
	if ($client === false)
	{
		throw new RuntimeException('No client connected within five seconds.');
	}
	stream_set_timeout($client, 5);
	if (isset($script['tls']['certificate']))
	{
		$encrypted = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
		if (!empty($script['tls']['expectFailure']))
		{
			// TLS 1.3 may complete server-side before the client's certificate rejection arrives.
			if ($encrypted !== false && @fgets($client) !== false)
			{
				throw new RuntimeException('Received application data after expected certificate rejection.');
			}
			if (stream_get_meta_data($client)['timed_out'])
			{
				throw new RuntimeException('Timed out waiting for certificate rejection.');
			}
			fclose($client);
			fclose($server);
			echo "OK\n";
			exit(0);
		}
		if ($encrypted !== true)
		{
			throw new RuntimeException('TLS handshake failed unexpectedly.');
		}
	}
	$write = static function (string $response) use ($client): void
	{
		while ($response !== '')
		{
			$written = fwrite($client, $response);
			if ($written === false || $written === 0)
			{
				throw new RuntimeException('Cannot write scripted response.');
			}
			$response = substr($response, $written);
		}
	};
	$write("+OK scripted POP3 server ready\r\n");
	foreach ($script['steps'] as [$command, $response])
	{
		$actual = fgets($client);
		if ($actual !== $command . "\r\n")
		{
			throw new RuntimeException('Unexpected command: ' . var_export($actual, true)
				. '; expected ' . $command);
		}
		$write($response);
	}
	if ($script['expectDisconnect'])
	{
		$extra = fgets($client);
		if ($extra !== false || !feof($client))
		{
			throw new RuntimeException('Expected client disconnect without further commands; received '
				. var_export($extra, true));
		}
	}
	fclose($client);
	fclose($server);
	echo "OK\n";
}
catch (Throwable $exception)
{
	fwrite(STDERR, $exception->getMessage() . "\n");
	exit(1);
}
