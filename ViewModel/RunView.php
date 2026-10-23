<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\ViewModel;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Iranimij\OpenImport\Model\Config\Source\RunTrigger;
use Iranimij\OpenImport\Model\Execution\ConfirmationWord;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Iranimij\OpenImport\Model\Storage\ErrorRecord;
use Iranimij\OpenImport\Model\Storage\PreviewRecord;
use Iranimij\OpenImport\Model\Storage\PreviewStore;
use Iranimij\OpenImport\Ui\Component\Listing\Column\StatusChip;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Everything the run page shows.
 */
class RunView implements ArgumentInterface
{
    public const ERRORS_SHOWN = 100;
    public const PREVIEW_SHOWN = 200;

    private ?RunInterface $run = null;

    private ?ProfileInterface $profile = null;

    private bool $profileLoaded = false;

    /**
     * @param RequestInterface $request
     * @param RunRepositoryInterface $runs
     * @param ProfileRepositoryInterface $profiles
     * @param ErrorLog $errors
     * @param PreviewStore $preview
     * @param StatusChip $chip
     * @param UrlInterface $urlBuilder
     * @param AuthorizationInterface $authorization
     * @param RunTrigger $triggers
     * @param Escaper $escaper
     * @param RunFiles $runFiles
     * @param ReaderFactory $readers
     * @param ConfirmationWord $confirmationWord
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RunRepositoryInterface $runs,
        private readonly ProfileRepositoryInterface $profiles,
        private readonly ErrorLog $errors,
        private readonly PreviewStore $preview,
        private readonly StatusChip $chip,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization,
        private readonly RunTrigger $triggers,
        private readonly Escaper $escaper,
        private readonly RunFiles $runFiles,
        private readonly ReaderFactory $readers,
        private readonly ConfirmationWord $confirmationWord
    ) {
    }

    /**
     * The first rows of the file an export wrote, read back as a merchant's tool would read it.
     *
     * @param int $limit
     * @return array{columns: string[], rows: array<int, array<string, string>>}
     */
    public function exportRows(int $limit = 10): array
    {
        $run = $this->run();
        $empty = ['columns' => [], 'rows' => []];
        // The rows are file contents: only for users allowed to download run files.
        if ($run === null || $run->getDirection() !== 'export' || $run->getOutputFile() === null
            || !$this->canDownloadFiles()) {
            return $empty;
        }
        try {
            $reader = $this->readers->create($this->runFiles->absolute((string) $run->getOutputFile()));
            $rows = [];
            foreach ($reader->rows() as $row) {
                $rows[] = array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '', $row);
                if (count($rows) >= $limit) {
                    break;
                }
            }

            return ['columns' => array_map('strval', $reader->columns()), 'rows' => $rows];
        } catch (\Throwable $exception) {
            // A file that cannot be read back (deleted by housekeeping, unknown format) is simply not shown.
            return $empty;
        }
    }

    /**
     * "Admin · openimport", "Schedule" …, as in the runs grid.
     *
     * @return string
     */
    public function startedBy(): string
    {
        $run = $this->run();

        return $run === null ? '' : $this->triggers->describe($run->getTrigger(), $run->getRequestedBy());
    }

    public function run(): ?RunInterface
    {
        if ($this->run === null) {
            try {
                $this->run = $this->runs->getById((int) $this->request->getParam('id'));
            } catch (NoSuchEntityException $exception) {
                return null;
            }
        }

        return $this->run;
    }

    public function profile(): ?ProfileInterface
    {
        $run = $this->run();
        if (!$this->profileLoaded && $run !== null && $run->getProfileId() !== null) {
            $this->profileLoaded = true;
            try {
                $this->profile = $this->profiles->getById($run->getProfileId());
            } catch (NoSuchEntityException $exception) {
                $this->profile = null;
            }
        }

        return $this->profile;
    }

    public function isActive(): bool
    {
        $run = $this->run();

        return $run !== null && in_array($run->getStatus(), [RunInterface::STATUS_QUEUED, RunInterface::STATUS_RUNNING], true);
    }

    public function statusHtml(): string
    {
        return $this->chip->html((string) $this->run()?->getStatus());
    }

    /**
     * @return string HTML
     */
    public function statusChipHtml(): string
    {
        return $this->chip->chip((string) $this->run()?->getStatus());
    }

    /**
     * One changed field of the dry-run preview: "field: before → after", or "field = value" for a new value.
     *
     * @param string $field
     * @param mixed $before
     * @param mixed $after
     * @return string HTML
     */
    public function changeHtml(string $field, mixed $before, mixed $after): string
    {
        $text = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '';
        $name = '<code>' . $this->escaper->escapeHtml($field) . '</code>';
        if ($text($before) === '') {
            return $name . ' = ' . $this->escaper->escapeHtml($text($after));
        }

        return $name . ': <del>' . $this->escaper->escapeHtml($text($before)) . '</del> → <ins>'
            . $this->escaper->escapeHtml($text($after)) . '</ins>';
    }

    /**
     * Counts worth showing, as label => number, in the order merchants read them.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $run = $this->run();
        if ($run === null) {
            return [];
        }
        $dry = $run->isDryRun();
        $labels = [
            'read' => (string) __('Rows read'),
            'created' => (string) ($dry ? __('Will be created') : __('Created')),
            'updated' => (string) ($dry ? __('Will change') : __('Updated')),
            'deleted' => (string) ($dry ? __('Will be deleted') : __('Deleted')),
            'unchanged' => (string) __('Unchanged'),
            'filtered' => (string) __('Skipped by filters'),
            'skipped' => (string) __('Skipped by behaviour'),
            'exported' => (string) __('Exported'),
            'errors' => (string) __('Errors'),
        ];
        $counts = [];
        foreach ($labels as $name => $label) {
            $value = $run->getCount($name);
            if ($value > 0 || in_array($name, ['read', 'errors'], true)) {
                $counts[$label] = $value;
            }
        }

        return $counts;
    }

    /**
     * @return PreviewRecord[]
     */
    public function previewRecords(): array
    {
        $run = $this->run();

        return $run === null || !$run->isDryRun() ? [] : $this->preview->fetch((int) $run->getRunId(), self::PREVIEW_SHOWN);
    }

    public function changeLabel(string $type): string
    {
        return (string) match ($type) {
            PreviewRecord::NEW => __('New'),
            PreviewRecord::CHANGED => __('Changes'),
            PreviewRecord::DELETED => __('Deleted'),
            default => __('Unchanged'),
        };
    }

    /**
     * @return ErrorRecord[]
     */
    public function errors(): array
    {
        $run = $this->run();

        return $run === null ? [] : $this->errors->fetch((int) $run->getRunId(), self::ERRORS_SHOWN);
    }

    /**
     * "Run for real" after a successful dry run.
     */
    public function canRunForReal(): bool
    {
        $run = $this->run();

        return $run !== null && $run->isDryRun() && $this->profile() !== null
            && in_array($run->getStatus(), [RunInterface::STATUS_SUCCESS, RunInterface::STATUS_PARTIAL], true)
            && $this->authorization->isAllowed('Iranimij_OpenImport::run');
    }

    /**
     * The word to type before a run above the safety limit, or '' when none is needed.
     */
    public function confirmationWord(): string
    {
        $run = $this->run();
        $profile = $this->profile();

        return $run === null || $profile === null ? '' : $this->confirmationWord->forRun($run, $profile);
    }

    public function canConfirm(): bool
    {
        return $this->confirmationWord() !== ''
            && $this->authorization->isAllowed('Iranimij_OpenImport::run_destructive');
    }

    public function canCancel(): bool
    {
        return in_array($this->run()?->getStatus(), [RunInterface::STATUS_QUEUED, RunInterface::STATUS_RUNNING], true)
            && $this->authorization->isAllowed('Iranimij_OpenImport::run')
            && !$this->isWriting();
    }

    /**
     * A running live run that writes to Magento (or delivers its export) now: it cannot be cancelled any more.
     *
     * @return bool
     */
    public function isWriting(): bool
    {
        $run = $this->run();

        return $run !== null && $run->getStatus() === RunInterface::STATUS_RUNNING
            && $this->runFiles->isWriting((int) $run->getRunId());
    }

    /**
     * "Run again" for a finished run whose profile still exists: the same mode, a fresh read of the source.
     *
     * @return bool
     */
    /**
     * Whether the admin may download the run's files (they can hold customer and order data).
     */
    public function canDownloadFiles(): bool
    {
        return $this->authorization->isAllowed('Iranimij_OpenImport::run_files');
    }

    /**
     * @return bool
     */
    public function canRunAgain(): bool
    {
        $run = $this->run();

        return $run !== null && $this->profile() !== null
            && in_array($run->getStatus(), RunInterface::FINAL_STATUSES, true)
            && $this->authorization->isAllowed('Iranimij_OpenImport::run');
    }

    /**
     * @param string $route
     * @param array<string, mixed> $params
     * @return string
     */
    public function url(string $route, array $params = []): string
    {
        return $this->urlBuilder->getUrl($route, $params + ['id' => (int) $this->run()?->getRunId()]);
    }

    public function profileUrl(): string
    {
        return $this->urlBuilder->getUrl('openimport/profile/edit', ['id' => (int) $this->run()?->getProfileId()]);
    }

    public function duration(): string
    {
        $run = $this->run();
        $started = $run?->getStartedAt() !== null ? strtotime($run->getStartedAt() . ' UTC') : false;
        $finished = $run?->getFinishedAt() !== null ? strtotime($run->getFinishedAt() . ' UTC') : false;
        if ($started === false || $finished === false) {
            return '';
        }
        $seconds = max(0, $finished - $started);

        return $seconds >= 60 ? sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60) : sprintf('%d s', $seconds);
    }
}
