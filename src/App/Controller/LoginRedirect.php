<?php

namespace App\Controller;

use Silex\Application;
use Silex\ControllerProviderInterface;

class LoginRedirect implements ControllerProviderInterface {

    public function connect(Application $app) {
        $controller = $app['controllers_factory'];
        $controller->get('/', array($this, 'index'))->bind('cliente-login-redirect');
        return $controller;
    }

    public function index(Application $app) {

        if (($app['security.authorization_checker']->isGranted('ROLE_CUSTOMER'))) {
            $referer = $app['session']->get('old-referer');
            $app['monolog']->addDebug("Teste: " . $referer);
            if($referer != null) {
                $app['session']->remove('old-referer');
                return $app->redirect($referer);
            } else
                return $app->redirect($app['url_generator']->generate('cliente'));
        }

        return $app->redirect('/novo-cliente');
    }

}
