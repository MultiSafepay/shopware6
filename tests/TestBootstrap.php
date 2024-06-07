<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
use Shopware\Core\TestBootstrapper;

require __DIR__ . '/../../../../vendor/autoload.php';

(new TestBootstrapper())->addActivePlugins('MltisafeMultiSafepay')->bootstrap();
