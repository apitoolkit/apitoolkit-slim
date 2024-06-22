<div align="center">

![APItoolkit's Logo](https://github.com/apitoolkit/.github/blob/main/images/logo-white.svg?raw=true#gh-dark-mode-only)
![APItoolkit's Logo](https://github.com/apitoolkit/.github/blob/main/images/logo-black.svg?raw=true#gh-light-mode-only)

## Slim SDK

[![APItoolkit SDK](https://img.shields.io/badge/APItoolkit-SDK-0068ff?logo=php)](https://github.com/topics/apitoolkit-sdk) [![Join Discord Server](https://img.shields.io/badge/Chat-Discord-7289da)](https://discord.gg/dEB6EjQnKB) [![APItoolkit Docs](https://img.shields.io/badge/Read-Docs-0068ff)](https://apitoolkit.io/docs/sdks/php/slim?utm_source=github-sdks) 

APItoolkit is an end-to-end API and web services management toolkit for engineers and customer support teams. To integrate your Slim (PHP) application with APItoolkit, you need to use this SDK to monitor incoming traffic, aggregate the requests, and then deliver them to the APItoolkit's servers.

</div>

---

## Table of Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Contributing and Help](#contributing-and-help)
- [License](#license)

---

## Installation

Kindly run the command below to install the SDK:

```sh
composer require apitoolkit/apitoolkit-slim
```

## Configuration

Next, create a new instance of the `APIToolkitMiddleware` class and register the middleware with the Slim Framework in the `app/middleware.php` file, like so:

```php
use Slim\Factory\AppFactory;
use APIToolkit\APIToolkitMiddleware;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

$apitoolkitMiddleware = new APIToolkitMiddleware(['apiKey' => "{ENTER_YOUR_API_KEY_HERE}"]);

$app->add($apitoolkitMiddleware);

$app->get('/', function ($request, $response) {
    $response->getBody()->write('Hello, World!');
    return $response;
});

$app->run();
```

> [!NOTE]
> 
> The `{ENTER_YOUR_API_KEY_HERE}` demo string should be replaced with the [API key](https://apitoolkit.io/docs/dashboard/settings-pages/api-keys?utm_source=github-sdks) generated from the APItoolkit dashboard.

<br />

> [!IMPORTANT]
> 
> To learn more configuration options (redacting fields, error reporting, outgoing requests, etc.), please read this [SDK documentation](https://apitoolkit.io/docs/sdks/php/slim?utm_source=github-sdks).

## Contributing and Help

To contribute to the development of this SDK or request help from the community and our team, kindly do any of the following:
- Read our [Contributors Guide](https://github.com/apitoolkit/.github/blob/main/CONTRIBUTING.md).
- Join our community [Discord Server](https://discord.gg/dEB6EjQnKB).
- Create a [new issue](https://github.com/apitoolkit/apitoolkit-slim/issues/new/choose) in this repository.

## License

This repository is published under the [MIT](LICENSE) license.

---

<div align="center">
    
<a href="https://apitoolkit.io?utm_source=github-sdks" target="_blank" rel="noopener noreferrer"><img src="https://github.com/apitoolkit/.github/blob/main/images/icon.png?raw=true" width="40" /></a>

</div>
