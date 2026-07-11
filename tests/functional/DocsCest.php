<?php

namespace tests\functional;

use FunctionalTester;

/**
 * The API documentation must be reachable without a token and served as raw
 * HTML / YAML (outside the JSON response envelope).
 */
class DocsCest extends BaseCest
{
    public function testSwaggerUiIsPublicHtml(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendGet('/docs');

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'text/html; charset=UTF-8');
        $I->seeResponseContains('<title>Photos REST API');
        $I->seeResponseContains('swagger-ui');
        // it points at the raw spec route, not an inline copy
        $I->seeResponseContains('/docs/openapi.yaml');
    }

    public function testOpenApiSpecIsPublicYaml(FunctionalTester $I): void
    {
        $I->deleteHeader('Authorization');
        $I->sendGet('/docs/openapi.yaml');

        $I->seeResponseCodeIs(200);
        $I->seeHttpHeader('Content-Type', 'application/yaml; charset=UTF-8');
        // served verbatim, not wrapped in the {success,data,code} envelope
        $I->seeResponseContains('openapi: 3.0.3');
        $I->seeResponseContains('title: Photos REST API');
    }
}
