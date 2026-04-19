<?php

declare(strict_types=1);

namespace App\ApiDoc;

use Nelmio\ApiDocBundle\Describer\DescriberInterface;
use OpenApi\Annotations\OpenApi;

final class VersionDescriber implements DescriberInterface
{
    public function __construct(private readonly string $projectDir) {}

    public function describe(OpenApi $api): void
    {
        $composerPath = $this->projectDir.'/composer.json';
        if (!is_file($composerPath)) {
            return;
        }
        $data = json_decode((string) file_get_contents($composerPath), true);
        if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
            $api->info->version = $data['version'];
        }
    }
}
