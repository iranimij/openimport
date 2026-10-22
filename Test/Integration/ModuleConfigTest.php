<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration;

use Iranimij\OpenImport\Model\Config;
use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\Framework\Module\ModuleList;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The module is installed on top of the shared base module, its defaults load and its ACL tree is where 07 says.
 */
class ModuleConfigTest extends TestCase
{
    public function testModuleIsEnabledAfterTheBaseModule(): void
    {
        $modules = Bootstrap::getObjectManager()->get(ModuleList::class)->getNames();
        self::assertContains('Iranimij_OpenImport', $modules);
        self::assertContains('Iranimij_Base', $modules);
        self::assertGreaterThan(
            array_search('Iranimij_Base', $modules, true),
            array_search('Iranimij_OpenImport', $modules, true)
        );
    }

    public function testDefaultsComeFromConfigXml(): void
    {
        $config = Bootstrap::getObjectManager()->get(Config::class);
        self::assertSame(14, $config->getKeepFilesDays());
        self::assertSame(10000, $config->getMaxErrorsPerRun());
        self::assertTrue($config->isFormulaGuardEnabled());
        self::assertSame(10, $config->getSafetyThresholdPercent());
    }

    public function testAclResourcesSitUnderSystemDataTransfer(): void
    {
        $tree = Bootstrap::getObjectManager()->get(ProviderInterface::class)->getAclResources();
        $ids = [];
        $walk = static function (array $nodes, array $path) use (&$walk, &$ids): void {
            foreach ($nodes as $node) {
                $ids[$node['id']] = $path;
                $walk($node['children'] ?? [], [...$path, $node['id']]);
            }
        };
        $walk($tree, []);
        foreach (['profiles', 'profiles_edit', 'run', 'run_destructive', 'runs', 'run_files'] as $child) {
            self::assertSame(
                ['Magento_Backend::admin', 'Magento_Backend::system', 'Magento_Backend::convert', 'Iranimij_OpenImport::openimport'],
                $ids['Iranimij_OpenImport::' . $child] ?? null,
                $child
            );
        }
        self::assertContains('Iranimij_Base::config', $ids['Iranimij_OpenImport::config'] ?? []);
    }
}
