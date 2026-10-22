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
use Iranimij\OpenImport\Model\Import\Category\CategoryTree;
use Iranimij\OpenImport\Model\Import\Small\CategoryPositionSource;
use Iranimij\OpenImport\Model\Import\Small\NewsletterSource;
use Iranimij\OpenImport\Model\Import\Small\ReviewSource;
use Iranimij\OpenImport\Model\Import\Small\SearchTermSource;
use Iranimij\OpenImport\Model\Import\Small\SmallRecords;
use Iranimij\OpenImport\Model\Import\Small\SynonymSource;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Newsletter\Model\Subscriber;
use Magento\Review\Model\Review;
use Magento\Review\Model\ResourceModel\Review\Collection as ReviewCollection;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The small entities through OpenImport's own importers, each as a round trip: reviews with ratings, newsletter
 * subscribers, search terms, search synonyms, product positions in categories.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class SmallEntitiesImportTest extends TestCase
{
    private function file(string $content): string
    {
        $var = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $relative = 'openimport/it-small-' . bin2hex(random_bytes(3)) . '.csv';
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
            ->setName('Small ' . uniqid())
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
            $options + ['file' => $file, 'original_name' => 'small.csv']
        );

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    /**
     * Makes ratings active and shown (true) or not shown (false) in store view 1.
     *
     * @param array<string, bool> $ratings rating code => shown
     */
    private function ratingVisibility(array $ratings): void
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        foreach ($ratings as $code => $shown) {
            $id = (int) $connection->fetchOne(
                $connection->select()->from($resource->getTableName('rating'), ['rating_id'])->where('rating_code = ?', $code)
            );
            self::assertGreaterThan(0, $id, 'rating ' . $code);
            $connection->update($resource->getTableName('rating'), ['is_active' => 1], ['rating_id = ?' => $id]);
            $connection->delete($resource->getTableName('rating_store'), ['rating_id = ?' => $id, 'store_id = ?' => 1]);
            if ($shown) {
                $connection->insert($resource->getTableName('rating_store'), ['rating_id' => $id, 'store_id' => 1]);
            }
        }
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
     * Imports a file live (asserting success and the counts), then exports with a filter and returns the rows by
     * the key column plus the export file path under "_file".
     *
     * @param string[] $columns
     * @param array{0: int, 1: int} $counts created, updated
     * @return array<string, array<string, string>>
     */
    private function roundTrip(string $entity, array $columns, string $csv, array $counts, string $keyColumn, string $filterColumn, string $value, string $operator = 'starts_with'): array
    {
        $profile = $this->profile($entity, $columns);
        $file = $this->file($csv);
        $dry = $this->process($profile, $file, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), $this->report($dry));
        self::assertSame($counts, [$dry->getCount('created'), $dry->getCount('updated')], 'dry run counts');
        $live = $this->process($profile, $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), $this->report($live));
        self::assertSame($counts, [$live->getCount('created'), $live->getCount('updated')], 'live counts');

        $export = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Small export ' . uniqid())
            ->setDirection(ProfileInterface::DIRECTION_EXPORT)
            ->setEntity($entity)
            ->setFormat('csv')
            ->setRowFilters(['mode' => 'include', 'match' => 'all', 'conditions' => [['column' => $filterColumn, 'operator' => $operator, 'value' => $value]]])
            ->setExportOptions(['destination' => 'download']);
        $export = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($export);
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($export, RunInterface::MODE_LIVE, RunInterface::TRIGGER_CLI);
        $run = Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        $path = Bootstrap::getObjectManager()->get(RunFiles::class)->absolute((string) $run->getOutputFile());
        $rows = [];
        foreach (Bootstrap::getObjectManager()->get(ReaderFactory::class)->create($path, 'csv')->rows() as $row) {
            $rows[$row[$keyColumn]] = $row + ['_file' => $path];
        }
        self::assertNotEmpty($rows, 'the export has the imported rows');

        // The exported file is what the shop has: nothing new, nothing changed.
        $first = reset($rows);
        $again = $this->process(
            $this->profile($entity, array_keys(array_diff_key($first === false ? [] : $first, ['_file' => 1]))),
            $path,
            RunInterface::MODE_DRY_RUN
        );
        self::assertSame(RunInterface::STATUS_SUCCESS, $again->getStatus(), $this->report($again));
        self::assertSame(
            [0, 0, count($rows)],
            [$again->getCount('created'), $again->getCount('updated'), $again->getCount('unchanged')],
            'round trip: ' . $again->getMessage()
        );

        return $rows;
    }

    private function delete(string $entity, string $csv, int $expected): void
    {
        $columns = explode(';', trim(strtok($csv, "\n") ?: ''));
        $run = $this->process($this->profile($entity, $columns, ProfileInterface::BEHAVIOR_DELETE), $this->file($csv), RunInterface::MODE_LIVE, ['confirmed' => true]);
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $this->report($run));
        self::assertSame($expected, $run->getCount('deleted'));
    }

    #[DataFixture('Magento/Catalog/_files/product_simple.php')]
    #[DataFixture('Magento/Customer/_files/customer.php')]
    public function testReviewsWithRatings(): void
    {
        $tag = 'OI' . bin2hex(random_bytes(2));
        // Quality and Value are shown in store view 1, Price is not: its stars would never appear.
        $this->ratingVisibility(['Quality' => true, 'Value' => true, 'Price' => false]);
        $header = "product_sku;nickname;title;detail;status;store_codes;created_at;customer_email;ratings\n";
        $rows = $this->roundTrip(
            EntityRegistry::REVIEW,
            explode(';', trim($header)),
            $header
            . "simple;Anna {$tag};Great shirt;Soft and warm.;approved;default;2025-02-03 10:00:00;customer@example.com;Quality:4,Value:5\n"
            . "simple;Ben {$tag};Meh;Too small.;pending;all;;;\n",
            [2, 0],
            'nickname',
            'nickname',
            $tag,
            'contains'
        );
        self::assertCount(2, $rows);
        $anna = $rows["Anna {$tag}"];
        // The test shop has one store view, so "default" is every store view and comes back as "all".
        foreach (['status' => 'approved', 'store_codes' => 'all', 'created_at' => '2025-02-03 10:00:00',
            'customer_email' => 'customer@example.com', 'ratings' => 'Quality:4,Value:5'] as $column => $expected) {
            self::assertSame($expected, $anna[$column], $column . ' of the exported review: ' . json_encode($anna));
        }
        self::assertSame(['pending', 'all', ''], [$rows["Ben {$tag}"]['status'], $rows["Ben {$tag}"]['store_codes'], $rows["Ben {$tag}"]['ratings']]);
        $review = Bootstrap::getObjectManager()->get(ReviewSource::class)->byKey(1, "Anna {$tag}", 'Great shirt');
        self::assertNotNull($review);
        self::assertSame(Review::STATUS_APPROVED, (int) $review['status_id']);
        // Both reviews are shown in the store view ("all" used to save store 0 alone, which shows a review nowhere).
        $shown = Bootstrap::getObjectManager()->create(ReviewCollection::class)
            ->addStoreFilter(1)
            ->addFieldToFilter('detail.nickname', ['in' => ["Anna {$tag}", "Ben {$tag}"]]);
        self::assertCount(2, $shown, 'the imported reviews are visible in store view 1');

        // Update by product, nickname and title: the status changes, the ratings are replaced.
        $update = $this->process(
            $this->profile(EntityRegistry::REVIEW, ['product_sku', 'nickname', 'title', 'status', 'ratings']),
            $this->file("product_sku;nickname;title;status;ratings\nsimple;Anna {$tag};Great shirt;not approved;Quality:2\n")
        );
        self::assertSame(RunInterface::STATUS_SUCCESS, $update->getStatus(), $this->report($update));
        self::assertSame([0, 1], [$update->getCount('created'), $update->getCount('updated')]);
        $row = Bootstrap::getObjectManager()->get(ReviewSource::class)->find("simple|Anna {$tag}|Great shirt");
        self::assertSame(['not approved', 'Quality:2', 'Soft and warm.'], [$row['status'], $row['ratings'], $row['detail']]);

        $bad = $this->process(
            $this->profile(EntityRegistry::REVIEW, explode(';', trim($header))),
            $this->file($header . "nope;X;Y;Z;approved;;;;\nsimple;X;Y;Z;maybe;;;;\nsimple;X;Y;Z;approved;;;;Nope:3\nsimple;X;Y;Z;approved;;;;Quality:9\nsimple;X;Y;;approved;;;;\n"),
            RunInterface::MODE_DRY_RUN
        );
        self::assertSame(RunInterface::STATUS_FAILED, $bad->getStatus());
        $messages = $this->report($bad);
        self::assertStringContainsString('there is no product "nope"', $messages);
        self::assertStringContainsString('"maybe" is not a status', $messages);
        self::assertStringContainsString('there is no rating "Nope"', $messages);
        self::assertStringContainsString('rating Quality has no value 9', $messages);
        self::assertStringContainsString('needs a nickname, a title and a text', $messages);

        // A rating that the review's store views do not show is imported, with a note on the row.
        $hidden = $this->process(
            $this->profile(EntityRegistry::REVIEW, explode(';', trim($header))),
            $this->file($header . "simple;Carl {$tag};Fine;Fits well.;approved;;;;Price:3\n"),
            RunInterface::MODE_DRY_RUN
        );
        self::assertSame(1, $hidden->getCount('created'), $this->report($hidden));
        self::assertStringContainsString('rating Price is not shown in store view default', $this->report($hidden));

        $this->delete(EntityRegistry::REVIEW, "product_sku;nickname;title\nsimple;Anna {$tag};Great shirt\nsimple;Ben {$tag};Meh\n", 2);
        self::assertNull(Bootstrap::getObjectManager()->get(ReviewSource::class)->find("simple|Anna {$tag}|Great shirt"));
    }

    #[DataFixture('Magento/Customer/_files/customer.php')]
    public function testNewsletterSubscribers(): void
    {
        $tag = bin2hex(random_bytes(2));
        $header = "email;store_code;status\n";
        $rows = $this->roundTrip(
            EntityRegistry::NEWSLETTER,
            ['email', 'store_code', 'status'],
            $header . "anna-{$tag}@example.com;default;subscribed\nben-{$tag}@example.com;;unsubscribed\ncustomer@example.com;default;subscribed\n",
            [3, 0],
            'email',
            'email',
            $tag,
            'contains'
        );
        self::assertSame(['default', 'subscribed'], [$rows["anna-{$tag}@example.com"]['store_code'], $rows["anna-{$tag}@example.com"]['status']]);
        self::assertSame('unsubscribed', $rows["ben-{$tag}@example.com"]['status']);
        $source = Bootstrap::getObjectManager()->get(NewsletterSource::class);
        $customer = $source->subscriber('customer@example.com', 1);
        self::assertNotNull($customer);
        self::assertSame(1, (int) $customer['customer_id'], 'the customer with that e-mail is linked');
        self::assertSame(Subscriber::STATUS_SUBSCRIBED, (int) $customer['subscriber_status']);

        $change = $this->process($this->profile(EntityRegistry::NEWSLETTER, ['email', 'store_code', 'status']), $this->file($header . "anna-{$tag}@example.com;default;unsubscribed\n"));
        self::assertSame([0, 1], [$change->getCount('created'), $change->getCount('updated')], $this->report($change));
        self::assertSame(Subscriber::STATUS_UNSUBSCRIBED, (int) $source->subscriber("anna-{$tag}@example.com", 1)['subscriber_status']);

        $bad = $this->process($this->profile(EntityRegistry::NEWSLETTER, ['email', 'store_code', 'status']), $this->file($header . "nobody;default;subscribed\nx@example.com;nowhere;subscribed\ny@example.com;;maybe\n"), RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_FAILED, $bad->getStatus());
        self::assertStringContainsString('"nobody" is not an e-mail address', $this->report($bad));
        self::assertStringContainsString('there is no store view "nowhere"', $this->report($bad));
        self::assertStringContainsString('"maybe" is not a status', $this->report($bad));

        $this->delete(EntityRegistry::NEWSLETTER, "email;store_code\nanna-{$tag}@example.com;default\nben-{$tag}@example.com;\ncustomer@example.com;\n", 3);
        self::assertNull($source->subscriber("anna-{$tag}@example.com", 1));
    }

    public function testSearchTermsAndSynonyms(): void
    {
        $tag = 'oi' . bin2hex(random_bytes(2));
        $header = "query_text;store_code;redirect;display_in_terms;popularity\n";
        $rows = $this->roundTrip(
            EntityRegistry::SEARCH_TERM,
            explode(';', trim($header)),
            $header . "{$tag} shirts;default;https://example.com/shirts;1;120\n{$tag} jackets;;;0;3\n",
            [2, 0],
            'query_text',
            'query_text',
            $tag
        );
        self::assertSame(['https://example.com/shirts', '1', '120'], [$rows["{$tag} shirts"]['redirect'], $rows["{$tag} shirts"]['display_in_terms'], $rows["{$tag} shirts"]['popularity']]);
        self::assertSame(['', '0', '3'], [$rows["{$tag} jackets"]['redirect'], $rows["{$tag} jackets"]['display_in_terms'], $rows["{$tag} jackets"]['popularity']]);
        $update = $this->process($this->profile(EntityRegistry::SEARCH_TERM, ['query_text', 'redirect']), $this->file("query_text;redirect\n{$tag} shirts;\n"));
        self::assertSame([0, 1], [$update->getCount('created'), $update->getCount('updated')], $this->report($update));
        self::assertSame('', Bootstrap::getObjectManager()->get(SearchTermSource::class)->find("{$tag} shirts")['redirect'] ?? 'x');
        $this->delete(EntityRegistry::SEARCH_TERM, "query_text;store_code\n{$tag} shirts;default\n{$tag} jackets;\n", 2);
        self::assertNull(Bootstrap::getObjectManager()->get(SearchTermSource::class)->find("{$tag} shirts"));

        $rows = $this->roundTrip(
            EntityRegistry::SEARCH_SYNONYM,
            ['synonyms', 'scope'],
            "synonyms;scope\n{$tag}shirt,{$tag}tee,{$tag}top;all\n{$tag}jacket, {$tag}coat;store:default\n",
            [2, 0],
            'synonyms',
            'synonyms',
            $tag
        );
        self::assertSame('all', $rows["{$tag}shirt,{$tag}tee,{$tag}top"]['scope']);
        self::assertSame('store:default', $rows["{$tag}jacket,{$tag}coat"]['scope']);
        // A word of an existing group of the same scope: the groups are merged, as the admin does.
        $merge = $this->process($this->profile(EntityRegistry::SEARCH_SYNONYM, ['synonyms', 'scope']), $this->file("synonyms;scope\n{$tag}top,{$tag}blouse;all\n"));
        self::assertSame([0, 1], [$merge->getCount('created'), $merge->getCount('updated')], $this->report($merge));
        $group = Bootstrap::getObjectManager()->get(SynonymSource::class)->group(["{$tag}blouse"], 0, 0);
        self::assertNotNull($group);
        self::assertStringContainsString("{$tag}shirt", (string) $group['synonyms']);
        self::assertStringContainsString("{$tag}blouse", (string) $group['synonyms']);
        $bad = $this->process($this->profile(EntityRegistry::SEARCH_SYNONYM, ['synonyms', 'scope']), $this->file("synonyms;scope\nalone;all\na,b;store:nowhere\n"), RunInterface::MODE_DRY_RUN);
        self::assertStringContainsString('needs at least two words', $this->report($bad));
        self::assertStringContainsString('is not a scope', $this->report($bad));
        $this->delete(EntityRegistry::SEARCH_SYNONYM, "synonyms;scope\n{$tag}shirt;all\n{$tag}jacket;store:default\n", 2);
        self::assertNull(Bootstrap::getObjectManager()->get(SynonymSource::class)->group(["{$tag}shirt"], 0, 0));
    }

    #[DataFixture('Magento/Catalog/_files/product_simple.php')]
    #[DataFixture('Magento/Catalog/_files/category.php')]
    public function testCategoryPositions(): void
    {
        $tree = Bootstrap::getObjectManager()->get(CategoryTree::class);
        $tree->reset();
        $path = $tree->path(333);
        self::assertNotNull($path, 'the category fixture (333) has a path');
        $source = Bootstrap::getObjectManager()->get(CategoryPositionSource::class);
        $before = $source->position(333, 1);

        $rows = $this->roundTrip(
            EntityRegistry::CATEGORY_POSITION,
            ['category_path', 'product_sku', 'position'],
            "category_path;product_sku;position\n{$path};simple;7\n",
            [$before === null ? 1 : 0, $before === null ? 0 : 1],
            'product_sku',
            'category_path',
            $path,
            'equals'
        );
        self::assertSame('7', $rows['simple']['position']);
        self::assertSame(7, $source->position(333, 1));

        $bad = $this->process($this->profile(EntityRegistry::CATEGORY_POSITION, ['category_path', 'product_sku', 'position']), $this->file("category_path;product_sku;position\nNo/Such;simple;1\n{$path};nope;1\n{$path};simple;first\n"), RunInterface::MODE_DRY_RUN);
        self::assertStringContainsString('there is no category "No/Such"', $this->report($bad));
        self::assertStringContainsString('there is no product "nope"', $this->report($bad));
        self::assertStringContainsString('"first" is not a whole number', $this->report($bad));

        $this->delete(EntityRegistry::CATEGORY_POSITION, "category_path;product_sku\n{$path};simple\n", 1);
        self::assertNull($source->position(333, 1));
        Bootstrap::getObjectManager()->get(SmallRecords::class);
    }
}
