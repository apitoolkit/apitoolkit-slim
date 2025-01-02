<?php

namespace APIToolkit;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Apitoolkit\Common\Shared;


class APIToolkitSlim
{
    public static  function observeGuzzle($request, $options) {
      $apitoolkit = $request->getAttribute("apitoolkitData");
      $msgId = $apitoolkit['msg_id'];
      return Shared::observeGuzzle($options, $msgId);
    }

    public static function reportError($error, $request)
    {
      $atError = buildError($error);
      $apitoolkit = $request->getAttribute("apitoolkitData");
      $client = $apitoolkit['client'];
      Share::reportError($error, $client);
    }
}
