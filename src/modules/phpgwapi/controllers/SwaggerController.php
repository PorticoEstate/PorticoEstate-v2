<?php

namespace App\modules\phpgwapi\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Database\Db;

class SwaggerController
{
  private function getSpecFilePath(): string
  {
    return __DIR__ . '/../../../../swagger_spec/openapi.json';
  }

  private function loadSpecData(): array
  {
    $specFile = $this->getSpecFilePath();
    if (!file_exists($specFile))
    {
      throw new \RuntimeException('Specification not found');
    }

    $spec = json_decode((string) file_get_contents($specFile), true);
    if (!is_array($spec))
    {
      throw new \RuntimeException('Specification is invalid JSON');
    }

    return $spec;
  }

  private function jsonResponse(Response $response, array $payload, int $status = 200): Response
  {
    $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
  }

  /**
   * Show the Swagger UI interface
   */
  public function index(Request $request, Response $response): Response
  {
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>API Documentation</title>
    <link rel="stylesheet" href="/vendor/swagger-api/swagger-ui/dist/swagger-ui.css" />
    <style>
      html { box-sizing: border-box; overflow: -moz-scrollbars-vertical; overflow-y: scroll; }
      *, *:before, *:after { box-sizing: inherit; }
      body { margin: 0; padding: 0; background: #fafafa; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>

    <script src="/vendor/swagger-api/swagger-ui/dist/swagger-ui-bundle.js"></script>
    <script src="/vendor/swagger-api/swagger-ui/dist/swagger-ui-standalone-preset.js"></script>
    <script>
    window.onload = function() {
      // Dynamically determine the current host and port
      const protocol = window.location.protocol;
      const hostname = window.location.hostname;
      const port = window.location.port ? window.location.port : (protocol === 'https:' ? '443' : '80');
      const baseUrl = `\${protocol}//\${hostname}:\${port}`;
      
      // First, fetch the OpenAPI spec file
      fetch('/swagger/spec')
        .then(response => response.json())
        .then(spec => {
          // Add current server to the spec
          if (!spec.servers) {
            spec.servers = [];
          }
          // Add current server as first option
          spec.servers.unshift({
            url: baseUrl,
            description: "Current server"
          });
          
          // Make sure openapi version exists
          if (!spec.openapi) {
            spec.openapi = "3.0.0";
          }
          
          // Initialize SwaggerUI with the modified spec
          window.ui = SwaggerUIBundle({
            spec: spec,
            dom_id: '#swagger-ui',
            deepLinking: true,
            presets: [
			  SwaggerUIBundle.presets.apis,
			  SwaggerUIStandalonePreset
            ],
            plugins: [
              SwaggerUIBundle.plugins.DownloadUrl
            ],
			layout: "StandaloneLayout"
          });
        })
        .catch(error => {
          console.error("Failed to load OpenAPI spec:", error);
          document.getElementById('swagger-ui').innerHTML = 
            '<div class="error"><h2>Error loading API specification</h2><p>' + 
            error.message + '</p></div>';
        });
    };
    </script>
</body>
</html>
HTML;

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
  }

  /**
   * Serve the OpenAPI specification
   */
  public function getSpec(Request $request, Response $response): Response
  {
  try
  {
    return $this->jsonResponse($response, $this->loadSpecData());
  }
  catch (\RuntimeException $exception)
  {
    return $this->jsonResponse($response, ['error' => $exception->getMessage()], 404);
  }
  }

  /**
   * Serve a module-filtered OpenAPI specification.
   */
  public function getModuleSpec(Request $request, Response $response, array $args): Response
  {
    $module = strtolower((string) ($args['module'] ?? ''));
    if ($module === '')
    {
      return $this->jsonResponse($response, ['error' => 'Module is required'], 400);
    }

    try
    {
      $spec = $this->loadSpecData();
    }
    catch (\RuntimeException $exception)
    {
      return $this->jsonResponse($response, ['error' => $exception->getMessage()], 404);
    }

    $pathPrefix = '/property/' . $module;
    $filteredPaths = [];
    $tagNames = [];

    foreach (($spec['paths'] ?? []) as $path => $operations)
    {
      if (strpos((string) $path, $pathPrefix) !== 0)
      {
        continue;
      }

      $filteredPaths[$path] = $operations;
      foreach ($operations as $operation)
      {
        if (!is_array($operation) || empty($operation['tags']) || !is_array($operation['tags']))
        {
          continue;
        }

        foreach ($operation['tags'] as $tag)
        {
          $tagNames[(string) $tag] = true;
        }
      }
    }

    if (!$filteredPaths)
    {
      return $this->jsonResponse($response, ['error' => "No Swagger definition found for module '{$module}'"], 404);
    }

    $spec['paths'] = $filteredPaths;
    if (!empty($spec['tags']) && is_array($spec['tags']))
    {
      $spec['tags'] = array_values(array_filter($spec['tags'], static function ($tag) use ($tagNames)
      {
        return is_array($tag)
          && isset($tag['name'])
          && isset($tagNames[(string) $tag['name']]);
      }));
    }

    return $this->jsonResponse($response, $spec);
  }
}
