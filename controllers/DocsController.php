<?php

namespace app\controllers;

use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use Yii;

/**
 * Serves the interactive API documentation:
 *  - `GET /docs`             → Swagger UI (HTML)
 *  - `GET /docs/openapi.yaml` → the raw OpenAPI spec
 *
 * The spec in `config/openapi.yaml` is the single source of truth; this
 * controller only renders it. Both endpoints are public (documentation must
 * be reachable without a token) and bypass the JSON response envelope, so it
 * extends the plain web controller rather than the REST/ApiController stack.
 */
class DocsController extends Controller
{
    /** Pinned so the docs render deterministically offline caches / audits. */
    private const string SWAGGER_UI_VERSION = '5.17.14';

    public $enableCsrfValidation = false;

    public function actionIndex(): string
    {
        $this->prepareRawResponse('text/html; charset=UTF-8');

        return $this->swaggerUiHtml();
    }

    /**
     * @throws NotFoundHttpException when the spec file is missing
     */
    public function actionSpec(): string
    {
        $path = Yii::getAlias('@app/config/openapi.yaml');
        if (!is_file($path)) {
            throw new NotFoundHttpException('OpenAPI specification not found.');
        }

        $this->prepareRawResponse('application/yaml; charset=UTF-8');

        return (string) file_get_contents($path);
    }

    /**
     * Sends the action's return value verbatim (outside the JSON envelope),
     * under the given content type.
     */
    private function prepareRawResponse(string $contentType): void
    {
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', $contentType);
    }

    private function swaggerUiHtml(): string
    {
        $base = 'https://unpkg.com/swagger-ui-dist@' . self::SWAGGER_UI_VERSION;
        $specUrl = Yii::$app->urlManager->createUrl(['docs/spec']);

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Photos REST API — Docs</title>
            <link rel="stylesheet" href="{$base}/swagger-ui.css">
            <link rel="icon" type="image/png" href="{$base}/favicon-32x32.png" sizes="32x32">
        </head>
        <body>
            <div id="swagger-ui"></div>
            <script src="{$base}/swagger-ui-bundle.js"></script>
            <script src="{$base}/swagger-ui-standalone-preset.js"></script>
            <script>
                window.ui = SwaggerUIBundle({
                    url: "{$specUrl}",
                    dom_id: "#swagger-ui",
                    deepLinking: true,
                    presets: [
                        SwaggerUIBundle.presets.apis,
                        SwaggerUIStandalonePreset,
                    ],
                    layout: "StandaloneLayout",
                    persistAuthorization: true,
                });
            </script>
        </body>
        </html>
        HTML;
    }
}
