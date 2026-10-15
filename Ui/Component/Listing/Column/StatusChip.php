<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;

/**
 * A run status as a labelled chip (text, not colour alone).
 */
class StatusChip
{
    private const SEVERITY = [
        'queued' => 'minor',
        'running' => 'minor',
        'success' => 'notice',
        'partial' => 'major',
        'failed' => 'critical',
        'needs_confirmation' => 'major',
        'cancelled' => 'minor',
    ];

    /**
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @param string $status
     * @return string HTML
     */
    public function html(string $status): string
    {
        return sprintf(
            '<span class="grid-severity-%s"><span>%s</span></span>',
            self::SEVERITY[$status] ?? 'minor',
            $this->escaper->escapeHtml($this->label($status))
        );
    }

    /**
     * The status as a compact chip for page headers (the grid style is a full-width bar outside a grid).
     *
     * @param string $status
     * @return string HTML
     */
    public function chip(string $status): string
    {
        return sprintf(
            '<span class="openimport-chip openimport-chip-%s">%s</span>',
            self::SEVERITY[$status] ?? 'minor',
            $this->escaper->escapeHtml($this->label($status))
        );
    }

    /**
     * @param string $status
     * @return string
     */
    public function label(string $status): string
    {
        return (string) match ($status) {
            'queued' => __('Queued'),
            'running' => __('Running'),
            'success' => __('Success'),
            'partial' => __('Partial'),
            'failed' => __('Failed'),
            'needs_confirmation' => __('Needs confirmation'),
            'cancelled' => __('Cancelled'),
            default => $status,
        };
    }
}
