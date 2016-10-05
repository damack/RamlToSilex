<?php
namespace Damack\Custom;

use Silex\Application;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class CustomController
{
    protected $app;
    protected $client;

    public function __construct($app) {
        $this->client = new Client(['base_uri' => 'https://api.yaas.io/']);
        $this->app = $app;
    }

    public function testAction($objectType, Request $request) {
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
            return new JsonResponse(json_decode($res->getBody()->getContents())[0], 200);
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
}
?>
