<?php

declare(strict_types=1);

use FunkyOz\EventSource\Examples\Chat\ChatStream;

require __DIR__ . '/../bootstrap.php';

(new ChatStream(exampleDatabase()))->create()->send();
