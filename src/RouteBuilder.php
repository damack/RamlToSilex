<?php

namespace Damack\RamlToSilex;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use GuzzleHttp\Client;

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

        $afterMiddleware = function (Request $request, Response $response) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'OPTIONS,GET,POST,PUT,DELETE');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, accept, authorization, tenant');
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
                ->before($beforeMiddleware)
                ->after($afterMiddleware);

            $controllers
                ->match($route['path'], function() { return new Response('', 200); })
                ->method('OPTIONS')
                ->after($afterMiddleware);
        }

        if ($app->get('ramlToSilex.apiConsole')) {
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

            $client = new Client(['base_uri' => 'https://api.yaas.io/']);
            $res = $client->get('/hybris/document/v1/'.$tenant.'/'.$app['ramlToSilex.yaas-client'].'/data/users', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->getToken($app, $client, $tenant)
                ],
                'query' => [
                    'q' => 'token:'.$token
                ]
            ]);
            $results = json_decode($res->getBody());

            if ($results && strpos($app['ramlToSilex.routeAccess']->{$routeName}, $results[0]->role) !== false) {
                return true;
            } else {
                return false;
            }
        } else {
            return true;
        }
    }

    private function getToken($app, $client, $tenant) {
        if(null === $token = $app['session']->get('token'.$tenant)) {
            $response = $client->post('/hybris/oauth2/v1/token', [
                'form_params' => [
                    'client_id' => $app['ramlToSilex.yaas-client-id'],
                    'client_secret' => $app['ramlToSilex.yaas-client-secret'],
                    'grant_type' => 'client_credentials',
                    'scope' => 'hybris.tenant='.$tenant.' hybris.document_view hybris.document_manage'
                ]
            ]);
            $token = json_decode($response->getBody())->{'access_token'};
            $app['session']->set('token'.$tenant, $token);
            return $token;
        }
        return $token;
    }
}
