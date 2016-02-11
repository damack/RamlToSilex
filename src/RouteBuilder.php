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
            if($app['ramlToSilex.routeAccess']) {
                if ($this->checkIfAccessAllowed($request, $app) == false) {
                    return new Response('', 401);
                }
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
            } else if (preg_match_all('/{[\w-]+}/', $route['path'], $identifier) && strrpos($route['path'], "}") === (strlen($route['path']) - 1)) {
                $route['type'] = 'Object';
                $route['objectType'] = strtolower(str_replace(array('/', $identifier[0][count($identifier[0]) - 1]), '', $route['path']));
                $route['path'] = str_replace($identifier[0][count($identifier[0]) - 1], '{objectId}', $route['path']);

                //object type is last element
                if (strpos($route['objectType'], "}") !== false) {
                    $route['objectType'] = substr(strrchr($route['objectType'], "}"), 1);
                }
            } else {
                $route['type'] = 'List';
                $route['objectType'] = substr($route['path'], strrpos($route['path'], "/") + 1, strlen($route['path']));
            }
            if (property_exists($app['ramlToSilex.customControllerMapping'], $routePath) && $app['ramlToSilex.customControllerMapping']->{$routePath}) {
                $mapping = $app['ramlToSilex.customControllerMapping']->{$routePath};
                $action = $mapping->action;
                $route['objectType'] = $mapping->objectType;
                $route['type'] = 'CustomAction';
            } else {
                $action = 'ramlToSilex.restController:'.strtolower($route['method']).$route['type'].'Action';
            }

            $controllers
                ->match($route['path'], $action)
                ->method($route['method'])
                ->setDefault('objectType', $route['objectType'])
                ->setDefault('path', $routePath)
                ->before($beforeMiddleware);
        }

        if (array_key_exists('ramlToSilex.apiConsole', $app) && $app['ramlToSilex.apiConsole']) {
            $controllers->match('/', function() use ($app) {
                return $app->redirect('vendor/damack/ramltosilex/api-console/');
            });
        }

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
