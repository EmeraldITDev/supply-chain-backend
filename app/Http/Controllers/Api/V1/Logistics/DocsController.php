<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use Illuminate\Support\Facades\File;

class DocsController extends ApiController
{
    public function spec()
    {
        $path = base_path('docs/openapi/logistics-v1.yaml');

        if (!File::exists($path)) {
            return $this->error('OpenAPI spec not found', 'NOT_FOUND', 404);
        }

        return response(File::get($path), 200, [
            'Content-Type' => 'application/yaml',
        ]);
    }

    public function ui()
    {
        $specUrl = url('/api/v1/logistics/openapi.yaml');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Logistics API Docs</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" />
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    window.ui = SwaggerUIBundle({
      url: "{$specUrl}",
      dom_id: '#swagger-ui',
      presets: [SwaggerUIBundle.presets.apis],
      layout: "BaseLayout"
    });
  </script>
</body>
</html>
HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html',
        ]);
    }
}
