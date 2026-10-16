<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model;

use Iranimij\Base\Model\Config\TypedReader;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * OpenImport settings (Stores › Configuration › iranimij › OpenImport). All settings live in the default scope.
 */
class Config
{
    public const XML_KEEP_FILES_DAYS = 'openimport/general/keep_files_days';
    public const XML_MAX_ERRORS_PER_RUN = 'openimport/general/max_errors_per_run';
    public const XML_CRON_TIME_BUDGET = 'openimport/general/cron_time_budget';
    public const XML_ALLOWED_HOSTS = 'openimport/security/allowed_hosts';
    public const XML_ALLOWED_FOLDERS = 'openimport/security/allowed_folders';
    public const XML_FORMULA_GUARD = 'openimport/security/formula_guard';
    public const XML_SAFETY_THRESHOLD = 'openimport/security/safety_threshold_percent';
    public const XML_EMAIL_IDENTITY = 'openimport/general/email_identity';
    public const XML_IMAGE_REFRESH_HOURS = 'openimport/images/refresh_hours';
    public const XML_IMAGE_MAX_MB = 'openimport/images/max_mb';
    public const XML_IMAGE_TIMEOUT = 'openimport/images/timeout';
    public const XML_IMAGE_KEEP_DAYS = 'openimport/images/keep_days';

    /**
     * @param TypedReader $reader
     */
    public function __construct(
        private readonly TypedReader $reader
    ) {
    }

    /**
     * Days run files are kept (at least 1).
     *
     * @return int
     */
    public function getKeepFilesDays(): int
    {
        return max(1, $this->int(self::XML_KEEP_FILES_DAYS, 14));
    }

    /**
     * Maximum number of row errors stored per run (at least 1).
     *
     * @return int
     */
    public function getMaxErrorsPerRun(): int
    {
        return max(1, $this->int(self::XML_MAX_ERRORS_PER_RUN, 10000));
    }

    /**
     * Seconds one cron pass may spend starting runs (at least 10).
     *
     * @return int
     */
    public function getCronTimeBudget(): int
    {
        return max(10, $this->int(self::XML_CRON_TIME_BUDGET, 240));
    }

    /**
     * Private host names an administrator allowed as URL sources, lower case.
     *
     * @return string[]
     */
    public function getAllowedHosts(): array
    {
        return array_values(array_unique(array_map('strtolower', $this->lines(self::XML_ALLOWED_HOSTS))));
    }

    /**
     * Extra absolute server folders allowed for folder sources, without trailing slash.
     *
     * @return string[]
     */
    public function getAllowedFolders(): array
    {
        $folders = array_map(static fn (string $folder): string => rtrim($folder, '/'), $this->lines(self::XML_ALLOWED_FOLDERS));

        return array_values(array_filter($folders, static fn (string $folder): bool => str_starts_with($folder, '/')));
    }

    /**
     * Whether exported cells that look like spreadsheet formulas are neutralised.
     *
     * @return bool
     */
    public function isFormulaGuardEnabled(): bool
    {
        return $this->reader->getBool(self::XML_FORMULA_GUARD, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    /**
     * Store e-mail address that sends run e-mails and exports by e-mail (general, sales, support, custom1, custom2).
     *
     * @return string
     */
    public function getEmailIdentity(): string
    {
        $identity = trim($this->reader->getString(self::XML_EMAIL_IDENTITY, ScopeConfigInterface::SCOPE_TYPE_DEFAULT));

        return $identity !== '' ? $identity : 'general';
    }

    /**
     * Share of an entity's records (1–100 %) that Replace and Delete may touch without confirmation.
     *
     * @return int
     */
    public function getSafetyThresholdPercent(): int
    {
        return min(100, max(1, $this->int(self::XML_SAFETY_THRESHOLD, 10)));
    }

    /**
     * Hours a downloaded picture is trusted before its address is asked again whether it changed (0 = every run).
     *
     * @return int
     */
    public function getImageRefreshHours(): int
    {
        return max(0, $this->int(self::XML_IMAGE_REFRESH_HOURS, 24));
    }

    /**
     * Largest picture a run downloads, in bytes (1–100 MB).
     *
     * @return int
     */
    public function getImageMaxBytes(): int
    {
        return min(100, max(1, $this->int(self::XML_IMAGE_MAX_MB, 10))) * 1048576;
    }

    /**
     * Seconds one picture download may take (5–300).
     *
     * @return int
     */
    public function getImageTimeout(): int
    {
        return min(300, max(5, $this->int(self::XML_IMAGE_TIMEOUT, 30)));
    }

    /**
     * Days a downloaded picture no run used stays in the picture cache (at least 1).
     *
     * @return int
     */
    public function getImageKeepDays(): int
    {
        return max(1, $this->int(self::XML_IMAGE_KEEP_DAYS, 30));
    }

    /**
     * Integer setting from the default scope, with a fallback for an empty or non-numeric value.
     *
     * @param string $path
     * @param int $fallback
     * @return int
     */
    private function int(string $path, int $fallback): int
    {
        $raw = trim($this->reader->getString($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT));

        return is_numeric($raw) ? (int) $raw : $fallback;
    }

    /**
     * Non-empty trimmed lines of a textarea setting.
     *
     * @param string $path
     * @return string[]
     */
    private function lines(string $path): array
    {
        $raw = $this->reader->getString($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $lines = array_map('trim', preg_split('/\R/', $raw) ?: []);

        return array_values(array_unique(array_filter($lines, static fn (string $line): bool => $line !== '')));
    }
}
