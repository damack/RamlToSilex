<?php

namespace Damack\RamlToSilex;

use Raml\Parser;
use Silex\Application;
use Silex\ServiceProviderInterface;

class RamlToSilexServiceProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
        $app['ramlToSilex.initializer'] = $app->protect(function () use ($app) {
            if (!is_readable($ramlFile = $app['ramlToSilex.raml_file'])) {
                throw new \RuntimeException("API config file is not readable");
            }

            $app['ramlToSilex.apiDefinition'] = (new Parser())->parse($ramlFile, false);
            $app['ramlToSilex.routes'] = $app['ramlToSilex.apiDefinition']->getResourcesAsUri()->getRoutes();
            $app['ramlToSilex.routeAccess'] = json_decode(file_get_contents($app['ramlToSilex.config_file']))->routeAccess;

            $app['ramlToSilex.restController'] = $app->share(function () use ($app) {
                return new RestController($app);
            });

            $app['ramlToSilex.routeBuilder'] = $app->share(function () {
                return new RouteBuilder();
            });
        });

        $app['ramlToSilex.builder'] = function () use ($app) {
            $app['ramlToSilex.initializer']();

            $controllers = $app['ramlToSilex.routeBuilder']->build($app, 'ramlToSilex.restController');
            $app['controllers']->mount('', $controllers);
        };
    }

    public function boot(Application $app)
    {
        $app['ramlToSilex.builder'];
    }
}
