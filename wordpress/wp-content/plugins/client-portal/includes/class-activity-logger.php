<?php
namespace VHONA\ClientPortal;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persists lightweight activity records so administrators can audit syncs and uploads.
 */
class ActivityLogger
{
    const POST_TYPE = 'vhona_client_activity';
    const META_TYPE = '_vhona_log_type';

    /**
     * Register the hidden custom post type used for log entries.
     */
    public function register_post_type()
    {
        register_post_type(
            self::POST_TYPE,
            [
                'labels' => [
                    'name' => __('Client Portal Activity', 'vhona-client-portal'),
                    'singular_name' => __('Client Portal Activity', 'vhona-client-portal'),
                ],
                'public' => false,
                'show_ui' => false,
                'show_in_menu' => false,
                'supports' => ['title', 'editor', 'author'],
                'capability_type' => 'post',
                'map_meta_cap' => true,
            ]
        );
    }

    /**
     * Persist a new activity entry.
     *
     * @param string $type    Machine readable type slug.
     * @param string $message Short human description.
     * @param array  $context Additional details stored as JSON.
     *
     * @return int|\WP_Error
     */
    public function log($type, $message, array $context = [])
    {
        $type = sanitize_key($type);
        $actor = isset($context['actor']) ? (int) $context['actor'] : get_current_user_id();

        $postarr = [
            'post_type'   => self::POST_TYPE,
            'post_title'  => sanitize_text_field($message ?: ucfirst(str_replace('_', ' ', $type))),
            'post_content'=> wp_json_encode($this->prepare_context($context)),
            'post_status' => 'publish',
            'post_author' => $actor > 0 ? $actor : 0,
        ];

        $log_id = wp_insert_post($postarr, true);

        if (is_wp_error($log_id)) {
            return $log_id;
        }

        update_post_meta($log_id, self::META_TYPE, $type ?: 'general');

        return (int) $log_id;
    }

    /**
     * Retrieve activity entries formatted for display/export.
     *
     * @param array $args Optional overrides. Supports `type`, `paged`, `posts_per_page`, and `include_meta`.
     *
     * @return array<mixed>
     */
    public function get_entries($args = [])
    {
        $include_meta = !empty($args['include_meta']);
        unset($args['include_meta']);

        $query = new \WP_Query($this->build_query_args($args));

        $entries = [];

        foreach ($query->posts as $post) {
            $entries[] = $this->prepare_entry($post);
        }

        if (!$include_meta) {
            return $entries;
        }

        return [
            'entries'    => $entries,
            'total'      => (int) $query->found_posts,
            'max_pages'  => (int) $query->max_num_pages,
            'page'       => (int) max(1, $query->get('paged')),
        ];
    }

    /**
     * Fetch the distinct activity types stored in the log.
     *
     * @return array<int, string>
     */
    public function get_types()
    {
        global $wpdb;

        $meta_key = self::META_TYPE;
        $types = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_value ASC",
                $meta_key
            )
        );

        $types = array_filter(array_map('sanitize_key', (array) $types));

        return array_values(array_unique($types));
    }

    /**
     * Convert the latest entries to CSV text.
     *
     * @return string
     */
    public function to_csv()
    {
        $rows   = [
            ['ID', 'Type', 'Actor', 'Message', 'Created', 'Context'],
        ];

        $result = $this->get_entries([
            'posts_per_page' => 200,
        ]);

        foreach ($result as $entry) {
            $rows[] = [
                $entry['id'],
                $entry['type'],
                $entry['actor'],
                $entry['message'],
                $entry['created'],
                wp_json_encode($entry['context']),
            ];
        }

        $handle = fopen('php://temp', 'w+');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);

        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv ?: '';
    }

    /**
     * Prepare a WP_Post for output.
     *
     * @param \WP_Post $post Post object.
     *
     * @return array
     */
    private function prepare_entry($post)
    {
        $context = json_decode($post->post_content, true);

        return [
            'id'      => (int) $post->ID,
            'type'    => get_post_meta($post->ID, self::META_TYPE, true) ?: 'general',
            'actor'   => (int) $post->post_author,
            'message' => $post->post_title,
            'created' => $post->post_date_gmt,
            'context' => is_array($context) ? $context : [],
        ];
    }

    /**
     * Build the query arguments for fetching log entries.
     *
     * @param array $args Optional overrides.
     *
     * @return array
     */
    private function build_query_args(array $args)
    {
        $query_args = wp_parse_args(
            $args,
            [
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 20,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'paged'          => 1,
            ]
        );

        $query_args['paged'] = max(1, (int) $query_args['paged']);

        if (!empty($args['type'])) {
            $query_args['meta_query'] = [
                [
                    'key'   => self::META_TYPE,
                    'value' => sanitize_key($args['type']),
                ],
            ];
        }

        return $query_args;
    }

    /**
     * Sanitize context arrays recursively.
     *
     * @param array $context Raw context.
     *
     * @return array
     */
    private function prepare_context(array $context)
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $key = sanitize_key($key);

            if (is_scalar($value)) {
                $sanitized[$key] = is_string($value) ? sanitize_text_field($value) : $value;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->prepare_context($value);
            }
        }

        return $sanitized;
    }
}
