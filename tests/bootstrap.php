<?php

/*
 * Uses the package's own vendor/ (a standalone checkout after `composer update`), or else the
 * host application's when the package lives in packages/<vendor>/<name> of an application.
 * UNIEXPORTER_AUTOLOAD overrides both. The package and test namespaces are registered here,
 * so the host does not need to require the package.
 */

$autoload = getenv('UNIEXPORTER_AUTOLOAD')
    ?: (is_file(dirname(__DIR__).'/vendor/autoload.php') ? dirname(__DIR__).'/vendor/autoload.php' : dirname(__DIR__, 4).'/vendor/autoload.php');

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $autoload;

$loader->addPsr4('Wovosoft\\LaravelUniexporter\\', dirname(__DIR__).'/src/', true);
$loader->addPsr4('Wovosoft\\LaravelUniexporter\\Tests\\', __DIR__.'/', true);
