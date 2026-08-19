<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\TestBootstrapper;

$loader = require __DIR__ . '/../../../../vendor/autoload.php';

// The plugin is installed as a dependency of the Shopware root package, and
// Composer only applies the *root* package's autoload-dev. Our own
// autoload-dev mapping therefore never reaches the generated autoloader, so
// anything the test classes reference across files -- fixtures, traits, base
// classes -- fails to resolve. PHPUnit includes the test files themselves by
// path, which is why the failure shows up as a missing trait rather than a
// missing test. Register the mapping here instead.
$loader->addPsr4('MultiSafepay\\Shopware6\\Tests\\', __DIR__);

// Initialize the test environment
$bootstrapper = new TestBootstrapper();
$bootstrapper
    ->addActivePlugins('MltisafeMultiSafepay')
    ->setForceInstallPlugins(true)
    ->bootstrap();

// Ensure the kernel is available for all test cases
KernelLifecycleManager::ensureKernelShutdown();
KernelLifecycleManager::bootKernel();
