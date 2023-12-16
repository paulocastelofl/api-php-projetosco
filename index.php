<?php

use app\core\DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();
$app->addRoutingMiddleware();

$app->addBodyParsingMiddleware();

/**
 * Add Error Middleware
 *
 * @param bool                  $displayErrorDetails -> Should be set to false in production
 * @param bool                  $logErrors -> Parameter is passed to the default ErrorHandler
 * @param bool                  $logErrorDetails -> Display error details in error log
 * @param LoggerInterface|null  $logger -> Optional PSR-3 Logger  
 *
 * Note: This middleware should be added last. It will not handle any exceptions/errors
 * for middleware added after it.
 */
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$app->add(new Tuupola\Middleware\CorsMiddleware([
    "origin" => ["*"],
    "methods" => ["GET", "POST", "PUT", "PATCH", "DELETE"],
    "headers.allow" => ["Authorization", "If-Match", "If-Unmodified-Since", "Content-Type", "Accept"],
    "headers.expose" => ["Etag"],
    "credentials" => false,
    "cache" => 86400
]));

$app->group('/api', function (RouteCollectorProxy $group) {
    // Define app routes
    $group->get('/persons', function ($request, $response, array $args) {
        // Define app routes
        $currentDate = date('Y-m-d');
        $q = "";
        $dateInit = '1992-01-01 00:00:00';
        $dateEnd = $currentDate . ' 23:59:00';
        $params = $request->getQueryParams();
        if (!empty($params)) {
            if (array_key_exists('q', $params)) $q = $params['q'];
            if (array_key_exists('dateInit', $params) && array_key_exists('dateEnd', $params)) {
                $dateInit = $params['dateInit'];
                $dateEnd = $params['dateEnd'];
            }
        }

        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $limit = isset($params['limit']) ? max(1, min(100, (int)$params['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $sql = "SELECT Id, Name, CreatedAt
                FROM DbSiswac.Persons 
                WHERE 
                CreatedAt BETWEEN '" . $dateInit . "' AND '" . $dateEnd . "'
                AND Name LIKE '%" . $q . "%'
                LIMIT ". $limit ." OFFSET ". $offset .";";

        try {

            $db = new Db();
            $conn = $db->connect();

            $sqlCount = "SELECT count(*) FROM DbSiswac.Persons"; 
            $res = $conn->query($sqlCount);
            $count = $res->fetchColumn();

            $stmt = $conn->query($sql);
            $persons = $stmt->fetchAll(PDO::FETCH_OBJ);
            $db = null;

            $data = [
                'data' => $persons, 
                'page' => $page,
                'per' => $limit,
                'total' => $count 
            ];

            $response->getBody()->write(json_encode($data ));
            return $response
                ->withHeader('content-type', 'application/json')
                ->withStatus(200);
        } catch (PDOException $e) {
            $error = array(
                "message" => $e->getMessage()
            );

            $response->getBody()->write(json_encode($error));
            return $response
                ->withHeader('content-type', 'application/json')
                ->withStatus(500);
        }
    });
    // Define app routes
    $group->get('/persons/{id}', function (Request $request, Response $response, array $args) {

        $sql = "SELECT Id, Name, CreatedAt, AnswersSelected, Avoiding, Collaborating, Competing, Granting, Reconciling
            FROM DbSiswac.Persons where Id  = " . $args['id'] . " LIMIT 1;";

        try {
            $db = new Db();
            $conn = $db->connect();
            $stmt = $conn->query($sql);
            $persons = $stmt->fetch(PDO::FETCH_OBJ);
            $db = null;

            $response->getBody()->write(json_encode($persons));
            return $response
                ->withHeader('content-type', 'application/json')
                ->withStatus(200);
        } catch (PDOException $e) {
            $error = array(
                "message" => $e->getMessage()
            );

            $response->getBody()->write(json_encode($error));
            return $response
                ->withHeader('content-type', 'application/json')
                ->withStatus(500);
        }
    });
    // Define app routes
    $group->post('/persons', function (Request $request, Response $response, array $args) {

        $data = $request->getParsedBody();

        $name = $data["name"];
        $answers = $data["answers"];

        $categories = [
            ['A' => 'avoiding', 'B' => 'granting'],
            ['A' => 'reconciling', 'B' => 'collaborating'],
            ['A' => 'competing', 'B' => 'granting'],
            ['A' => 'reconciling', 'B' => 'granting'],
            ['A' => 'collaborating', 'B' => 'avoiding'],
            ['A' => 'avoiding', 'B' => 'competing'],
            ['A' => 'avoiding', 'B' => 'reconciling'],
            ['A' => 'competing', 'B' => 'collaborating'],
            ['A' => 'avoiding', 'B' => 'competing'],
            ['A' => 'competing', 'B' => 'granting'],
        ];

        $categoryCounts = [
            'competing' => 0,
            'collaborating' => 0,
            'reconciling' => 0,
            'avoiding' => 0,
            'granting' => 0,
        ];

        $array_answers = $answers;
        if ($array_answers !== null) {
            foreach ($array_answers as $index => $array_answers) {
                $category = $categories[$index][$array_answers];
                $categoryCounts[$category]++;
            }
        } else {
            $error = array(
                "message" => "array_answers not possible be null."
            );

            $response->getBody()->write(json_encode($error));
            return $response
                ->withHeader('content-type', 'application/json')
                ->withStatus(500);
        }

        $sql = "INSERT INTO DbSiswac.Persons
            (Name, CreatedAt, AnswersSelected, Avoiding, Collaborating, Competing, Granting, Reconciling)
            VALUES(:name, curdate(), :answers , 
            " . strval($categoryCounts['avoiding']) . ", 
            " . strval($categoryCounts['collaborating']) . ", 
            " . strval($categoryCounts['competing']) . ", 
            " . strval($categoryCounts['granting']) . ",
            " . strval($categoryCounts['reconciling']) . ");";

        try {
            $db = new Db();
            $conn = $db->connect();

            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':answers', json_encode($answers));

            $result = $stmt->execute();

            $db = null;
            $response->getBody()->write(json_encode($result));

            return $response
                ->withHeader('content-type', 'application/json')
                ->withStatus(200);
        } catch (PDOException $e) {
            $error = array(
                "message" => $e->getMessage()
            );

            $response->getBody()->write(json_encode($error));
            return $response
                ->withHeader('content-type', 'application/json')
                ->withStatus(500);
        }
    });

    $group->get('/answers', function ($request, $response, array $args) {

        $sql = "SELECT ques.Id as QuestionID, ques.Description as DescriptionQuestion, 
        ans.Letter as LetterAnswers, ans.Description as DescriptionAnswers, ans.CreatedAt as CreatedAtAnswers
        FROM DbSiswac.Questions ques 
        inner join DbSiswac.Answers ans on ans.QuestionID = ques.Id";

        try {
            $db = new Db();
            $conn = $db->connect();
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $db = null;

            $answers = array();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $answers[] = $row;
            }

            $data = json_decode(json_encode($answers), true);

            $groupedData = [];

            foreach ($data as $item) {
                $questionId = $item['QuestionID'];

                // Se ainda não houver um array para a QuestionID, crie um
                if (!isset($groupedData[$questionId])) {
                    $groupedData[$questionId] = [
                        'QuestionID' => $questionId,
                        'DescriptionQuestion' => $item['DescriptionQuestion'],
                        'Answers' => []
                    ];
                }

                // Adicione a resposta ao array de respostas
                $groupedData[$questionId]['Answers'][] = [
                    'LetterAnswers' => $item['LetterAnswers'],
                    'DescriptionAnswers' => $item['DescriptionAnswers'],
                    'CreatedAtAnswers' => $item['CreatedAtAnswers']
                ];
            }

            $groupedData = array_values($groupedData);
            $response->getBody()->write(json_encode($groupedData));

            return $response
                ->withHeader('content-type', 'application/json')
                ->withStatus(200);
        } catch (PDOException $e) {
            $error = array(
                "message" => $e->getMessage()
            );

            $response->getBody()->write(json_encode($error));
            return $response
                ->withHeader('content-type', 'application/json')
                ->withStatus(500);
        }
    });
});

// Run app
$app->run();

//docker run --name mysql-server -p 3306:3306 -e MYSQL_ROOT_PASSWORD=secret mysql
