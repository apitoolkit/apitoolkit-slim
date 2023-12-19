<?php

namespace APIToolkit;


use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Routing\RouteContext;
use Google\Cloud\PubSub\PubSubClient;
use Exception;
use JsonPath\JsonObject;
use JsonPath\InvalidJsonException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Ramsey\Uuid\Uuid;






class APIToolkitMiddleware
{
  private string $projectId;
  public bool $debug;
  public $pubsubTopic;
  private ?string $serviceVersion;
  private array $tags;
  private array $redactHeaders = [];
  private array $redactRequestBody = [];
  private array $redactResponseBody = [];
  private $logger;
  private array $errors = [];
  public function __construct(
    $apiKey,
    $rootUrl = 'https://app.apitoolkit.io',
    array $redactHeaders = [],
    array $redactRequestBody = [],
    array $redactResponseBody = [],
    $debug = false,
    $serviceVersion = null,
    array $tags = []
  ) {
    $this->redactHeaders = $redactHeaders;
    $this->redactRequestBody = $redactRequestBody;
    $this->redactResponseBody = $redactResponseBody;
    $this->debug = $debug;
    $this->serviceVersion = $serviceVersion;
    $this->tags = $tags;
    $this->logger = new Logger('APIToolkit');
    $this->logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));


    $apitoolkitCredentials = $this->getCredentials($apiKey, $rootUrl);
    $this->projectId = $apitoolkitCredentials["projectId"];
    // TODO: Is it possible to cache this pubsub client and prevent initialization on each request?
    $pubsubClient = new PubSubClient([
      "keyFile" => $apitoolkitCredentials["pubsubKeyFile"]
    ]);
    $this->pubsubTopic = $pubsubClient->topic($apitoolkitCredentials["topic"]);
    if ($this->debug) {
      $this->logger->debug('APIToolkit: Credentials loaded from server correctly');
    }
  }

  public function __invoke(Request $request, RequestHandler $handler): Response
  {
    $newUuid = Uuid::uuid4();
    $msg_id = $newUuid->toString();
    $request = $request->withAttribute('apitoolkitData', [
      'msg_id' => $msg_id,
      'project_id' => $this->projectId,
      "client" => $this,
    ]);
    $startTime = hrtime(true);
    $response = $handler->handle($request);
    $this->log($request, $response, $startTime, $msg_id);
    return $response;
  }

  public static function credentials($url, $api_key)
  {
    if (empty($url)) {
      $url = "https://app.apitoolkit.io";
    }
    $url = $url . "/api/client_metadata";

    $headers = array(
      "Authorization: Bearer $api_key",
    );

    $curlInit = curl_init($url);
    curl_setopt($curlInit, CURLOPT_URL, $url);
    curl_setopt($curlInit, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curlInit, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curlInit, CURLOPT_SSL_VERIFYPEER, false);
    $curlResponse = curl_exec($curlInit);
    $response = json_decode($curlResponse, 1);
    if ($curlResponse == false) {
      curl_error($curlInit);
    }

    curl_close($curlInit);
    return $response;
  }

  public static function getCredentials($APIKey, $url)
  {
    $clientmetadata = self::credentials($url, $APIKey);
    if (!$clientmetadata) {
      throw new InvalidClientMetadataException("Unable to query APIToolkit for client metadata, do you have a correct APIKey? ");
    }

    return [
      "projectId" => $clientmetadata["project_id"],
      "pubsubKeyFile" => $clientmetadata["pubsub_push_service_account"],
      "topic" => $clientmetadata["topic_id"]
    ];
  }

  public function addError($error)
  {
    $this->errors[] = $error;
  }

  public static function observeGuzzle($request, $options)
  {
    $handlerStack = HandlerStack::create();
    $request_info = [];
    $query = "";
    parse_str($request->getUri()->getQuery(), $query);
    $handlerStack->push(GuzzleMiddleware::mapRequest(function ($request) use (&$request_info, $options) {
      $query = "";
      parse_str($request->getUri()->getQuery(), $query);
      $request_info = [
        "start_time" => hrtime(true),
        "method" => $request->getMethod(),
        "raw_url" => $request->getUri()->getPath() . '?' . $request->getUri()->getQuery(),
        "url_no_query" => $request->getUri()->getPath(),
        "url_path" => $options['pathPattern'] ?? $request->getUri()->getPath(),
        "headers" => $request->getHeaders(),
        "body" => $request->getBody()->getContents(),
        "query" => $query,
        "host" => $request->getUri()->getHost(),
      ];
      return $request;
    }));

    $handlerStack->push(GuzzleMiddleware::mapResponse(function ($response) use (&$request_info, $request, $options) {
      $apitoolkit = $request->getAttribute("apitoolkitData");
      $client = $apitoolkit['client'];
      $msg_id = $apitoolkit['msg_id'];
      $projectId = $apitoolkit['project_id'];
      $respBody = $response->getBody()->getContents();
      $payload = [
        'duration' => round(hrtime(true) - $request_info["start_time"]),
        'host' => $request_info["host"],
        'method' => $request_info["method"],
        'project_id' => $projectId,
        'proto_major' => 1,
        'proto_minor' => 1,
        'query_params' => $request_info["query"],
        'path_params' =>  extractPathParams($request_info["url_path"], $request_info["url_no_query"]),
        'raw_url' => $request_info["raw_url"],
        'referer' => "",
        'request_headers' => self::redactHeaderFields($options["redactHeaders"] ?? [], $request_info["headers"]),
        'response_headers' => self::redactHeaderFields($options["redactHeaders"] ?? [], $response->getHeaders()),
        'request_body' => base64_encode(self::redactJSONFields($options["redactRequestBody"] ?? [], $request_info["body"])),
        'response_body' => base64_encode(self::redactJSONFields($options["redactResponseBody"] ?? [], $respBody)),
        'errors' => [],
        'sdk_type' => 'GuzzleOutgoing',
        'parent_id' => $msg_id,
        'status_code' => $response->getStatusCode(),
        'timestamp' => (new \DateTime())->format('c'),
        'url_path' => $request_info["url_path"],
      ];
      $client->publishMessage($payload);
      $newBodyStream = \GuzzleHttp\Psr7\Utils::streamFor($respBody);

      $newResponse = new GuzzleResponse(
        $response->getStatusCode(),
        $response->getHeaders(),
        $newBodyStream,
        $response->getProtocolVersion(),
        $response->getReasonPhrase()
      );
      return $newResponse;
    }));

    $client = new Client(['handler' => $handlerStack]);
    return $client;
  }

  public static function reportError($error, $request)
  {

    $atError = buildError($error);
    $apitoolkit = $request->getAttribute("apitoolkitData");
    $client = $apitoolkit['client'];
    $client->addError($atError);
  }

  public function publishMessage($payload)
  {
    $data = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($this->debug) {
      $this->logger->debug("APIToolkit: payload" . $data);
    }
    $this->pubsubTopic->publish([
      "data" => $data
    ]);
  }

  public function log(Request $request, $response, $startTime, $msg_id)
  {
    if (!$this->pubsubTopic) return;
    $payload = $this->buildPayload($request, $response, $startTime, $this->projectId, $msg_id);
    if ($this->debug) {
      $this->logger->debug("APIToolkit: payload", $payload);
    }
    $this->publishMessage($payload);
  }

  // payload static method deterministically converts a request, response object, a start time and a projectId
  // into a pauload json object which APIToolkit server is able to interprete.
  public function buildPayload(Request $request, $response, $startTime, $projectId, $msg_id)
  {
    $path = $request->getUri()->getPath();
    $query = $request->getUri()->getQuery();
    $routeContext = RouteContext::fromRequest($request);
    $route = $routeContext->getRoute();
    $pattern = $route->getPattern();
    $pathWithQuery = $path . ($query ? '?' . $query : '');
    $pathParams = extractPathParams($pattern, $path);
    $body = $response->getBody();
    $reqBod = $request->getParsedBody();
    return [
      'duration' => round(hrtime(true) - $startTime),
      'host' => $request->getUri()->getAuthority(),
      'method' => $request->getMethod(),
      'project_id' => $projectId,
      'proto_major' => 1,
      'proto_minor' => 1,
      'query_params' => $request->getQueryParams(),
      'path_params' =>  $pathParams,
      'raw_url' => $pathWithQuery,
      'referer' => $request->getHeaderLine('Referer'),
      'request_headers' => $this->redactHeaderFields($this->redactHeaders, $request->getHeaders()),
      'response_headers' => $this->redactHeaderFields($this->redactHeaders, $response->getHeaders()),
      'request_body' => base64_encode($this->redactJSONFields($this->redactRequestBody, json_encode($reqBod))),
      'response_body' => base64_encode($this->redactJSONFields($this->redactResponseBody, $body)),
      'errors' => $this->errors,
      'sdk_type' => 'PhpSlim',
      'msg_id' => $msg_id,
      'tags' => $this->tags,
      'service_version' => $this->serviceVersion,
      'parent_id' => null,
      'status_code' => $response->getStatusCode(),
      'timestamp' => (new \DateTime())->format('c'),
      'url_path' => $pattern,
    ];
  }

  public  static function redactHeaderFields(array $redactKeys, array $headerFields): array
  {
    array_walk($headerFields, function (&$value, $key, $redactKeys) {
      if (in_array(strtolower($key), array_map('strtolower', $redactKeys))) {
        $value = ['[CLIENT_REDACTED]'];
      }
    }, $redactKeys);
    return $headerFields;
  }

  // redactJSONFields accepts a list of jsonpath's to redact, and a json object to redact from, 
  // and returns the final json after the redacting has been done.
  public static function redactJSONFields(array $redactKeys, string $jsonStr): string
  {
    try {
      $obj = new JsonObject($jsonStr);
    } catch (InvalidJsonException $e) {
      // For any data that isn't json, we simply return the data as is.
      return $jsonStr;
    }

    foreach ($redactKeys as $jsonPath) {
      $obj->set($jsonPath, '[CLIENT_REDACTED]');
    }
    return $obj->getJson();
  }
}

class InvalidClientMetadataException extends Exception
{
}

function rootCause($err)
{
  $cause = $err;
  while ($cause && property_exists($cause, 'cause')) {
    $cause = $cause->cause;
  }
  return $cause;
}

function buildError($err)
{
  $errType = get_class($err);
  $rootError = rootCause($err);
  $rootErrorType = get_class($rootError);

  return [
    'when' => date('c'),
    'error_type' => $errType,
    'message' => $err->getMessage(),
    'root_error_type' => $rootErrorType,
    'root_error_message' => $rootError->getMessage(),
    'stack_trace' => $err->getTraceAsString(),
  ];
}


function extractPathParams($pattern, $url)
{
  $patternSegments = explode('/', trim($pattern, '/'));
  $urlSegments = explode('/', trim($url, '/'));

  $params = array();

  foreach ($patternSegments as $key => $segment) {
    if (strpos($segment, '{') === 0 && strpos($segment, '}') === strlen($segment) - 1) {
      $paramName = trim($segment, '{}');
      if (isset($urlSegments[$key])) {
        $params[$paramName] = $urlSegments[$key];
      }
    }
  }

  return $params;
}
