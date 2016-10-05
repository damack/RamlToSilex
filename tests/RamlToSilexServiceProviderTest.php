<?php
namespace Damack\RamlToSilex;

use Damack\RamlToSilex\RamlToSilexServiceProvider;
use Damack\Custom\CustomController;
use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;

class RamlToSilexServiceProviderTest extends TestCase {
    private $app = null;
    private $tenant = 'testsebastianpro';

    /**
     * @before
     */
    public function createApp() {
        $app = new Application();
        $app['debug'] = true;

        $app->register(new \Silex\Provider\ServiceControllerServiceProvider());
        $app->register(new \Silex\Provider\SessionServiceProvider(), [
            'session.test' => true
        ]);
        $app->register(new RamlToSilexServiceProvider(), array(
            'ramlToSilex.raml_file' => __DIR__ . '/raml/api.raml',
            'ramlToSilex.config_file' => __DIR__ . '/config.json',
            'ramlToSilex.google-app-id' => 'id',
            'ramlToSilex.google-app-secret' => 'secret',
            'ramlToSilex.google-redirect-uri' => 'http://localhost/',
            'ramlToSilex.redirectUri' => 'http://localhost/login.html',
            'ramlToSilex.apiConsole' => false,
            'ramlToSilex.yaas-client' => getenv("CLIENT"),
            'ramlToSilex.yaas-client-id' => getenv("CLIENT_ID"),
            'ramlToSilex.yaas-client-secret' => getenv("CLIENT_SECRET"),
            'ramlToSilex.customController' => function() use ($app) {
                return new CustomController($app);
            }
        ));
        $this->app = $app;

        $client = new Client(['base_uri' => 'https://api.yaas.io/']);
        $response = $client->post('/hybris/oauth2/v1/token', [
            'form_params' => [
                'client_id' => getenv("CLIENT_ID"),
                'client_secret' => getenv("CLIENT_SECRET"),
                'grant_type' => 'client_credentials',
                'scope' => 'hybris.tenant='.$this->tenant.' hybris.document_manage'
            ]
        ]);
        $token = json_decode($response->getBody())->{'access_token'};
        $client->delete('/hybris/document/v1/'.$this->tenant.'/'.getenv("CLIENT").'/data/users', [
            'headers' => [
                'Authorization' => 'Bearer '.$token
            ]
        ]);
        $client->post('/hybris/document/v1/'.$this->tenant.'/'.getenv("CLIENT").'/data/users', [
            'headers' => [
                'Authorization' => 'Bearer '.$token
            ],
            'json' => [
                'id' => 1,
                'name' => "Hans Walter Admin",
                'mail' => "hans.walter@gmail.com",
                'role' => "Admin",
                'token' => "admin",
                'activ' => true,
            ]
        ]);
        $client->post('/hybris/document/v1/'.$this->tenant.'/'.getenv("CLIENT").'/data/users', [
            'headers' => [
                'Authorization' => 'Bearer '.$token
            ],
            'json' => [
                'id' => 2,
                'name' => "Hans Walter Describer",
                'mail' => "hans.walter@gmail.com",
                'role' => "Describer",
                'token' => "describer",
                'activ' => true,
            ]
        ]);
        $client->post('/hybris/document/v1/'.$this->tenant.'/'.getenv("CLIENT").'/data/users', [
            'headers' => [
                'Authorization' => 'Bearer '.$token
            ],
            'json' => [
                'id' => 3,
                'name' => "Hans Walter Anonymous",
                'mail' => "hans.walter@gmail.com",
                'role' => "Anonymous",
                'token' => "anonymous",
                'activ' => true,
            ]
        ]);
    }

    public function testGetList() {
        $request = Request::create('/users', 'GET');
        $request->headers->set('Authorization', 'Bearer admin');
        $request->headers->set('Tenant', $this->tenant);
        $response = $this->app->handle($request);

        $result = json_decode($response->getContent());
        foreach($result as $object) {
            unset($object->metadata);
        }

        $this->assertEquals('[{"name":"Hans Walter Admin","mail":"hans.walter@gmail.com","role":"Admin","activ":true,"id":"1"},{"name":"Hans Walter Describer","mail":"hans.walter@gmail.com","role":"Describer","activ":true,"id":"2"},{"name":"Hans Walter Anonymous","mail":"hans.walter@gmail.com","role":"Anonymous","activ":true,"id":"3"}]', json_encode($result));
    }

    public function testGetListQFilter() {
        $request = Request::create('/users?q=role:Admin', 'GET');
        $request->headers->set('Authorization', 'Bearer admin');
        $request->headers->set('Tenant', $this->tenant);
        $response = $this->app->handle($request);

        $result = json_decode($response->getContent());
        foreach($result as $object) {
            unset($object->metadata);
        }

        $this->assertEquals('[{"name":"Hans Walter Admin","mail":"hans.walter@gmail.com","role":"Admin","activ":true,"id":"1"}]', json_encode($result));
    }

    public function testGetListAnonymous() {
        $request = Request::create('/users', 'GET');
        $request->headers->set('Authorization', 'Bearer anonymous');
        $request->headers->set('Tenant', $this->tenant);
        $response = $this->app->handle($request);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testPost() {
        $request = Request::create('/users', 'POST', array(), array(), array(), array('CONTENT_TYPE' => 'application/json'), '{"name":"Test", "mail":"test@test.de", "token":"test", "role":"Admin", "activ":true}');
        $request->headers->set('Authorization', 'Bearer admin');
        $request->headers->set('Tenant', $this->tenant);
        $response = $this->app->handle($request);

        $this->assertEquals(201, $response->getStatusCode());
    }

    public function testGet() {
        $request = Request::create('/users/3', 'GET');
        $request->headers->set('Authorization', 'Bearer admin');
        $request->headers->set('Tenant', $this->tenant);
        $response = $this->app->handle($request);

        $result = json_decode($response->getContent());
        unset($result->metadata);

        $this->assertEquals('{"name":"Hans Walter Anonymous","mail":"hans.walter@gmail.com","role":"Anonymous","activ":true,"id":"3"}', json_encode($result));
    }

    public function testPut() {
        $request = Request::create('/users/3', 'PUT', array(), array(), array(), array('CONTENT_TYPE' => 'application/json'), '{"name":"Test"}');
        $request->headers->set('Authorization', 'Bearer admin');
        $request->headers->set('Tenant', $this->tenant);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDelete() {
        $request = Request::create('/users/3', 'DELETE');
        $request->headers->set('Authorization', 'Bearer admin');
        $request->headers->set('Tenant', $this->tenant);
        $response = $this->app->handle($request);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testAuthGoogleMe() {
        $request = Request::create('/auth/google/me', 'GET');
        $request->headers->set('Authorization', 'Bearer admin');
        $request->headers->set('Tenant', $this->tenant);
        $response = $this->app->handle($request);

        $result = json_decode($response->getContent());
        unset($result->metadata);

        $this->assertEquals('{"name":"Hans Walter Admin","mail":"hans.walter@gmail.com","role":"Admin","activ":true,"id":"1"}', json_encode($result));
    }

    public function testCustomController() {
        $request = Request::create('/users/2/test', 'PUT');
        $request->headers->set('Authorization', 'Bearer admin');
        $request->headers->set('Tenant', $this->tenant);
        $response = $this->app->handle($request);

        $result = json_decode($response->getContent());
        unset($result->metadata);

        $this->assertEquals('{"name":"Hans Walter Admin","mail":"hans.walter@gmail.com","role":"Admin","token":"admin","activ":true,"id":"1"}', json_encode($result));
    }
}
