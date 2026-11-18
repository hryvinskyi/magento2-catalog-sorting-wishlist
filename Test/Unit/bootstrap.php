<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

/**
 * Standalone bootstrap for this module's unit tests
 *
 * The classes under test run against Composer's autoloader, without booting Magento. The module's namespace is
 * read from its own composer.json, so the bootstrap works from app/code, from vendor and from a standalone checkout.
 */

$autoloadCandidates = [
    __DIR__ . '/../../../../autoload.php', // vendor/<v>/<m>/Test/Unit -> vendor/autoload.php
    __DIR__ . '/../../vendor/autoload.php', // module run standalone
    __DIR__ . '/../../../../../../vendor/autoload.php', // app/code/<V>/<M>/Test/Unit -> root vendor
];

$loader = null;
$vendorDir = null;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        $loader = require $candidate;
        $vendorDir = dirname($candidate);
        break;
    }
}

if (!$loader instanceof \Composer\Autoload\ClassLoader || $vendorDir === null) {
    throw new \RuntimeException('Unable to locate Composer autoloader for the test bootstrap.');
}

$moduleDir = dirname(__DIR__, 2);
$manifest = json_decode((string)file_get_contents($moduleDir . '/composer.json'), true);
$autoload = is_array($manifest) ? ($manifest['autoload'] ?? null) : null;
$psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;
foreach (is_array($psr4) ? $psr4 : [] as $namespace => $path) {
    if (is_string($namespace) && is_string($path)) {
        $loader->addPsr4($namespace, $moduleDir . '/' . $path);
    }
}

// When running inside a Magento installation, also resolve generated factories from generated/code.
$generated = dirname($vendorDir) . '/generated/code';
if (is_dir($generated)) {
    $loader->add('', [$generated]);
}

// A factory or proxy that generated/code does not hold (an installation that was never compiled, or a module run
// standalone) is generated on demand into a temporary directory, as Magento's own unit test framework does.
if (class_exists(\Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader::class)) {
    $generatedCodeAutoloader = new \Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader(
        [
            new \Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator(),
            new \Magento\Framework\TestFramework\Unit\Autoloader\ProxyGenerator(),
        ],
        new \Magento\Framework\Code\Generator\Io(
            new \Magento\Framework\Filesystem\Driver\File(),
            sys_get_temp_dir() . '/hryvinskyi-catalog-sorting-unit-generated'
        )
    );
    spl_autoload_register(static function (string $className) use ($generatedCodeAutoloader): void {
        $generatedCodeAutoloader->load($className);
    });
}

// Magento's translate function, which the root autoloader does not load: classes that build
// \Magento\Framework\Phrase messages call it.
$translate = $vendorDir . '/magento/framework/Phrase/__.php';
if (!function_exists('__') && is_file($translate)) {
    require_once $translate;
}
