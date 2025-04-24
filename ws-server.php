<?php

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require __DIR__ . '/vendor/autoload.php';

class NotificationServer implements MessageComponentInterface
{
	protected $clients;

	public function __construct()
	{
		$this->clients = new \SplObjectStorage;
	}

	public function onOpen(ConnectionInterface $conn)
	{
		$this->clients->attach($conn);
	}

	public function onMessage(ConnectionInterface $from, $msg)
	{
		// Optionally handle messages from clients
	}

	public function onClose(ConnectionInterface $conn)
	{
		$this->clients->detach($conn);
	}

	public function onError(ConnectionInterface $conn, \Exception $e)
	{
		$conn->close();
	}

	public function broadcast($msg)
	{
		foreach ($this->clients as $client)
		{
			$client->send($msg);
		}
	}
}

$notificationServer = new NotificationServer();

$loop = React\EventLoop\Loop::get();

$factory = new Clue\React\Redis\Factory($loop);
$factory->createClient('redis://redis:6379')->then(function (Clue\React\Redis\Client $client) use ($notificationServer)
{
	$client->subscribe('notifications');
	$client->on('message', function ($channel, $payload) use ($notificationServer)
	{
		$notificationServer->broadcast($payload);
	});
});

$webSock = new React\Socket\SocketServer('0.0.0.0:8081', [], $loop);
$webServer = new Ratchet\Server\IoServer(
	new Ratchet\Http\HttpServer(
		new Ratchet\WebSocket\WsServer($notificationServer)
	),
	$webSock,
	$loop
);

$loop->run();
