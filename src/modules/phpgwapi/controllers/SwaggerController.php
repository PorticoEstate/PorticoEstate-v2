<?php

namespace App\modules\phpgwapi\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SwaggerController
{
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
        // Path to your OpenAPI specification
        $specFile = __DIR__ . '/../../../../swagger_spec/openapi.json';
        
        if (!file_exists($specFile)) {
            $response->getBody()->write(json_encode(['error' => 'Specification not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $spec = file_get_contents($specFile);
        $response->getBody()->write($spec);
        return $response->withHeader('Content-Type', 'application/json');
    }
}