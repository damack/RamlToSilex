<?php

namespace Damack\RamlToSilex;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RouteBuilder
{
    private static $validMethods = array('GET', 'POST', 'PUT', 'PATCH', 'DELETE');

    public function build(Application $app)
    {
        $controllers = $app['controllers_factory'];
        $routes = $app['ramlToSilex.routes'];

        $availableRoutes = array();
        $beforeMiddleware = function (Request $request, Application $app) {
            if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
                $data = json_decode($request->getContent(), true);
                $request->request->replace(is_array($data) ? $data : array());
            }
            if ($this->checkIfAccessAllowed($request, $app) == false) {
                return new Response('', 401);
            }
        };

        foreach ($routes as $index => $route) {
            $route['method'] = $route['type'];
            $routePath = $route['method'].' '.$route['path'];
            unset($route['type']);

            if (!in_array($route['method'], self::$validMethods)) {
                continue;
            }

            $availableRoutes[] = $index;

            if (preg_match('/auth/', $route['path'])) {
                $route['type'] = str_replace('google', 'Google', str_replace('auth', 'Auth', str_replace('/', '', $route['path'])));
                $route['objectType'] = 'users';
            } else if (preg_match('/{[\w-]+}/', $route['path'], $identifier)) {
                $route['type'] = 'Object';
                $route['objectType'] = strtolower(str_replace(array('/', $identifier[0]), '', $route['path']));
                $route['path'] = str_replace($identifier[0], '{objectId}', $route['path']);
            } else {
                $route['type'] = 'List';
                $route['objectType'] = strtolower(str_replace('/', '', $route['path']));
            }

            $action = 'ramlToSilex.restController:'.strtolower($route['method']).$route['type'].'Action';
            $name = 'ramlToSilex.'.strtolower($route['method']).ucfirst($route['objectType']).$route['type'];

            $controllers
                ->match($route['path'], $action)
                ->method($route['method'])
                ->setDefault('objectType', $route['objectType'])
                ->setDefault('path', $routePath)
                ->bind($name)
                ->before($beforeMiddleware);
        }

        $controllers->match('/', function() use ($app) {
            return $app->redirect('api-console');
        });

        return $controllers;
    }

    private function checkIfAccessAllowed(Request $request, Application $app) {
        $routes = $app['ramlToSilex.apiDefinition']->getResourcesAsUri()->getRoutes();
        $routeName = $request->attributes->get('path');
        if (array_key_exists('Authorization', $routes[$routeName]['method']->getHeaders())) {
            $tenant = $request->headers->get('Tenant');
            $token = $request->headers->get('Authorization') ? $request->headers->get('Authorization') : getallheaders()['Authorization'];
            $token = str_replace('Bearer ', '', $token);

            $queryBuilder = $app['dbs'][$tenant]->createQueryBuilder();
            $query = $queryBuilder
                ->select('*')
                ->from('users')
                ->where('token = '.$queryBuilder->createPositionalParameter($token))
            ;
            $result = $query->execute()->fetchObject();
            if ($result && strpos($app['ramlToSilex.routeAccess']->{$routeName}, $result->role) !== false) {
                return true;
            } else {
                return false;
            }
        } else {
            return true;
        }
    }
}
