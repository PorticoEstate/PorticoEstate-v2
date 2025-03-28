<?php

// Register WebSocket routes
if (file_exists(SRC_ROOT_PATH . '/WebSocket/Routes.php')) {
	$app->getContainer()->get(\App\WebSocket\Routes::class)->register($app);
}

// Register module routes
$d = dir(realpath(SRC_ROOT_PATH . '/modules'));
while ($entry = $d->read()) {
	// skip the . and .. directories
	if ($entry == '.' || $entry == '..') {
		continue;
	}

	if (is_dir(SRC_ROOT_PATH . '/modules/' . $entry . '/routes')) {
		$f = SRC_ROOT_PATH . '/modules/' . $entry . '/routes/Routes.php';

		if (file_exists($f)) {
			require $f;
		}
	}
}
$d->close();
