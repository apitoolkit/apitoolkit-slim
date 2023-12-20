<?php

namespace APIToolkit;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;


class APIToolkitSlim
{
    public static  function observeGuzzle($request, $options)
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
                'path_params' => extractPathParams($request_info["url_path"], $request_info["url_no_query"]),
                'raw_url' => $request_info["raw_url"],
                'referer' => "",
                'request_headers' => redactHeaderFields($options["redactHeaders"] ?? [], $request_info["headers"]),
                'response_headers' => redactHeaderFields($options["redactHeaders"] ?? [], $response->getHeaders()),
                'request_body' => base64_encode(redactJSONFields($options["redactRequestBody"] ?? [], $request_info["body"])),
                'response_body' => base64_encode(redactJSONFields($options["redactResponseBody"] ?? [], $respBody)),
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
