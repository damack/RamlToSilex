<?php

namespace Damack\RamlToSilex;

use Silex\Application;
use League\OAuth2\Client\Provider\Google;
use Doctrine\DBAL\Schema\Schema;
use Pagerfanta\Adapter\DoctrineDbalAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use PDO;

class RestController
{
    protected $dbal;
    protected $provider;
    protected $app;

    public function __construct(Application $app)
    {
        $this->dbal = $app['dbs'];
        $this->provider = new Google([
            'clientId'     => $app['ramlToSilex.google-app-id'],
            'clientSecret' => $app['ramlToSilex.google-app-secret'],
            'redirectUri'  => $app['ramlToSilex.google-redirect-uri']
        ]);
        $this->app = $app;
    }

    public function getListAction($objectType, Request $request)
    {
        $tenant = $request->headers->get('Tenant');
        $foreignKeys = $this->getForeignKeys($request->attributes);
        $this->createTable($tenant, $objectType, $foreignKeys);
        $queryBuilder = $this->dbal[$tenant]
            ->createQueryBuilder()
            ->select('o.*')
            ->from($objectType, 'o')
        ;

        $q = $request->query->get('q');
        $where = $this->createWhere($queryBuilder, $q, $foreignKeys);
        if ($where) {
            $queryBuilder->where($where);
        }
        if ($sort = $request->query->get('sort')) {
            $queryBuilder->orderBy(explode(',', $sort)[0], explode(',', $sort)[1]);
        }

        $countQueryBuilderModifier = function ($queryBuilder) {
            $queryBuilder
                ->select('COUNT(DISTINCT o.id) AS total_results')
                ->setMaxResults(1)
            ;
        };

        $pager = new DoctrineDbalAdapter($queryBuilder, $countQueryBuilderModifier);

        $nbResults = $pager->getNbResults();
        $page = $request->query->get('page', 1);
        $size = $request->query->get('size', 20);
        $results = $pager->getSlice(($page - 1) * $size, $page * $size);

        foreach($results as &$obj) {
            $this->removeHiddenFields($objectType, $obj, $foreignKeys);
        }

        return new JsonResponse($results, 200, array(
            'X-Total-Count' => $nbResults,
        ));
    }

    public function postListAction($objectType, Request $request)
    {
        $tenant = $request->headers->get('Tenant');
        $foreignKeys = $this->getForeignKeys($request->attributes);
        $this->createTable($tenant, $objectType, $foreignKeys);
        try {
            $this->dbal[$tenant]->insert($objectType, array_merge($request->request->all(), $foreignKeys));
        } catch (\Exception $e) {
            return new JsonResponse(array(
                'errors' => array('detail' => $e->getMessage()),
            ), 400);
        }

        $id = (integer) $this->dbal[$tenant]->lastInsertId();


        return new JsonResponse(array("id"=>$id), 201);
    }

    public function getObjectAction($objectId, $objectType, Request $request)
    {
        $tenant = $request->headers->get('Tenant');
        $queryBuilder = $this->dbal[$tenant]->createQueryBuilder();
        $query = $queryBuilder
            ->select('*')
            ->from($objectType)
            ->where('id = '.$queryBuilder->createPositionalParameter($objectId))
        ;

        $result = $query->execute()->fetch(PDO::FETCH_ASSOC);;
        if (false === $result) {
            return new Response('', 404);
        }
        $this->removeHiddenFields($objectType, $result, $this->getForeignKeys($request->attributes));

        return new JsonResponse($result, 200);
    }

    public function putObjectAction($objectId, $objectType, Request $request)
    {
        $tenant = $request->headers->get('Tenant');
        $data = $request->request->all();
        $request->request->remove('id');

        $result = $this->dbal[$tenant]->update($objectType, $data, array('id' => $objectId));
        if (0 === $result) {
            return new Response('', 404);
        }

        return new Response('', 200);
    }

    public function deleteObjectAction($objectId, $objectType, Request $request)
    {
        $tenant = $request->headers->get('Tenant');
        $result = $this->dbal[$tenant]->delete($objectType, array('id' => $objectId));
        if (0 === $result) {
            return new Response('', 404);
        }

        return new JsonResponse('', 204);
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

        $this->createTable($tenant, $objectType);
        $user = $this->dbal[$tenant]->fetchAssoc('SELECT * FROM '.$objectType.' WHERE mail = ?', array($gUser->getEmail()));
        if ($user == null) {
            $this->dbal[$tenant]->insert($objectType, array(
                "name" => $gUser->getName(),
                "mail" => $gUser->getEmail(),
                "token" => $token
            ));
        } else if ($user['token'] !== $token) {
            $this->dbal[$tenant]->update($objectType, array(
                "token" => $token
            ), array('id' => $user['id']));
        }
        return new RedirectResponse($app['ramlToSilex.redirectUri'].'?access_token='.$token);
    }

    public function getAuthGooglemeAction($objectType, Request $request) {
        $tenant = $request->headers->get('Tenant');
        $token = $request->headers->get('Authorization') ? $request->headers->get('Authorization') : getallheaders()['Authorization'];
        $token = str_replace('Bearer ', '', $token);

        $queryBuilder = $this->dbal[$tenant]->createQueryBuilder();
        $query = $queryBuilder
            ->select('*')
            ->from($objectType)
            ->where('token = '.$queryBuilder->createPositionalParameter($token));
        $result = $query->execute()->fetch(PDO::FETCH_ASSOC);
        if (0 === $result) {
            return new Response('', 404);
        }

        $this->removeHiddenFields($objectType, $result);
        return new JsonResponse($result, 200);
    }

    private function getForeignKeys($attributes) {
        $return = array();
        foreach($attributes as $key => $value) {
            if(strpos($key, 'Id') && $key !== 'objectId') {
                $return[$key] = $value;
            }
        }
        return $return;
    }

    private function createWhere($queryBuilder, $q, $foreignKeys) {
        $return = '';

        if ($q) {
            $qSplit = explode(',', $q);
            foreach($qSplit as $value) {
                $valueSplit = explode(':', $value);
                if (strlen($return) > 0) {
                    $return .= ',';
                }
                $return .= trim($valueSplit[0]) . "=" . $queryBuilder->createPositionalParameter($valueSplit[1]);
            }
        }
        foreach($foreignKeys as $key => $value) {
            if (strlen($return) > 0) {
                $return .= ',';
            }
            $return .= $key . "=" . $queryBuilder->createPositionalParameter($value);
        }
        return $return;
    }

    private function removeHiddenFields($schemaName, &$obj, $foreignKeys = array()) {
        $apiDefinition = $this->app['ramlToSilex.apiDefinition'];
        foreach ($apiDefinition->getSchemaCollections() as $schema) {
            if (key($schema) == $schemaName) {
                $schemaDef = json_decode($schema[$schemaName]);
                foreach($schemaDef->items->properties as $key => $value) {
                    if (property_exists($value,'hidden') && $value->hidden === true) {
                        unset($obj[$key]);
                    }
                }
            }
        }
        foreach ($foreignKeys as $key => $value) {
            unset($obj[$key]);
        }
    }

    private function createTable($tenant, $name, $foreignKeys = []) {
        $findTable = false;
        $schema = $this->dbal[$tenant]->getSchemaManager();

        $tables = $schema->listTables();
        foreach ($tables as $table) {
            if ($table->getName() == $name) {
                $findTable = true;
                break;
            }
        }

        if ($findTable == false) {
            $apiDefinition = $this->app['ramlToSilex.apiDefinition'];
            $tableSchema = new Schema();

            $table = $tableSchema->createTable($name);
            $table->addColumn("id", "integer", array("unsigned" => true, "autoincrement" => true));
            $table->setPrimaryKey(array("id"));

            foreach ($apiDefinition->getSchemaCollections() as $schema) {
                if (key($schema) == $name) {
                    $schemaDef = json_decode($schema[$name]);
                    foreach($schemaDef->items->properties as $key => $value) {
                        if ($key != "id") {
                            $property = [];

                            if ($schemaDef->items->required && array_search($key, $schemaDef->items->required) != null) {
                                $property["notnull"] = false;
                            }
                            if (property_exists($value, "default")) {
                                $property["default"] = $value->default;
                            }

                            if (property_exists($value, "enum")) {
                                $table->addColumn($key, $value->type, $property);
                            } else {
                                $table->addColumn($key, $value->type, $property);
                            }
                        }
                    }

                    //Add foreign keys
                    foreach($foreignKeys as $key => $value) {
                        $table->addColumn($key, 'integer');
                    }
                }
            }

            $queries = $tableSchema->toSql($this->dbal[$tenant]->getDatabasePlatform());
            foreach ($queries as $query) {
                $this->dbal[$tenant]->query($query);
            }
        }
    }
}
