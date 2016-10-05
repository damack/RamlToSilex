<?php

namespace Damack\RamlToSilex;

use Silex\Application;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use League\OAuth2\Client\Provider\Google;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RestController
{
    protected $provider;
    protected $app;
    protected $client;

    public function __construct(Application $app)
    {
        $this->provider = new Google([
            'clientId'     => $app['ramlToSilex.google-app-id'],
            'clientSecret' => $app['ramlToSilex.google-app-secret'],
            'redirectUri'  => $app['ramlToSilex.google-redirect-uri']
        ]);
        $this->client = new Client(['base_uri' => 'https://api.yaas.io/']);
        $this->app = $app;
    }

    public function getListAction($objectType, Request $request)
    {
        $tenant = $request->headers->get('Tenant');
        $q = $request->query->get('q');
        $sort = $request->query->get('sort');
        $page = $request->query->get('page', 1);
        $size = $request->query->get('size', 20);

        if(null === $cache = $this->app['session']->get($tenant.$page.$size.$q)) {
            $headers = [
                'Authorization' => 'Bearer '.$this->getToken($tenant)
            ];
        } else {
            $headers = [
                'Authorization' => 'Bearer '.$this->getToken($tenant),
                'If-None-Match' => $cache['ETag']
            ];
        }

        try {
            $res = $this->client->get('/hybris/document/v1/'.$tenant.'/'.$this->app['ramlToSilex.yaas-client'].'/data/'.$objectType, [
                'headers' => $headers,
                'query' => [
                    'pageNumber' => $page,
                    'pageSize' => $size,
                    'q' => $q,
                    'totalCount' => true
                ]
            ]);

            if($res->getStatusCode() === 304) {
                return new JsonResponse($cache['data'], 304, array(
                    'X-Total-Count' => $cache['count'],
                ));
            } else {
                $results = json_decode($res->getBody());
                foreach($results as &$obj) {
                    $this->removeHiddenFields($objectType, $obj);
                }

                $this->app['session']->set($tenant.$page.$size.$q, [
                    'ETag' => $res->getHeader('ETag'),
                    'data' => $results,
                    'count' => $res->getHeader('hybris-count')
                ]);
                return new JsonResponse($results, 200, array(
                    'X-Total-Count' => $res->getHeader('hybris-count'),
                ));
            }
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                return new JsonResponse(json_decode($response->getBody()->getContents()), $response->getStatusCode());
            } else {
                return new JsonResponse(json_decode('{"status":500,"message":"No connection to db"}'), 500);
            }
        }
    }

    public function postListAction($objectType, Request $request)
    {
        $tenant = $request->headers->get('Tenant');
        try {
            $response = $this->client->post('/hybris/document/v1/'.$tenant.'/'.$this->app['ramlToSilex.yaas-client'].'/data/'.$objectType, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->getToken($tenant),
                    'Content-Type' => 'application/json'
                ],
                'body' => $request->getContent()
            ]);
            $id = json_decode($response->getBody()->getContents())->id;
            return new JsonResponse(json_decode('{"id":"'.$id.'"}'), 201);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                return new JsonResponse(json_decode($response->getBody()->getContents()), $response->getStatusCode());
            } else {
                return new JsonResponse(json_decode('{"status":500,"message":"No connection to db"}'), 500);
            }
        }
    }

    public function getObjectAction($objectId, $objectType, Request $request)
    {
        $tenant = $request->headers->get('Tenant');

        try {
            $res = $this->client->get('/hybris/document/v1/'.$tenant.'/'.$this->app['ramlToSilex.yaas-client'].'/data/'.$objectType.'/'.$objectId, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->getToken($tenant)
                ]
            ]);
            $result = json_decode($res->getBody()->getContents());
            $this->removeHiddenFields($objectType, $result);

            return new JsonResponse($result, 200);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                return new JsonResponse(json_decode($response->getBody()->getContents()), $response->getStatusCode());
            } else {
                return new JsonResponse(json_decode('{"status":500,"message":"No connection to db"}'), 500);
            }
        }
    }

    public function putObjectAction($objectId, $objectType, Request $request)
    {
        $tenant = $request->headers->get('Tenant');
        $data = json_decode($request->getContent());
        unset($data->id);

        try {
            $response = $this->client->put('/hybris/document/v1/'.$tenant.'/'.$this->app['ramlToSilex.yaas-client'].'/data/'.$objectType.'/'.$objectId, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->getToken($tenant),
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode($data),
                'query' => [
                    'patch' => true
                ]
            ]);
            return new Response('', 200);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                return new JsonResponse(json_decode($response->getBody()->getContents()), $response->getStatusCode());
            } else {
                return new JsonResponse(json_decode('{"status":500,"message":"No connection to db"}'), 500);
            }
        }
    }

    public function deleteObjectAction($objectId, $objectType, Request $request)
    {
        $tenant = $request->headers->get('Tenant');

        try {
            $response = $this->client->delete('/hybris/document/v1/'.$tenant.'/'.$this->app['ramlToSilex.yaas-client'].'/data/'.$objectType.'/'.$objectId, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->getToken($tenant)
                ]
            ]);
            return new Response('', 204);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                return new JsonResponse(json_decode($response->getBody()->getContents()), $response->getStatusCode());
            } else {
                return new JsonResponse(json_decode('{"status":500,"message":"No connection to db"}'), 500);
            }
        }
    }

    public function getAuthGoogleAction($objectType, Request $request)
    {
        return new RedirectResponse($this->provider->getAuthorizationUrl(['state' => $request->get('tenant')]));
    }

    public function getAuthGooglecallbackAction($objectType, Request $request)
    {
        $tenant = $request->get('state');
        $token = $this->provider->getAccessToken('authorization_code', [
            'code' => $request->get('code')
        ]);
        $gUser = $this->provider->getResourceOwner($token);

        $res = $this->client->get('/hybris/document/v1/'.$tenant.'/'.$this->app['ramlToSilex.yaas-client'].'/data/users', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->getToken($tenant)
            ],
            'query' => [
                'q' => 'mail:'.$gUser->getEmail(),
                'totalCount' => true
            ]
        ]);

        if ($res->getHeader('hybris-count') === 0) {
            $this->client->post('/hybris/document/v1/'.$tenant.'/'.$this->app['ramlToSilex.yaas-client'].'/data/'.$objectType, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->getToken($tenant),
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    "name" => $gUser->getName(),
                    "mail" => $gUser->getEmail(),
                    "token" => $token
                ]
            ]);
        } else if (json_decode($res->getBody()->getContents())[0]->token !== $token) {
            $user = json_decode($res->getBody()->getContents())[0];
            $this->client->put('/hybris/document/v1/'.$tenant.'/'.$this->app['ramlToSilex.yaas-client'].'/data/'.$objectType.'/'.$user->id, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->getToken($tenant),
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'token' => $user->token
                ],
                'query' => [
                    'patch' => true
                ]
            ]);

            $this->dbal[$tenant]->update($objectType, array(
                "token" => $token
            ), array('id' => $user['id']));
        }
        return new RedirectResponse($this->app['ramlToSilex.redirectUri'].'?access_token='.$token);
    }

    public function getAuthGooglemeAction($objectType, Request $request) {
        $tenant = $request->headers->get('Tenant');
        $token = $request->headers->get('Authorization') ? $request->headers->get('Authorization') : getallheaders()['Authorization'];
        $token = str_replace('Bearer ', '', $token);

        try {
            $res = $this->client->get('/hybris/document/v1/'.$tenant.'/'.$this->app['ramlToSilex.yaas-client'].'/data/users', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->getToken($tenant)
                ],
                'query' => [
                    'q' => 'token:'.$token
                ]
            ]);
            $results = json_decode($res->getBody());
            foreach($results as &$obj) {
                $this->removeHiddenFields($objectType, $obj);
            }

            return new JsonResponse($results[0], 200);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                return new JsonResponse(json_decode($response->getBody()->getContents()), $response->getStatusCode());
            } else {
                return new JsonResponse(json_decode('{"status":500,"message":"No connection to db"}'), 500);
            }
        }
    }

    private function getToken($tenant) {
        if(null === $token = $this->app['session']->get('token'.$tenant)) {
            $response = $this->client->post('/hybris/oauth2/v1/token', [
                'form_params' => [
                    'client_id' => $this->app['ramlToSilex.yaas-client-id'],
                    'client_secret' => $this->app['ramlToSilex.yaas-client-secret'],
                    'grant_type' => 'client_credentials',
                    'scope' => 'hybris.tenant='.$tenant.' hybris.document_view hybris.document_manage'
                ]
            ]);
            $token = json_decode($response->getBody())->{'access_token'};
            $this->app['session']->set('token'.$tenant, $token);
            return $token;
        }
        return $token;
    }

    private function removeHiddenFields($schemaName, &$obj) {
        $apiDefinition = $this->app['ramlToSilex.apiDefinition'];
        foreach ($apiDefinition->getSchemaCollections() as $schema) {
            if (key($schema) == $schemaName) {
                $schemaDef = json_decode($schema[$schemaName]);
                foreach($schemaDef->items->properties as $key => $value) {
                    if (property_exists($value,'hidden') && $value->hidden === true) {
                        unset($obj->$key);
                    }
                }
            }
        }
    }
}
