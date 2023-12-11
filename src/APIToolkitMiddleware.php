<?php
namespace APIToolkit;


use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Routing\RouteContext;
use Google\Cloud\PubSub\PubSubClient;
use Exception;
use JsonPath\JsonObject;
use JsonPath\InvalidJsonException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;



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

  public function __construct(
    $apiKey,
    $rootUrl = 'https://app.apitoolkit.io',
    array $redactHeaders = [],
    array $redactRequestBody = [],
    array $redactResponseBody = [],
    $debug = false,
    $serviceVersion = null,
    array $tags = [])
  {
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
      $startTime = hrtime(true);    
      $response = $handler->handle($request);
      $this->log($request, $response, $startTime);
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

  public function log(Request $request, $response, $startTime)
  {
    if (!$this->pubsubTopic) return;
    $payload = $this->buildPayload($request, $response, $startTime, $this->projectId);
    if ($this->debug) {
      $this->logger->debug("APIToolkit: payload", $payload);
    }
    $this->publishMessage($payload);
  }

  // payload static method deterministically converts a request, response object, a start time and a projectId
  // into a pauload json object which APIToolkit server is able to interprete.
  public function buildPayload(Request $request, $response, $startTime, $projectId)
  {
    $path = $request->getUri()->getPath();
    $query = $request->getUri()->getQuery();
    $routeContext = RouteContext::fromRequest($request);
    $route = $routeContext->getRoute();
    $pattern = $route->getPattern();
    $pathWithQuery = $path . ($query ? '?' . $query : '');
    return [
      'duration' => round(hrtime(true) - $startTime),
      'host' => $request->getUri()->getAuthority(),
      'method' => $request->getMethod(),
      'project_id' => $projectId,
      'proto_major' => 1,
      'proto_minor' => 1,
      'query_params' => $request->getQueryParams(),
      'path_params' =>  $request->getAttributes() || [],
      'raw_url' => $pathWithQuery,
      'referer' => $request->getHeaderLine('Referer'), 
      'request_headers' => $this->redactHeaderFields($this->redactHeaders, $request->getHeaders()),
      'response_headers' => $this->redactHeaderFields($this->redactHeaders, $response->getHeaders()),
      'request_body' => base64_encode($this->redactJSONFields($this->redactRequestBody, $request->getBody() ? $request->getBody()->getContents(): "")),
      'response_body' => base64_encode($this->redactJSONFields($this->redactResponseBody, $response->getBody()->getContents())),
      'sdk_type' => 'PhpLaravel',
      'status_code' => $response->getStatusCode(),
      'timestamp' => (new \DateTime())->format('c'),
      'url_path' => $pattern,
    ];
  }

  public function redactHeaderFields(array $redactKeys, array $headerFields): array
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
  public function redactJSONFields(array $redactKeys, string $jsonStr): string
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
