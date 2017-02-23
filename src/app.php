<?php

$app['debug'] = true;
$app['charset'] = "iso-8859-1";
//$app['charset'] = "utf-8";

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
            'driver'    => 'pdo_mysql',
	        'host'      => 'localhost',
	        'dbname'    => 'brigadeirodelicia',
	        'user'      => 'root',
	        'password'  => '',
        ),
));

$app->register(new Silex\Provider\SwiftmailerServiceProvider());

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__ . '/views',
    'twig.options'=>array(
        'cache'     => __DIR__.'/../cache',
    ),
    'twig.form.templates' => array(
        'form_div_layout.html.twig', 
        'theme/form_div_layout.twig'
    ),
));

$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => __DIR__.'/../logs/buckshot.log',
    'monolog.level' => Monolog\Logger::DEBUG,
    'monolog.name' => 'buckshot'
));

$app->register(new Silex\Provider\SessionServiceProvider());

return $app;
