<?php

namespace Evntaly\Export;

use League\Csv\Reader;
use League\Csv\Writer;

class ExportManager
{
    /**
     * Export events to CSV format.
     *
     * @param  array             $events   The events to export
     * @param  string            $filePath Output file path (or null for string output)
     * @param  array             $options  Export options
     * @return string|bool       CSV content as string or true if file was written
     * @throws \RuntimeException When League/CSV is not installed
     */
    public function exportToCsv(array $events, ?string $filePath = null, array $options = []): string|bool
    {
        if (!class_exists('League\Csv\Writer')) {
            throw new \RuntimeException('League/CSV library is required for CSV exports. Install it with: composer require league/csv');
        }

        // Default field mapping
        $fields = $options['fields'] ?? [
            'id' => 'ID',
            'title' => 'Title',
            'description' => 'Description',
            'timestamp' => 'Timestamp',
            'type' => 'Type',
            'tags' => 'Tags',
        ];

        // Create CSV writer
        $csv = Writer::createFromString('');

        // Set delimiter
        $csv->setDelimiter($options['delimiter'] ?? ',');

        // Add headers
        $csv->insertOne(array_values($fields));

        // Process events
        $records = [];
        foreach ($events as $event) {
            $record = [];
            foreach ($fields as $field => $header) {
                if ($field === 'tags' && isset($event['tags']) && is_array($event['tags'])) {
                    $record[] = implode(', ', $event['tags']);
                } elseif ($field === 'data' && isset($event['data']) && is_array($event['data'])) {
                    $record[] = json_encode($event['data']);
                } elseif (isset($event[$field])) {
                    $record[] = $event[$field];
                } else {
                    $record[] = '';
                }
            }
            $records[] = $record;
        }

        // Insert all records
        $csv->insertAll($records);

        // Output to file or return as string
        if ($filePath) {
            file_put_contents($filePath, $csv->getContent());
            return true;
        }

        return $csv->getContent();
    }

    /**
     * Export events to JSON format.
     *
     * @param  array       $events   The events to export
     * @param  string      $filePath Output file path (or null for string output)
     * @param  array       $options  Export options
     * @return string|bool JSON content as string or true if file was written
     */
    public function exportToJson(array $events, ?string $filePath = null, array $options = []): string|bool
    {
        $jsonOptions = JSON_PRETTY_PRINT;
        if (isset($options['pretty']) && !$options['pretty']) {
            $jsonOptions = 0;
        }

        $json = json_encode($events, $jsonOptions);

        if ($filePath) {
            file_put_contents($filePath, $json);
            return true;
        }

        return $json;
    }

    /**
     * Import events from CSV file.
     *
     * @param  string            $filePath Path to CSV file
     * @param  array             $options  Import options
     * @return array             The imported events
     * @throws \RuntimeException When League/CSV is not installed
     */
    public function importFromCsv(string $filePath, array $options = []): array
    {
        if (!class_exists('League\Csv\Reader')) {
            throw new \RuntimeException('League/CSV library is required for CSV imports. Install it with: composer require league/csv');
        }

        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setHeaderOffset(0);

        $fieldMap = array_flip($options['fieldMap'] ?? [
            'ID' => 'id',
            'Title' => 'title',
            'Description' => 'description',
            'Timestamp' => 'timestamp',
            'Type' => 'type',
            'Tags' => 'tags',
        ]);

        $events = [];
        foreach ($csv as $record) {
            $event = [];

            foreach ($record as $header => $value) {
                $field = $fieldMap[$header] ?? strtolower($header);

                if ($field === 'tags' && !empty($value)) {
                    $event[$field] = array_map('trim', explode(',', $value));
                } elseif ($field === 'data' && !empty($value)) {
                    $event[$field] = json_decode($value, true) ?? [];
                } else {
                    $event[$field] = $value;
                }
            }

            $events[] = $event;
        }

        return $events;
    }

    /**
     * Import events from JSON file.
     *
     * @param  string $filePath Path to JSON file
     * @param  array  $options  Import options
     * @return array  The imported events
     */
    public function importFromJson(string $filePath, array $options = []): array
    {
        $json = file_get_contents($filePath);
        $events = json_decode($json, true);

        if (!is_array($events)) {
            throw new \InvalidArgumentException('Invalid JSON format');
        }

        return $events;
    }

    /**
     * Import events from a third-party analytics platform.
     *
     * @param  string $platform Platform name ('google_analytics', 'mixpanel', etc.)
     * @param  array  $data     Platform-specific export data
     * @return array  The imported events
     */
    public function importFromPlatform(string $platform, array $data): array
    {
        switch (strtolower($platform)) {
            case 'google_analytics':
                return $this->importFromGoogleAnalytics($data);

            case 'mixpanel':
                return $this->importFromMixpanel($data);

            default:
                throw new \InvalidArgumentException("Unsupported platform: $platform");
        }
    }

    /**
     * Import events from Google Analytics.
     */
    private function importFromGoogleAnalytics(array $data): array
    {
        $events = [];

        foreach ($data as $record) {
            if (isset($record['eventCategory'], $record['eventAction'])) {
                $events[] = [
                    'title' => $record['eventAction'],
                    'description' => $record['eventLabel'] ?? '',
                    'data' => [
                        'category' => $record['eventCategory'],
                        'value' => $record['eventValue'] ?? null,
                        'ga_session_id' => $record['sessionId'] ?? null,
                        'ga_client_id' => $record['clientId'] ?? null,
                    ],
                    'type' => 'analytics',
                    'tags' => ['imported', 'google_analytics', $record['eventCategory']],
                ];
            }
        }

        return $events;
    }

    /**
     * Import events from Mixpanel.
     */
    private function importFromMixpanel(array $data): array
    {
        $events = [];

        foreach ($data as $record) {
            if (isset($record['event'], $record['properties'])) {
                $events[] = [
                    'title' => $record['event'],
                    'description' => 'Imported from Mixpanel',
                    'data' => $record['properties'],
                    'timestamp' => isset($record['properties']['time'])
                        ? date('c', $record['properties']['time'])
                        : null,
                    'type' => 'analytics',
                    'tags' => ['imported', 'mixpanel'],
                ];
            }
        }

        return $events;
    }
}
