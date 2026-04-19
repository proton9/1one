<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ApiDocTest extends WebTestCase
{
    public function testSpecEndpointReturnsValidOpenApi(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringStartsWith('application/json', (string) $client->getResponse()->headers->get('Content-Type'));

        $spec = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($spec);
        self::assertArrayHasKey('openapi', $spec);
        self::assertMatchesRegularExpression('/^3\.\d+\.\d+$/', $spec['openapi']);
    }

    public function testSpecInfoTitleAndVersion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');
        $spec = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('Payment Transfer Gateway', $spec['info']['title']);

        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
        self::assertSame($composer['version'], $spec['info']['version'], 'info.version must match composer.json');
    }

    public function testSpecContainsAllRoutes(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');
        $spec = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertArrayHasKey('/transfers', $spec['paths']);
        self::assertArrayHasKey('post', $spec['paths']['/transfers']);

        self::assertArrayHasKey('/transfers/{id}', $spec['paths']);
        self::assertArrayHasKey('get', $spec['paths']['/transfers/{id}']);

        self::assertArrayHasKey('/accounts/{id}/balance', $spec['paths']);
        self::assertArrayHasKey('get', $spec['paths']['/accounts/{id}/balance']);
    }

    public function testRedocUiEndpointReturnsHtml(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringStartsWith('text/html', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('/vendor/redoc/redoc.standalone.js', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('/api/doc.json', (string) $client->getResponse()->getContent());
    }
}
