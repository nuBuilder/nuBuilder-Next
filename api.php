<?php
declare(strict_types=1);

/*
 * nuBuilder Next REST API – api.php/v1/… (see core/nuapi.php and the help, chapter "REST API").
 * Off unless $nuConfigApi = true in nuconfig.php.
 */
require __DIR__ . '/core/nuapp.php';
NuApi::handle();
