<?php
namespace VHONA\ClientPortal;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\WP_CLI_Command')) {
    return;
}

/**
 * WP-CLI helpers for inspecting activity logs.
 */
class Activity_CLI_Command extends \WP_CLI_Command
{
    /**
     * @var ActivityLogger
     */
    private $logger;

    public function __construct()
    {
        $this->logger = new ActivityLogger();
    }

    /**
     * List recent activity log entries with optional filters.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Filter entries by the stored log type.
     *
     * [--search=<term>]
     * : Limit results to messages matching the provided search term.
     *
     * [--after=<date>]
     * : Only include entries created after this date (Y-m-d or any strtotime-compatible string).
     *
     * [--before=<date>]
     * : Only include entries created before this date.
     *
     * [--page=<number>]
     * : Page number to retrieve. Defaults to 1.
     *
     * [--per-page=<number>]
     * : Number of entries per page. Defaults to 20.
     *
     * [--format=<format>]
     * : Render the results in a particular format. One of table, json, csv, yaml.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp vhona-portal logs list --type=rest_post --after="2024-01-01"
     *
     * @subcommand list
     */
    public function list_($args, $assoc_args)
    {
        $filters = $this->build_filters($assoc_args);
        $entries = $this->logger->get_entries($filters);

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        $rows = array_map(function ($entry) {
            $entry['context'] = wp_json_encode($entry['context']);
            return $entry;
        }, $entries);

        \WP_CLI\Utils\format_items($format, $rows, ['id', 'type', 'actor', 'message', 'created', 'context']);
    }

    /**
     * Export log entries to CSV, optionally writing to a file.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Filter entries by type before exporting.
     *
     * [--search=<term>]
     * : Only include entries whose message matches the search term.
     *
     * [--after=<date>]
     * : Only export entries created after this date.
     *
     * [--before=<date>]
     * : Only export entries created before this date.
     *
     * [--limit=<number>]
     * : Maximum number of entries to export (defaults to 200).
     *
     * [--file=<path>]
     * : Optional path to write the CSV to. If omitted the CSV is printed to STDOUT.
     *
     * ## EXAMPLES
     *
     *     wp vhona-portal logs export --type=document_upload --limit=500 --file=/tmp/logs.csv
     */
    public function export($args, $assoc_args)
    {
        $filters = $this->build_filters($assoc_args);
        $filters['posts_per_page'] = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 200;

        if ($filters['posts_per_page'] < 1) {
            $filters['posts_per_page'] = 200;
        }

        $csv = $this->logger->to_csv($filters);

        if (empty($assoc_args['file'])) {
            \WP_CLI::line($csv);
            \WP_CLI::success(__('Export complete. Output written to STDOUT.', 'vhona-client-portal'));
            return;
        }

        $path = wp_normalize_path($assoc_args['file']);

        if (false === file_put_contents($path, $csv)) {
            \WP_CLI::error(sprintf(__('Unable to write log export to %s', 'vhona-client-portal'), $path));
        }

        \WP_CLI::success(sprintf(__('Exported activity log to %s', 'vhona-client-portal'), $path));
    }

    /**
     * Convert CLI arguments into ActivityLogger filters.
     *
     * @param array $assoc_args Raw CLI options.
     *
     * @return array
     */
    private function build_filters(array $assoc_args)
    {
        $filters = [
            'type' => isset($assoc_args['type']) ? sanitize_key($assoc_args['type']) : '',
            'search' => isset($assoc_args['search']) ? sanitize_text_field($assoc_args['search']) : '',
            'after' => isset($assoc_args['after']) ? sanitize_text_field($assoc_args['after']) : '',
            'before' => isset($assoc_args['before']) ? sanitize_text_field($assoc_args['before']) : '',
            'posts_per_page' => isset($assoc_args['per-page']) ? (int) $assoc_args['per-page'] : 20,
            'paged' => isset($assoc_args['page']) ? (int) $assoc_args['page'] : 1,
        ];

        if ($filters['posts_per_page'] < 1) {
            $filters['posts_per_page'] = 20;
        }

        if ($filters['paged'] < 1) {
            $filters['paged'] = 1;
        }

        return $filters;
    }
}
