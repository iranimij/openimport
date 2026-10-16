<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

/**
 * Finds the form field a save problem belongs to, so the form can open that step and show the message at the field.
 */
class ProblemLocator
{
    /**
     * Message pattern => form field (the name in openimport_profile_form.xml); the first match wins.
     */
    private const PATTERNS = [
        '/^(Give the profile a name|The name is longer)/' => 'name',
        '/^The direction /' => 'direction',
        '/^(Choose what to import or export|Magento has no )/' => 'entity',
        '/^The safety threshold/' => 'safety_threshold',
        '/^An uploaded file cannot run on a schedule/' => 'schedule_preset',
        '/^A scheduled export needs a destination/' => 'export_destination',
        '/^(A schedule needs 5 fields|The (minute|hour|day of month|month|day of week) )/' => 'schedule_custom',
        '/(only updates existing records|cannot be imported with "Replace")/' => 'behavior',
        '/^(Enter the server folder to read from|The folder may not contain)/' => 'source_path',
        '/^(Enter the web address|The web address must)/' => 'source_url',
        '/^Enter the user name for the login/' => 'source_username_url',
        '/^The saved connection /' => 'source_connection',
        '/^Choose a saved S3 connection for the destination/' => 'dest_connection',
        '/^Choose a saved S3 connection/' => 'source_connection',
        '/^Enter the server \(host name\)\.$/' => 'source_host',
        '/^Enter the user name for the server/' => 'source_username',
        '/^The port must be/' => 'source_port',
        '/ is not an export destination/' => 'export_destination',
        '/^Enter the server folder to write to/' => 'dest_path_folder',
        '/^Enter the server \(host name\) of the destination/' => 'dest_host',
        '/^Enter the user name for the destination/' => 'dest_username',
        '/^Enter at least one e-mail address to send the file/' => 'dest_emails',
        '/^OpenImport cannot write /' => 'format',
        '/ is not a way to choose the file/' => 'source_pick',
        '/^The filter match must/' => 'filter_match',
        '/^Filter \d+: /' => 'row_filters',
    ];

    /**
     * @param string[] $problems messages of the profile validator
     * @param array<string, mixed> $data the posted form
     * @return array<string, string> form field => its messages (one per line)
     */
    public function locate(array $problems, array $data): array
    {
        $fields = [];
        $destinationEmails = (string) ($data['export_options']['emails'] ?? '');
        $daily = ($data['schedule_preset'] ?? '') === ProfileFormMapper::SCHEDULE_DAILY;
        foreach ($problems as $problem) {
            $field = $this->field($problem, $destinationEmails);
            if ($field === 'schedule_custom' && $daily) {
                $field = 'schedule_time';
            }
            if ($field === 'source_connection' && ($data['direction'] ?? '') === 'export') {
                $field = 'dest_connection';
            }
            if ($field !== '') {
                $fields[$field] = isset($fields[$field]) ? $fields[$field] . "\n" . $problem : $problem;
            }
        }

        return $fields;
    }

    /**
     * @param string $problem
     * @param string $destinationEmails
     * @return string the field, '' when the problem belongs to no single field
     */
    private function field(string $problem, string $destinationEmails): string
    {
        if (preg_match('/^"(.*)" is not an e-mail address\.$/', $problem, $match)) {
            return $match[1] !== '' && str_contains($destinationEmails, $match[1]) ? 'dest_emails' : 'notify_emails';
        }
        foreach (self::PATTERNS as $pattern => $field) {
            if (preg_match($pattern, $problem)) {
                return $field;
            }
        }
        // Mapping problems start with the field they are about ("price: ...").
        if (preg_match('/^[\w .()\/-]{1,80}: /u', $problem)) {
            return 'mapping';
        }

        return '';
    }
}
