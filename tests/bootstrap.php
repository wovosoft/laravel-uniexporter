<?php

/*
 * Runs the package tests against the host application's vendor directory without the host
 * having to require the package: the package and test namespaces are registered here.
 * A standalone checkout can point UNIEXPORTER_AUTOLOAD at its own vendor/autoload.php.
 */

$autoload = getenv('UNIEXPORTER_AUTOLOAD') ?: dirname(__DIR__, 4).'/vendor/autoload.php';

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $autoload;

$loader->addPsr4('Wovosoft\\LaravelUniexporter\\', dirname(__DIR__).'/src/', true);
$loader->addPsr4('Wovosoft\\LaravelUniexporter\\Tests\\', __DIR__.'/', true);
