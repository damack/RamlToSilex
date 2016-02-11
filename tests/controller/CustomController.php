<?php
namespace Damack\Custom;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class CustomController
{
    protected $dbal;

    public function __construct($app) {
        $this->dbal = $app['dbs'];
    }

    public function testAction($objectType, Request $request) {
        $tenant = $request->headers->get('Tenant');
        $token = $request->headers->get('Authorization') ? $request->headers->get('Authorization') : getallheaders()['Authorization'];
        $token = str_replace('Bearer ', '', $token);

        $queryBuilder = $this->dbal[$tenant]->createQueryBuilder();
        $query = $queryBuilder
            ->select('*')
            ->from($objectType)
            ->where('token = '.$queryBuilder->createPositionalParameter($token))
        ;
        $result = $query->execute()->fetchObject();
        return new JsonResponse($result, 200);
    }
}
?>
