# RamlToSilex

RamlToSilex is a Silex provider to setting up a REST API on top of a relational database, based on a YAML (RAML) configuration file.

## What is RAML ?

[RESTful API Modeling Language (RAML)](http://raml.org/) is a simple and sufficient way of describing practically-RESTful APIs. It encourages reuse, enables discovery and pattern-sharing, and aims for merit-based emergence of best practices.

## Installation

To install RamlToSilex library, run the command below and you will get the latest version:

```bash
composer require damack/ramlToSilex
```

Enable `ServiceController`, `Doctrine` and `Microrest` service providers in your application:

```php
$app = new Application();

$app->register(new \Silex\Provider\ServiceControllerServiceProvider());
$app->register(new \Silex\Provider\DoctrineServiceProvider(), array(
    'dbs.options' => array(
        'test' => array(
            'driver' => 'pdo_sqlite',
            'path' => __DIR__ . '/db.sqlite'
        )
    ),
));
$app->register(new Damack\RamlToSilex\RamlToSilexServiceProvider(), array(
    'ramlToSilex.raml_file' => __DIR__ . '/raml/api.raml',
    'ramlToSilex.config_file' => __DIR__ . '/config.json',
    'ramlToSilex.google-app-id' => 'id',
    'ramlToSilex.google-app-secret' => 'secret',
    'ramlToSilex.google-redirect-uri' => 'http://localhost/'
));
```

- You need to give the path to the `RAML` file describing your API. You can find an example into the `tests/raml` directory
- You need to give the path to the config file describing your access to the routs and custom controller
- You need to give the app-id, app-secret and redirect-uri so google auth can work

## Function
- Create table from schema definition
- Goolge OAuth authentication
- API authentication with token
- Role based access to routes
- Hidden fields for get
- Custom controller
- API console

### Missing function
- Input validation

## Tests

Run the tests suite with the following commands:

```bash
composer install
composer test
```

## License

is licensed under the [MIT License](LICENSE)
