<?php

declare(strict_types=1);

use App\Application\Middleware\SessionMiddleware;
use Slim\App;
use App\Application\Middleware\APIToolkitMiddleware;

return function (App $app) {
    $app->add(new APIToolkitMiddleware("z6EYf5FMa3gzzNUfgKZsHjtN9GLETNaev7/v0LkNozFQ89nH", debug: true));
    $app->add(SessionMiddleware::class);
};
