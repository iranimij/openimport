<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Import;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Import\PriceRule\ConditionTree;
use Iranimij\OpenImport\Model\Import\PriceRule\PriceRuleImporter;
use Iranimij\OpenImport\Model\Import\PriceRule\PriceRuleRecords;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogRule\Model\Indexer\IndexBuilder;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\CatalogRule\Model\Rule as CatalogRule;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Quote\Model\Quote;
use Magento\SalesRule\Model\Rule as CartRule;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Cart and catalog price rules through OpenImport's own importer: rules with coupons, labels and conditions from
 * simple columns and JSON, the rule applies in a quote (coupon) and to a product price (catalog), export round
 * trip, update, bad rows, delete.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class PriceRuleImportTest extends TestCase
{
    private function file(string $content): string
    {
        $var = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $relative = 'openimport/it-rules-' . bin2hex(random_bytes(3)) . '.csv';
        $var->writeFile($relative, $content);

        return $var->getAbsolutePath($relative);
    }

    /**
     * @param string[] $columns
     */
    private function profile(string $entity, array $columns, string $behavior = ProfileInterface::BEHAVIOR_ADD_UPDATE): ProfileInterface
    {
        $mapping = [];
        foreach ($columns as $column) {
            $mapping[] = ['target' => $column, 'source' => $column];
        }
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Rules ' . uniqid())
            ->setEntity($entity)
            ->setFormat('csv')
            ->setSourceType('upload')
            ->setBehavior($behavior)
            ->setSafetyThreshold(100)
            ->setMapping($mapping);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function process(ProfileInterface $profile, string $file, string $mode = RunInterface::MODE_LIVE, array $options = []): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            $mode,
            RunInterface::TRIGGER_CLI,
            $options + ['file' => $file, 'original_name' => 'rules.csv']
        );

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    private function report(RunInterface $run): string
    {
        $messages = [$run->getMessage()];
        foreach (Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $run->getRunId(), 50) as $error) {
            $messages[] = $error->message;
        }

        return implode("\n", $messages);
    }

    /**
     * @return array<string, array<string, string>> rows of an export by name
     */
    private function export(string $entity, string $tag): array
    {
        $export = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Rules export ' . uniqid())
            ->setDirection(ProfileInterface::DIRECTION_EXPORT)
            ->setEntity($entity)
            ->setFormat('csv')
            ->setRowFilters(['mode' => 'include', 'match' => 'all', 'conditions' => [['column' => 'name', 'operator' => 'starts_with', 'value' => $tag]]])
            ->setExportOptions(['destination' => 'download']);
        $export = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($export);
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($export, RunInterface::MODE_LIVE, RunInterface::TRIGGER_CLI);
        $run = Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        $path = Bootstrap::getObjectManager()->get(RunFiles::class)->absolute((string) $run->getOutputFile());
        $rows = [];
        foreach (Bootstrap::getObjectManager()->get(ReaderFactory::class)->create($path, 'csv')->rows() as $row) {
            $rows[$row['name']] = $row + ['_file' => $path];
        }

        return $rows;
    }

    private function cartRule(string $name): ?CartRule
    {
        $records = Bootstrap::getObjectManager()->get(PriceRuleRecords::class);
        $ids = $records->idsByName(ConditionTree::CART, $name);
        $rule = $ids === [] ? null : $records->load(ConditionTree::CART, $ids[0]);

        return $rule instanceof CartRule ? $rule : null;
    }

    #[DataFixture('Magento/Catalog/_files/product_simple.php')]
    public function testCartRulesWithCouponsApplyInAQuote(): void
    {
        $tag = 'OI ' . bin2hex(random_bytes(3));
        $code = 'SAVE10-' . bin2hex(random_bytes(3));
        $list = ['OI-A-' . bin2hex(random_bytes(2)), 'OI-B-' . bin2hex(random_bytes(2))];
        $header = "name;description;is_active;websites;customer_groups;from_date;to_date;priority;coupon_type;coupon_code;coupon_codes;"
            . "uses_per_coupon;uses_per_customer;action;discount_amount;free_shipping;labels;condition_subtotal;condition_skus;action_skus;conditions\n";
        $columns = explode(';', trim($header));
        $file = $this->file($header
            . "{$tag} ten;Ten percent;1;base;NOT LOGGED IN,General;2020-01-01;;5;specific;{$code};;5;1;by_percent;10;no;"
            . "10 % off|default:Ten off;20;;;\n"
            . "{$tag} fixed;;1;all;all;;;0;specific;;{$list[0]},{$list[1]};3;0;by_fixed;2;matching;;;simple;simple;\n"
            . "{$tag} json;;0;base;General;;;1;;;;;;cart_fixed;5;;;;;;"
            . '"{""type"":""Combine"",""aggregator"":""any"",""value"":""1"",""conditions"":[{""type"":""Address"",""attribute"":""base_subtotal"",""operator"":"">="",""value"":""100""}]}"'
            . "\n");
        $profile = $this->profile(EntityRegistry::CART_RULE, $columns);

        $dry = $this->process($profile, $file, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), $this->report($dry));
        self::assertSame(3, $dry->getCount('created'));
        self::assertNull($this->cartRule("{$tag} ten"), 'a dry run writes nothing');

        $live = $this->process($profile, $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), $this->report($live));
        self::assertSame(3, $live->getCount('created'));
        $ten = $this->cartRule("{$tag} ten");
        self::assertNotNull($ten);
        self::assertSame($code, $ten->getPrimaryCoupon()->getCode());
        self::assertSame(['by_percent', 10.0, 5, 1], [$ten->getSimpleAction(), (float) $ten->getDiscountAmount(), (int) $ten->getUsesPerCoupon(), (int) $ten->getUsesPerCustomer()]);
        self::assertSame('2020-01-01', substr((string) $ten->getFromDate(), 0, 10));
        $labels = (array) $ten->getStoreLabels();
        ksort($labels);
        self::assertSame([0 => '10 % off', 1 => 'Ten off'], $labels);
        $groups = array_map('intval', (array) $ten->getCustomerGroupIds());
        sort($groups);
        self::assertSame([0, 1], $groups);
        $websiteId = (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getWebsite('base')->getId();
        self::assertSame([$websiteId], array_map('intval', $ten->getWebsiteIds()));
        $records = Bootstrap::getObjectManager()->get(PriceRuleRecords::class);
        self::assertSame([], $records->couponCodes((int) $ten->getId()));
        $conditions = $ten->getConditions()->asArray();
        self::assertSame('base_subtotal', $conditions['conditions'][0]['attribute']);
        self::assertSame('20', $conditions['conditions'][0]['value']);
        $fixed = $this->cartRule("{$tag} fixed");
        self::assertNotNull($fixed);
        self::assertSame(1, (int) $fixed->getSimpleFreeShipping());
        self::assertSame([1, 3], [(int) $fixed->getUseAutoGeneration(), (int) $fixed->getUsesPerCoupon()]);
        self::assertSame($list, $records->couponCodes((int) $fixed->getId()));
        self::assertSame('sku', $fixed->getConditions()->asArray()['conditions'][0]['conditions'][0]['attribute']);
        self::assertSame('simple', $fixed->getActions()->asArray()['conditions'][0]['value']);
        $json = $this->cartRule("{$tag} json");
        self::assertNotNull($json);
        self::assertSame('any', $json->getConditions()->asArray()['aggregator']);
        self::assertSame('100', $json->getConditions()->asArray()['conditions'][0]['value']);
        self::assertSame(0, (int) $json->getIsActive());

        // The coupon works in a cart: three simple products (30) with the code give 10 % off.
        $product = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class)->get('simple');
        /** @var Quote $quote */
        $quote = Bootstrap::getObjectManager()->create(Quote::class);
        $quote->setStoreId(1)->setIsActive(true)->setCustomerIsGuest(true)->setCustomerGroupId(0)
            ->setCustomerEmail('guest@example.com');
        $quote->getBillingAddress()->setCountryId('DE')->setPostcode('60311')->setCity('Frankfurt');
        $quote->getShippingAddress()->setCountryId('DE')->setPostcode('60311')->setCity('Frankfurt')
            ->setCollectShippingRates(true);
        $quote->addProduct($product, 3);
        $quote->setCouponCode($code)->collectTotals();
        $address = $quote->getShippingAddress();
        $diagnosis = sprintf(
            'coupon "%s", applied rules "%s", address rules "%s", base subtotal %s, discount %s, items %d; rule active %s, '
            . 'websites %s, groups %s, type %s, auto %s, validates %s',
            (string) $quote->getCouponCode(),
            (string) $quote->getAppliedRuleIds(),
            (string) $address->getAppliedRuleIds(),
            (string) $address->getBaseSubtotal(),
            (string) $address->getDiscountAmount(),
            count($address->getAllItems()),
            (string) $ten->getIsActive(),
            implode(',', (array) $ten->getWebsiteIds()),
            implode(',', (array) $ten->getCustomerGroupIds()),
            (string) $ten->getCouponType(),
            (string) $ten->getUseAutoGeneration(),
            $ten->validate($address) ? 'yes' : 'no'
        );
        self::assertSame($code, $quote->getCouponCode(), 'the coupon is accepted: ' . $diagnosis);
        self::assertSame(-3.0, (float) $address->getDiscountAmount(), '30 with 10 % off: ' . $diagnosis);

        // Update by name: the discount changes, everything else stays; the export round trip imports as it is.
        $update = $this->file("name;discount_amount\n{$tag} ten;15\n");
        $changed = $this->process($this->profile(EntityRegistry::CART_RULE, ['name', 'discount_amount']), $update);
        self::assertSame(RunInterface::STATUS_SUCCESS, $changed->getStatus(), $this->report($changed));
        self::assertSame([0, 1], [$changed->getCount('created'), $changed->getCount('updated')]);
        $ten = $this->cartRule("{$tag} ten");
        self::assertNotNull($ten);
        self::assertSame(15.0, (float) $ten->getDiscountAmount());
        self::assertSame($code, $ten->getPrimaryCoupon()->getCode());
        self::assertSame('20', $ten->getConditions()->asArray()['conditions'][0]['value'], 'conditions kept');

        $rows = $this->export(EntityRegistry::CART_RULE, $tag);
        self::assertCount(3, $rows);
        self::assertSame($code, $rows["{$tag} ten"]['coupon_code']);
        self::assertSame('', $rows["{$tag} ten"]['coupon_codes']);
        self::assertSame(['', implode(';', $list)], [$rows["{$tag} fixed"]['coupon_code'], $rows["{$tag} fixed"]['coupon_codes']]);
        self::assertSame('10 % off|default:Ten off', $rows["{$tag} ten"]['labels']);
        self::assertSame('base', $rows["{$tag} ten"]['websites']);
        self::assertSame('NOT LOGGED IN,General', $rows["{$tag} ten"]['customer_groups']);
        self::assertSame('specific', $rows["{$tag} ten"]['coupon_type']);
        self::assertSame('matching', $rows["{$tag} fixed"]['free_shipping']);
        self::assertStringContainsString('"attribute":"base_subtotal"', $rows["{$tag} ten"]['conditions']);
        $back = $this->process($this->profile(EntityRegistry::CART_RULE, PriceRuleImporter::CART_COLUMNS), $rows["{$tag} ten"]['_file'], RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $back->getStatus(), $this->report($back));
        self::assertSame([0, 0, 3], [$back->getCount('created'), $back->getCount('updated'), $back->getCount('unchanged')], $back->getMessage());
        $again = $this->process($this->profile(EntityRegistry::CART_RULE, PriceRuleImporter::CART_COLUMNS), $rows["{$tag} ten"]['_file']);
        self::assertSame(RunInterface::STATUS_SUCCESS, $again->getStatus(), $this->report($again));
        self::assertSame($list, $records->couponCodes((int) $fixed->getId()), 'coupons not doubled');

        // Bad rows are reported, nothing is written.
        $bad = $this->file($header
            . "{$tag} w;;1;nowhere;all;;;0;none;;;0;0;by_percent;1;no;;;;;\n"
            . "{$tag} a;;1;all;all;;;0;none;;;0;0;half_off;1;no;;;;;\n"
            . "{$tag} c;;1;all;all;;;0;specific;;;0;0;by_percent;1;no;;;;;\n"
            . "{$tag} j;;1;all;all;;;0;none;;;0;0;by_percent;1;no;;;;;\"{\"\"type\"\":\"\"Nope\"\"}\"\n"
            . "{$tag} d;;1;all;all;not a date;;0;none;;;0;0;by_percent;1;no;;;;;\n"
            . "{$tag} ten;;1;all;all;;;0;none;;;0;0;by_percent;1;no;;;;;\n"
            . "{$tag} ten;;1;all;all;;;0;none;;;0;0;by_percent;1;no;;;;;\n"
            . ";;1;all;all;;;0;none;;;0;0;by_percent;1;no;;;;;\n");
        $check = $this->process($profile, $bad, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_FAILED, $check->getStatus());
        $messages = $this->report($check);
        self::assertStringContainsString('there is no website "nowhere"', $messages);
        self::assertStringContainsString('"half_off" is not an action', $messages);
        self::assertStringContainsString('needs a coupon_code', $messages);
        self::assertStringContainsString('The conditions are not usable', $messages);
        self::assertStringContainsString('"not a date" is not a date.', $messages);
        self::assertStringContainsString("\"{$tag} ten\" appears twice in the file.", $messages);
        self::assertStringContainsString('The rule has no name.', $messages);
        self::assertSame(15.0, (float) $this->cartRule("{$tag} ten")?->getDiscountAmount());

        // Delete by name.
        $delete = $this->profile(EntityRegistry::CART_RULE, ['name'], ProfileInterface::BEHAVIOR_DELETE);
        $gone = $this->process($delete, $this->file("name\n{$tag} ten\n{$tag} fixed\n{$tag} json\n"), RunInterface::MODE_LIVE, ['confirmed' => true]);
        self::assertSame(RunInterface::STATUS_SUCCESS, $gone->getStatus(), $this->report($gone));
        self::assertSame(3, $gone->getCount('deleted'));
        self::assertNull($this->cartRule("{$tag} ten"));
        self::assertSame([], $records->couponOwners([$code, $list[0]]), 'coupons go with the rules');
    }

    #[DataFixture('Magento/Catalog/_files/product_simple.php')]
    public function testCatalogRuleAppliesToAProductPrice(): void
    {
        $tag = 'OI ' . bin2hex(random_bytes(3));
        $header = "name;is_active;websites;customer_groups;priority;stop_rules_processing;action;discount_amount;condition_skus\n";
        $file = $this->file($header . "{$tag} catalog;1;base;NOT LOGGED IN;0;0;by_percent;10;simple\n");
        $profile = $this->profile(EntityRegistry::CATALOG_RULE, explode(';', trim($header)));

        $live = $this->process($profile, $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), $this->report($live));
        self::assertSame(1, $live->getCount('created'));
        $records = Bootstrap::getObjectManager()->get(PriceRuleRecords::class);
        $ids = $records->idsByName(ConditionTree::CATALOG, "{$tag} catalog");
        self::assertCount(1, $ids);
        $rule = $records->load(ConditionTree::CATALOG, $ids[0]);
        self::assertInstanceOf(CatalogRule::class, $rule);
        self::assertSame('sku', $rule->getConditions()->asArray()['conditions'][0]['attribute']);

        Bootstrap::getObjectManager()->get(IndexBuilder::class)->reindexFull();
        $product = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class)->get('simple');
        $websiteId = (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getWebsite('base')->getId();
        $price = Bootstrap::getObjectManager()->get(CatalogRuleResource::class)
            ->getRulePrice(new \DateTime(), $websiteId, 0, (int) $product->getId());
        self::assertSame(9.0, (float) $price, 'the simple product (10) costs 9 for guests');

        $rows = $this->export(EntityRegistry::CATALOG_RULE, $tag);
        self::assertSame('by_percent', $rows["{$tag} catalog"]['action']);
        self::assertSame('10', $rows["{$tag} catalog"]['discount_amount']);
        self::assertSame('NOT LOGGED IN', $rows["{$tag} catalog"]['customer_groups']);
        self::assertStringContainsString('"value":"simple"', $rows["{$tag} catalog"]['conditions']);
        $back = $this->process($this->profile(EntityRegistry::CATALOG_RULE, PriceRuleImporter::CATALOG_COLUMNS), $rows["{$tag} catalog"]['_file'], RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $back->getStatus(), $this->report($back));
        self::assertSame([0, 0, 1], [$back->getCount('created'), $back->getCount('updated'), $back->getCount('unchanged')], $back->getMessage());

        $delete = $this->profile(EntityRegistry::CATALOG_RULE, ['name'], ProfileInterface::BEHAVIOR_DELETE);
        $gone = $this->process($delete, $this->file("name\n{$tag} catalog\n"), RunInterface::MODE_LIVE, ['confirmed' => true]);
        self::assertSame(RunInterface::STATUS_SUCCESS, $gone->getStatus(), $this->report($gone));
        self::assertSame([], $records->idsByName(ConditionTree::CATALOG, "{$tag} catalog"));
    }

    public function testReplaceIsRefused(): void
    {
        $this->expectExceptionMessage('openimport_cart_rule cannot be imported with "Replace": use Add/Update.');
        $this->profile(EntityRegistry::CART_RULE, ['name'], ProfileInterface::BEHAVIOR_REPLACE);
    }
}
