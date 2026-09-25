<?php

/**
 * Router script for PHP's built-in server, started by `freezed-desk serve`.
 * Every request goes through the App; static files of the UI theme are
 * served by the App as well, so project overlays work.
 */
$autoload = getenv('DESK_AUTOLOAD');
if (!$autoload || !is_file($autoload)) {
    http_response_code(500);
    echo 'Desk: DESK_AUTOLOAD is not set. Start the UI with freezed-desk serve.';
    return true;
}
require $autoload;

$options = json_decode((string) getenv('DESK_OPTIONS'), true);

return \Neuedaten\FreezedDesk\Web\App::serve(is_array($options) ? $options : []);
