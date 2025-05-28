<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\TestBootstrapper;

$loader = require __DIR__ . '/../../../../vendor/autoload.php';

// Initialize the test environment
$bootstrapper = new TestBootstrapper();
$bootstrapper
    ->addActivePlugins('MltisafeMultiSafepay')
    ->setForceInstallPlugins(true)
    ->bootstrap();

// Ensure the kernel is available for all test cases
KernelLifecycleManager::ensureKernelShutdown();
KernelLifecycleManager::bootKernel();
