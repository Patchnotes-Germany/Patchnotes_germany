<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withCache(__DIR__.'/var/rector')
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        // Doctrine entities keep their fields declared in one block: the ORM mapping attributes
        // belong next to the column definitions, not in the constructor signature.
        ClassPropertyAssignToConstructorPromotionRector::class => [
            __DIR__.'/src/*/Entity/*',
        ],
    ]);
