<?php
namespace VHONA\ClientPortal;

require_once __DIR__ . '/class-activity-logger.php';

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin controller for the VHONA client portal.
 */
class Plugin
{
    const OPTION_SETTINGS = 'vhona_client_portal_settings';
    const OPTION_ONBOARDING_STATE = 'vhona_client_portal_onboarding';
    const REST_NAMESPACE = 'client-portal/v1';

    const CAPABILITY_ACCESS_PORTAL = 'access_client_portal';

    const META_STORAGE_PROVIDER = '_vhona_storage_provider';
    const META_STORAGE_KEY = '_vhona_storage_key';
    const META_STORAGE_URL = '_vhona_storage_url';
    const META_ATTACHMENT_ID = '_vhona_document_attachment';

    const META_TASK_PROJECT = '_vhona_task_project_id';
    const META_TASK_STATUS = '_vhona_task_status';
    const META_TASK_DUE = '_vhona_task_due';
    const META_TASK_ASSIGNEE = '_vhona_task_assignee';

    const META_CONVERSATION_PROJECT = '_vhona_conversation_project_id';

    const META_DOCUMENT_DOWNLOAD_COUNT = '_vhona_document_download_count';
    const META_DOCUMENT_LAST_DOWNLOAD = '_vhona_document_last_download';
    const META_DOCUMENT_VERSIONS = '_vhona_document_versions';
    const META_DOCUMENT_DOWNLOAD_HISTORY = '_vhona_document_download_history';

    const OPTION_INTEGRATION_CACHE = 'vhona_client_portal_integration_cache';
    const OPTION_METRICS = 'vhona_client_portal_metrics';

    const CRON_REFRESH_INTEGRATIONS = 'vhona_client_portal_refresh_integrations';
    const CRON_RETENTION_CLEANUP = 'vhona_client_portal_retention_cleanup';

    const STORAGE_PROVIDER_S3 = 's3';

    const USER_META_TOUR_COMPLETED = '_vhona_portal_tour_completed';
    const USER_META_TOUR_DISMISSED = '_vhona_portal_tour_dismissed';

    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Plugin directory path with trailing slash.
     *
     * @var string
     */
    private $plugin_path;

    /**
     * Plugin directory URL with trailing slash.
     *
     * @var string
     */
    private $plugin_url;

    /**
     * Activity logger helper.
     *
     * @var ActivityLogger
     */
    private $activity_logger;

    /**
     * Get the singleton instance.
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function calculate_onboarding_completion(array $state)
    {
        $steps = $this->get_onboarding_steps();
        $total = count($steps);

        if ($total === 0) {
            return 0;
        }

        $completed = 0;
        foreach (array_keys($steps) as $key) {
            if (!empty($state['steps'][$key]['completed'])) {
                $completed++;
            }
        }

        return (int) floor(($completed / $total) * 100);
    }

    private function get_onboarding_steps()
    {
        return [
            'welcome' => ['label' => __('Welcome', 'vhona-client-portal')],
            'branding' => ['label' => __('Branding', 'vhona-client-portal')],
            'roles' => ['label' => __('Roles', 'vhona-client-portal')],
            'storage' => ['label' => __('Storage', 'vhona-client-portal')],
            'integrations' => ['label' => __('Integrations', 'vhona-client-portal')],
            'finish' => ['label' => __('Finish', 'vhona-client-portal')],
        ];
    }

    private function get_onboarding_state()
    {
        $state = get_option(self::OPTION_ONBOARDING_STATE, []);
        $steps = $this->get_onboarding_steps();

        if (!is_array($state)) {
            $state = [];
        }

        if (!isset($state['steps']) || !is_array($state['steps'])) {
            $state['steps'] = [];
        }

        foreach (array_keys($steps) as $key) {
            if (!isset($state['steps'][$key]) || !is_array($state['steps'][$key])) {
                $state['steps'][$key] = [];
            }

            $state['steps'][$key] = wp_parse_args(
                $state['steps'][$key],
                [
                    'completed' => false,
                    'completed_at' => '',
                    'actor' => 0,
                    'skipped' => false,
                ]
            );
        }

        return $state;
    }

    private function save_onboarding_state(array $state)
    {
        update_option(self::OPTION_ONBOARDING_STATE, $state);
    }

    private function determine_next_onboarding_step(array $state)
    {
        foreach (array_keys($this->get_onboarding_steps()) as $key) {
            if (empty($state['steps'][$key]['completed'])) {
                return $key;
            }
        }

        return '';
    }

    private function get_next_step_key($current)
    {
        $keys = array_keys($this->get_onboarding_steps());
        $index = array_search($current, $keys, true);

        if ($index === false || $index + 1 >= count($keys)) {
            return '';
        }

        return $keys[$index + 1];
    }

    private function mark_onboarding_step_complete($step, array $context = [])
    {
        $steps = $this->get_onboarding_steps();
        if (!isset($steps[$step])) {
            return;
        }

        $state = $this->get_onboarding_state();
        $state['steps'][$step]['completed'] = true;
        $state['steps'][$step]['completed_at'] = current_time('mysql', true);
        $state['steps'][$step]['actor'] = get_current_user_id();
        $state['steps'][$step]['skipped'] = !empty($context['skipped']);

        $this->save_onboarding_state($state);

        $this->log_activity(
            'onboarding_step',
            sprintf(__('Wizard step "%s" completed', 'vhona-client-portal'), $steps[$step]['label']),
            [
                'step' => $step,
                'skipped' => !empty($context['skipped']),
            ]
        );
    }

    public function render_setup_wizard()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $state = $this->get_onboarding_state();
        $steps = $this->get_onboarding_steps();
        $requested = isset($_GET['step']) ? sanitize_key(wp_unslash($_GET['step'])) : '';
        $current = isset($steps[$requested]) ? $requested : $this->determine_next_onboarding_step($state);
        if (!$current) {
            $current = array_key_first($steps);
        }

        $progress = $this->calculate_onboarding_completion($state);
        ?>
        <div class="wrap vhona-setup-wizard">
            <h1><?php esc_html_e('Client Portal Setup Wizard', 'vhona-client-portal'); ?></h1>
            <p class="description"><?php printf(esc_html__('%d%% complete', 'vhona-client-portal'), (int) $progress); ?></p>

            <ol class="vhona-wizard-steps">
                <?php foreach ($steps as $key => $meta) : ?>
                    <?php
                    $classes = [];
                    if (!empty($state['steps'][$key]['completed'])) {
                        $classes[] = 'completed';
                    }
                    if ($key === $current) {
                        $classes[] = 'current';
                    }
                    ?>
                    <li class="<?php echo esc_attr(implode(' ', $classes)); ?>">
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'vhona-client-portal-wizard', 'step' => $key], admin_url('admin.php'))); ?>"><?php echo esc_html($meta['label']); ?></a>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="vhona-wizard-content">
                <?php $this->render_wizard_step_content($current, $state, $steps); ?>
            </div>
        </div>
        <?php
    }

    private function render_wizard_step_content($step, array $state, array $steps)
    {
        $settings = $this->get_settings();
        $action_url = admin_url('admin-post.php');
        $next_step = $this->get_next_step_key($step) ?: 'finish';

        switch ($step) {
            case 'welcome':
                ?>
                <h2><?php esc_html_e('Welcome to the client portal wizard', 'vhona-client-portal'); ?></h2>
                <p><?php esc_html_e('This guided experience will walk you through key configuration areas so your team can invite clients with confidence.', 'vhona-client-portal'); ?></p>
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <?php wp_nonce_field('vhona_wizard_step_welcome'); ?>
                    <input type="hidden" name="action" value="vhona_client_portal_wizard_save" />
                    <input type="hidden" name="wizard_step" value="welcome" />
                    <?php submit_button(__('Begin setup', 'vhona-client-portal')); ?>
                </form>
                <?php
                break;

            case 'branding':
                ?>
                <h2><?php esc_html_e('Apply your brand', 'vhona-client-portal'); ?></h2>
                <p><?php esc_html_e('Set the colours and logo that should appear throughout the client-facing dashboard.', 'vhona-client-portal'); ?></p>
                <form method="post" action="<?php echo esc_url($action_url); ?>" class="vhona-wizard-form">
                    <?php wp_nonce_field('vhona_wizard_step_branding'); ?>
                    <input type="hidden" name="action" value="vhona_client_portal_wizard_save" />
                    <input type="hidden" name="wizard_step" value="branding" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Logo URL', 'vhona-client-portal'); ?></th>
                            <td><input type="url" class="regular-text" name="logo_url" value="<?php echo esc_attr($settings['theme']['logo_url']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Accent colour', 'vhona-client-portal'); ?></th>
                            <td><input type="text" class="regular-text" name="accent_color" value="<?php echo esc_attr($settings['theme']['accent_color']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Background colour', 'vhona-client-portal'); ?></th>
                            <td><input type="text" class="regular-text" name="background_color" value="<?php echo esc_attr($settings['theme']['background_color']); ?>" /></td>
                        </tr>
                    </table>
                    <?php submit_button(__('Save branding and continue', 'vhona-client-portal')); ?>
                </form>
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <?php wp_nonce_field('vhona_wizard_skip_branding'); ?>
                    <input type="hidden" name="action" value="vhona_client_portal_wizard_skip" />
                    <input type="hidden" name="wizard_step" value="branding" />
                    <?php submit_button(__('Skip for now', 'vhona-client-portal'), 'secondary'); ?>
                </form>
                <?php
                break;

            case 'roles':
                ?>
                <h2><?php esc_html_e('Assign roles', 'vhona-client-portal'); ?></h2>
                <p><?php esc_html_e('Invite at least one manager with the “Client Portal Manager” capability and ensure clients receive the “Client Portal Client” role.', 'vhona-client-portal'); ?></p>
                <ol>
                    <li><?php esc_html_e('Create or update a manager account and assign the Client Portal Manager role.', 'vhona-client-portal'); ?></li>
                    <li><?php esc_html_e('Confirm that client accounts have the Client Portal Client role or the custom capability you configured.', 'vhona-client-portal'); ?></li>
                </ol>
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <?php wp_nonce_field('vhona_wizard_step_roles'); ?>
                    <input type="hidden" name="action" value="vhona_client_portal_wizard_save" />
                    <input type="hidden" name="wizard_step" value="roles" />
                    <?php submit_button(__('Roles verified', 'vhona-client-portal')); ?>
                </form>
                <?php
                break;

            case 'storage':
                ?>
                <h2><?php esc_html_e('Choose document storage', 'vhona-client-portal'); ?></h2>
                <p><?php esc_html_e('Configure where uploaded files are stored and how long signed URLs remain valid.', 'vhona-client-portal'); ?></p>
                <form method="post" action="<?php echo esc_url($action_url); ?>" class="vhona-wizard-form">
                    <?php wp_nonce_field('vhona_wizard_step_storage'); ?>
                    <input type="hidden" name="action" value="vhona_client_portal_wizard_save" />
                    <input type="hidden" name="wizard_step" value="storage" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Storage provider', 'vhona-client-portal'); ?></th>
                            <td>
                                <label>
                                    <input type="radio" name="storage_provider" value="local" <?php checked($settings['storage']['provider'], 'local'); ?> />
                                    <?php esc_html_e('WordPress uploads', 'vhona-client-portal'); ?>
                                </label><br />
                                <label>
                                    <input type="radio" name="storage_provider" value="s3" <?php checked($settings['storage']['provider'], 's3'); ?> />
                                    <?php esc_html_e('S3 compatible bucket', 'vhona-client-portal'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Bucket name', 'vhona-client-portal'); ?></th>
                            <td><input type="text" class="regular-text" name="s3_bucket" value="<?php echo esc_attr($settings['storage']['s3_bucket']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Region', 'vhona-client-portal'); ?></th>
                            <td><input type="text" class="regular-text" name="s3_region" value="<?php echo esc_attr($settings['storage']['s3_region']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Signed URL expiry (seconds)', 'vhona-client-portal'); ?></th>
                            <td><input type="number" min="60" name="ttl" value="<?php echo esc_attr($settings['storage']['ttl']); ?>" /></td>
                        </tr>
                    </table>
                    <?php submit_button(__('Save storage settings', 'vhona-client-portal')); ?>
                </form>
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <?php wp_nonce_field('vhona_wizard_skip_storage'); ?>
                    <input type="hidden" name="action" value="vhona_client_portal_wizard_skip" />
                    <input type="hidden" name="wizard_step" value="storage" />
                    <?php submit_button(__('Skip storage setup', 'vhona-client-portal'), 'secondary'); ?>
                </form>
                <?php
                break;

            case 'integrations':
                ?>
                <h2><?php esc_html_e('Connect integrations', 'vhona-client-portal'); ?></h2>
                <p><?php esc_html_e('Enable FastAPI-powered enhancements and provide credentials for remote data sources.', 'vhona-client-portal'); ?></p>
                <form method="post" action="<?php echo esc_url($action_url); ?>" class="vhona-wizard-form">
                    <?php wp_nonce_field('vhona_wizard_step_integrations'); ?>
                    <input type="hidden" name="action" value="vhona_client_portal_wizard_save" />
                    <input type="hidden" name="wizard_step" value="integrations" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable FastAPI features', 'vhona-client-portal'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="fastapi_enabled" value="1" <?php checked($settings['integrations']['fastapi_enabled']); ?> />
                                    <?php esc_html_e('Allow the portal to fetch AI content, sync status updates, and validate sessions from FastAPI.', 'vhona-client-portal'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('FastAPI base URL', 'vhona-client-portal'); ?></th>
                            <td><input type="url" class="regular-text" name="fastapi_url" value="<?php echo esc_attr($settings['integrations']['fastapi_url']); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('FastAPI token', 'vhona-client-portal'); ?></th>
                            <td><input type="password" class="regular-text" name="fastapi_token" value="<?php echo esc_attr($settings['integrations']['fastapi_token']); ?>" autocomplete="off" /></td>
                        </tr>
                    </table>
                    <?php submit_button(__('Save integration settings', 'vhona-client-portal')); ?>
                </form>
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <?php wp_nonce_field('vhona_wizard_skip_integrations'); ?>
                    <input type="hidden" name="action" value="vhona_client_portal_wizard_skip" />
                    <input type="hidden" name="wizard_step" value="integrations" />
                    <?php submit_button(__('Configure later', 'vhona-client-portal'), 'secondary'); ?>
                </form>
                <?php
                break;

            case 'finish':
            default:
                ?>
                <h2><?php esc_html_e('Setup complete', 'vhona-client-portal'); ?></h2>
                <p><?php esc_html_e('You can revisit any step at any time or head back to the main settings page to adjust advanced options.', 'vhona-client-portal'); ?></p>
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <?php wp_nonce_field('vhona_wizard_step_finish'); ?>
                    <input type="hidden" name="action" value="vhona_client_portal_wizard_save" />
                    <input type="hidden" name="wizard_step" value="finish" />
                    <?php submit_button(__('Return to settings', 'vhona-client-portal')); ?>
                </form>
                <?php
                break;
        }
    }

    public function handle_wizard_save()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to modify the setup wizard.', 'vhona-client-portal'));
        }

        $step = isset($_POST['wizard_step']) ? sanitize_key(wp_unslash($_POST['wizard_step'])) : '';
        if (!$step) {
            wp_die(__('Missing wizard step.', 'vhona-client-portal'));
        }

        check_admin_referer('vhona_wizard_step_' . $step);

        $settings = $this->get_settings();

        switch ($step) {
            case 'branding':
                $settings['theme']['logo_url'] = esc_url_raw($_POST['logo_url'] ?? '');
                $settings['theme']['accent_color'] = sanitize_text_field($_POST['accent_color'] ?? $settings['theme']['accent_color']);
                $settings['theme']['background_color'] = sanitize_text_field($_POST['background_color'] ?? $settings['theme']['background_color']);
                update_option(self::OPTION_SETTINGS, $settings);
                break;
            case 'storage':
                $provider = isset($_POST['storage_provider']) && $_POST['storage_provider'] === self::STORAGE_PROVIDER_S3 ? self::STORAGE_PROVIDER_S3 : 'local';
                $settings['storage']['provider'] = $provider;
                $settings['storage']['s3_bucket'] = sanitize_text_field($_POST['s3_bucket'] ?? $settings['storage']['s3_bucket']);
                $settings['storage']['s3_region'] = sanitize_text_field($_POST['s3_region'] ?? $settings['storage']['s3_region']);
                $settings['storage']['ttl'] = max(60, (int) ($_POST['ttl'] ?? $settings['storage']['ttl']));
                update_option(self::OPTION_SETTINGS, $settings);
                break;
            case 'integrations':
                $settings['integrations']['fastapi_enabled'] = !empty($_POST['fastapi_enabled']);
                $settings['integrations']['fastapi_url'] = esc_url_raw($_POST['fastapi_url'] ?? $settings['integrations']['fastapi_url']);
                $settings['integrations']['fastapi_token'] = sanitize_text_field($_POST['fastapi_token'] ?? $settings['integrations']['fastapi_token']);
                update_option(self::OPTION_SETTINGS, $settings);
                break;
            default:
                break;
        }

        $this->mark_onboarding_step_complete($step);

        $next = $this->determine_next_onboarding_step($this->get_onboarding_state());

        if (!$next) {
            $redirect = add_query_arg(
                [
                    'page' => 'vhona-client-portal',
                    'portal_message' => __('Wizard complete. Review your settings below.', 'vhona-client-portal'),
                ],
                admin_url('admin.php')
            );
        } else {
            $redirect = add_query_arg(
                [
                    'page' => 'vhona-client-portal-wizard',
                    'step' => $next,
                ],
                admin_url('admin.php')
            );
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_wizard_skip()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to modify the setup wizard.', 'vhona-client-portal'));
        }

        $step = isset($_POST['wizard_step']) ? sanitize_key(wp_unslash($_POST['wizard_step'])) : '';
        if (!$step) {
            wp_die(__('Missing wizard step.', 'vhona-client-portal'));
        }

        check_admin_referer('vhona_wizard_skip_' . $step);

        $this->mark_onboarding_step_complete($step, ['skipped' => true]);

        $next = $this->determine_next_onboarding_step($this->get_onboarding_state());

        $redirect = add_query_arg(
            [
                'page' => 'vhona-client-portal-wizard',
                'step' => $next ?: $step,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_settings_updated($old_value, $value, $option = null)
    {
        $diff = $this->diff_settings((array) $old_value, (array) $value);

        if (empty($diff)) {
            return;
        }

        $this->log_activity('settings_saved', __('Portal settings updated', 'vhona-client-portal'), ['changes' => $diff]);
    }

    public function audit_rest_request($response, $handler, $request)
    {
        if (!$request instanceof \WP_REST_Request) {
            return $response;
        }

        $route = $request->get_route();
        if (strpos($route, '/' . self::REST_NAMESPACE) !== 0) {
            return $response;
        }

        $method = strtoupper($request->get_method());
        $log_mutation = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $log_sensitive_get = ('GET' === $method) && (false !== strpos($route, '/download') || false !== strpos($route, '/analytics') || false !== strpos($route, '/activity'));

        if (!$log_mutation && !$log_sensitive_get) {
            return $response;
        }

        $status = 200;
        $error = '';
        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            $data = $response->get_error_data();
            $status = isset($data['status']) ? (int) $data['status'] : 500;
        } else {
            $rest_response = rest_ensure_response($response);
            $status = (int) $rest_response->get_status();
        }

        $context = [
            'method' => $method,
            'route' => $route,
            'status' => $status,
        ];

        if ($log_mutation) {
            $context['params'] = $this->filter_params_for_log($request->get_params());
        }

        if ($error) {
            $context['error'] = sanitize_text_field($error);
        }

        $this->log_activity('rest_' . strtolower($method), sprintf(__('REST %1$s %2$s', 'vhona-client-portal'), $method, $route), $context);

        return $response;
    }

    private function filter_params_for_log($params)
    {
        if (!is_array($params)) {
            return [];
        }

        $filtered = [];
        foreach ($params as $key => $value) {
            if (in_array($key, ['_wpnonce', '_portal_nonce', 'nonce', 'file', 'upload', 'files'], true)) {
                continue;
            }

            if (is_scalar($value)) {
                $string = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                $filtered[$key] = mb_strimwidth(sanitize_text_field($string), 0, 200, '…');
            } elseif (is_array($value)) {
                $filtered[$key] = $this->filter_params_for_log($value);
            }
        }

        return $filtered;
    }

    private function diff_settings(array $old, array $new)
    {
        $changes = [];

        foreach ($new as $section => $values) {
            if (!is_array($values)) {
                continue;
            }

            $old_values = isset($old[$section]) && is_array($old[$section]) ? $old[$section] : [];

            foreach ($values as $key => $value) {
                $old_value = isset($old_values[$key]) ? $old_values[$key] : null;

                if ($value === $old_value) {
                    continue;
                }

                $changes[] = [
                    'section' => $section,
                    'key' => $key,
                ];
            }
        }

        return $changes;
    }

    private function __construct()
    {
        $this->plugin_path = trailingslashit(dirname(__DIR__));
        $plugin_file = $this->plugin_path . 'client-portal.php';
        $this->plugin_url = trailingslashit(plugins_url('', $plugin_file));
        $this->activity_logger = new ActivityLogger();

        add_action('init', [$this, 'register_roles']);
        add_action('init', [$this, 'register_post_types']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('enqueue_block_assets', [$this, 'enqueue_frontend_assets']);
        add_shortcode('vhona_client_portal', [$this, 'render_portal_shortcode']);
        add_action('admin_post_vhona_client_portal_export_logs', [$this, 'handle_export_logs']);
        add_action('admin_post_vhona_client_portal_erase_user', [$this, 'handle_manual_erasure']);
        add_action('admin_post_vhona_client_portal_wizard_save', [$this, 'handle_wizard_save']);
        add_action('admin_post_vhona_client_portal_wizard_skip', [$this, 'handle_wizard_skip']);
        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
        add_action('save_post_client_document', [$this, 'handle_document_save'], 10, 3);
        add_action('wp_login', [$this, 'handle_portal_login'], 10, 2);
        add_action(self::CRON_REFRESH_INTEGRATIONS, [$this, 'refresh_integration_cache']);
        add_action(self::CRON_RETENTION_CLEANUP, [$this, 'handle_retention_cleanup']);
        add_filter('rest_request_after_callbacks', [$this, 'audit_rest_request'], 10, 3);
        add_action('update_option_' . self::OPTION_SETTINGS, [$this, 'handle_settings_updated'], 10, 3);

        if (defined('WP_CLI') && WP_CLI) {
            $this->register_cli_commands();
        }
    }

    /**
     * Activation hook callback.
     */
    public function activate()
    {
        $this->register_roles();
        $this->register_post_types();
        flush_rewrite_rules();

        if (!wp_next_scheduled(self::CRON_REFRESH_INTEGRATIONS)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_REFRESH_INTEGRATIONS);
        }

        if (!wp_next_scheduled(self::CRON_RETENTION_CLEANUP)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_RETENTION_CLEANUP);
        }
    }

    /**
     * Deactivation hook callback.
     */
    public function deactivate()
    {
        flush_rewrite_rules();
        wp_clear_scheduled_hook(self::CRON_REFRESH_INTEGRATIONS);
        wp_clear_scheduled_hook(self::CRON_RETENTION_CLEANUP);
    }

    /**
     * Create roles and map capabilities.
     */
    public function register_roles()
    {
        add_role(
            'client_portal_client',
            __('Client Portal Client', 'vhona-client-portal'),
            [
                'read' => true,
                self::CAPABILITY_ACCESS_PORTAL => true,
            ]
        );

        add_role(
            'client_portal_manager',
            __('Client Portal Manager', 'vhona-client-portal'),
            [
                'read' => true,
                'manage_client_portal' => true,
                'upload_files' => true,
                self::CAPABILITY_ACCESS_PORTAL => true,
            ]
        );

        if ($role = get_role('client_portal_client')) {
            $role->add_cap(self::CAPABILITY_ACCESS_PORTAL);
            $role->add_cap('read');
        }

        if ($role = get_role('client_portal_manager')) {
            $role->add_cap('manage_client_portal');
            $role->add_cap('upload_files');
            $role->add_cap(self::CAPABILITY_ACCESS_PORTAL);
            $role->add_cap('read');
        }

        if ($admin = get_role('administrator')) {
            $admin->add_cap('manage_client_portal');
            $admin->add_cap(self::CAPABILITY_ACCESS_PORTAL);
        }
    }

    /**
     * Register custom post types.
     */
    public function register_post_types()
    {
        register_post_type(
            'client_project',
            [
                'labels' => [
                    'name' => __('Client Projects', 'vhona-client-portal'),
                    'singular_name' => __('Client Project', 'vhona-client-portal'),
                ],
                'public' => false,
                'show_ui' => true,
                'supports' => ['title', 'editor', 'custom-fields'],
                'capability_type' => 'client_project',
                'map_meta_cap' => true,
                'capabilities' => $this->get_project_capabilities(),
                'menu_icon' => 'dashicons-portfolio',
            ]
        );

        register_post_type(
            'client_document',
            [
                'labels' => [
                    'name' => __('Client Documents', 'vhona-client-portal'),
                    'singular_name' => __('Client Document', 'vhona-client-portal'),
                ],
                'public' => false,
                'show_ui' => true,
                'supports' => ['title', 'editor', 'author'],
                'capability_type' => 'client_document',
                'map_meta_cap' => true,
                'capabilities' => $this->get_document_capabilities(),
                'menu_icon' => 'dashicons-media-document',
            ]
        );

        register_post_type(
            'client_task',
            [
                'labels' => [
                    'name' => __('Client Tasks', 'vhona-client-portal'),
                    'singular_name' => __('Client Task', 'vhona-client-portal'),
                ],
                'public' => false,
                'show_ui' => true,
                'supports' => ['title', 'editor', 'author'],
                'capability_type' => 'client_task',
                'map_meta_cap' => true,
                'capabilities' => $this->get_task_capabilities(),
                'menu_icon' => 'dashicons-yes-alt',
            ]
        );

        register_post_type(
            'client_conversation',
            [
                'labels' => [
                    'name' => __('Client Conversations', 'vhona-client-portal'),
                    'singular_name' => __('Client Conversation', 'vhona-client-portal'),
                ],
                'public' => false,
                'show_ui' => true,
                'supports' => ['title', 'editor', 'author', 'comments'],
                'capability_type' => 'client_conversation',
                'map_meta_cap' => true,
                'capabilities' => $this->get_conversation_capabilities(),
                'menu_icon' => 'dashicons-format-chat',
            ]
        );

        $this->activity_logger->register_post_type();
    }

    private function get_project_capabilities()
    {
        return [
            'edit_post' => 'manage_client_portal',
            'read_post' => 'read',
            'delete_post' => 'manage_client_portal',
            'edit_posts' => 'manage_client_portal',
            'edit_others_posts' => 'manage_client_portal',
            'publish_posts' => 'manage_client_portal',
            'delete_posts' => 'manage_client_portal',
            'delete_others_posts' => 'manage_client_portal',
            'read_private_posts' => 'manage_client_portal',
        ];
    }

    private function get_document_capabilities()
    {
        return [
            'edit_post' => 'manage_client_portal',
            'read_post' => 'read',
            'delete_post' => 'manage_client_portal',
            'edit_posts' => 'manage_client_portal',
            'edit_others_posts' => 'manage_client_portal',
            'publish_posts' => 'manage_client_portal',
            'delete_posts' => 'manage_client_portal',
            'delete_others_posts' => 'manage_client_portal',
            'read_private_posts' => 'manage_client_portal',
        ];
    }

    private function get_task_capabilities()
    {
        return [
            'edit_post' => 'manage_client_portal',
            'read_post' => 'read',
            'delete_post' => 'manage_client_portal',
            'edit_posts' => 'manage_client_portal',
            'edit_others_posts' => 'manage_client_portal',
            'publish_posts' => 'manage_client_portal',
            'delete_posts' => 'manage_client_portal',
            'delete_others_posts' => 'manage_client_portal',
            'read_private_posts' => 'manage_client_portal',
        ];
    }

    private function get_conversation_capabilities()
    {
        return [
            'edit_post' => 'manage_client_portal',
            'read_post' => 'read',
            'delete_post' => 'manage_client_portal',
            'edit_posts' => 'manage_client_portal',
            'edit_others_posts' => 'manage_client_portal',
            'publish_posts' => 'manage_client_portal',
            'delete_posts' => 'manage_client_portal',
            'delete_others_posts' => 'manage_client_portal',
            'read_private_posts' => 'manage_client_portal',
        ];
    }

    /**
     * Register admin settings page.
     */
    public function register_admin_menu()
    {
        add_menu_page(
            __('Client Portal', 'vhona-client-portal'),
            __('Client Portal', 'vhona-client-portal'),
            'manage_options',
            'vhona-client-portal',
            [$this, 'render_settings_page'],
            'dashicons-shield',
            58
        );

        add_submenu_page(
            'vhona-client-portal',
            __('Setup Wizard', 'vhona-client-portal'),
            __('Setup Wizard', 'vhona-client-portal'),
            'manage_options',
            'vhona-client-portal-wizard',
            [$this, 'render_setup_wizard']
        );
    }

    /**
     * Register plugin settings for storage and theme.
     */
    public function register_settings()
    {
        register_setting(
            'vhona_client_portal_settings_group',
            self::OPTION_SETTINGS,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => $this->get_default_settings(),
            ]
        );
    }

    public function sanitize_settings($settings)
    {
        $defaults = $this->get_default_settings();
        $settings = is_array($settings) ? $settings : [];

        $settings['storage'] = isset($settings['storage']) && is_array($settings['storage']) ? $settings['storage'] : [];
        $settings['theme'] = isset($settings['theme']) && is_array($settings['theme']) ? $settings['theme'] : [];
        $settings['integrations'] = isset($settings['integrations']) && is_array($settings['integrations']) ? $settings['integrations'] : [];
        $settings['analytics'] = isset($settings['analytics']) && is_array($settings['analytics']) ? $settings['analytics'] : [];
        $settings['compliance'] = isset($settings['compliance']) && is_array($settings['compliance']) ? $settings['compliance'] : [];
        $settings['onboarding'] = isset($settings['onboarding']) && is_array($settings['onboarding']) ? $settings['onboarding'] : [];

        $storage = array_merge($defaults['storage'], $settings['storage']);
        $storage['provider'] = $storage['provider'] === self::STORAGE_PROVIDER_S3 ? self::STORAGE_PROVIDER_S3 : 'local';
        $storage['s3_bucket'] = sanitize_text_field($storage['s3_bucket']);
        $storage['s3_region'] = sanitize_text_field($storage['s3_region']);
        $storage['s3_access_key'] = sanitize_text_field($storage['s3_access_key']);
        $storage['s3_secret_key'] = sanitize_text_field($storage['s3_secret_key']);
        $storage['s3_prefix'] = trim(sanitize_text_field($storage['s3_prefix']), '/');
        $storage['ttl'] = max(60, (int) $storage['ttl']);

        $theme = array_merge($defaults['theme'], $settings['theme']);
        foreach ($theme as $key => $value) {
            $theme[$key] = sanitize_text_field($value);
        }

        $integrations = array_merge($defaults['integrations'], $settings['integrations']);
        $integrations['fastapi_enabled'] = !empty($integrations['fastapi_enabled']);
        $integrations['fastapi_url'] = esc_url_raw($integrations['fastapi_url']);
        $integrations['fastapi_token'] = sanitize_text_field($integrations['fastapi_token']);
        $integrations['fastapi_cache_ttl'] = max(60, (int) $integrations['fastapi_cache_ttl']);
        $integrations['fastapi_content_limit'] = max(1, (int) $integrations['fastapi_content_limit']);
        $integrations['crm_endpoint'] = esc_url_raw($integrations['crm_endpoint']);
        $integrations['crm_token'] = sanitize_text_field($integrations['crm_token']);
        $integrations['billing_endpoint'] = esc_url_raw($integrations['billing_endpoint']);
        $integrations['billing_token'] = sanitize_text_field($integrations['billing_token']);
        $integrations['calendar_feed'] = esc_url_raw($integrations['calendar_feed']);

        $analytics = array_merge($defaults['analytics'], $settings['analytics']);
        $analytics['download_retention_days'] = max(1, (int) $analytics['download_retention_days']);

        $compliance = array_merge($defaults['compliance'], $settings['compliance']);
        $compliance['auto_purge'] = !empty($compliance['auto_purge']);
        $compliance['retention_days'] = max(7, (int) $compliance['retention_days']);
        $compliance['notify_email'] = sanitize_email($compliance['notify_email']);

        $onboarding = array_merge($defaults['onboarding'], $settings['onboarding']);
        $onboarding['guided_tour_enabled'] = !empty($onboarding['guided_tour_enabled']);
        $onboarding['guided_tour_dismissible'] = !empty($onboarding['guided_tour_dismissible']);

        return [
            'storage' => $storage,
            'theme' => $theme,
            'integrations' => $integrations,
            'analytics' => $analytics,
            'compliance' => $compliance,
            'onboarding' => $onboarding,
        ];
    }

    private function get_default_settings()
    {
        return [
            'storage' => [
                'provider' => 'local',
                's3_bucket' => '',
                's3_region' => 'us-east-1',
                's3_access_key' => '',
                's3_secret_key' => '',
                's3_prefix' => 'client-portal',
                'ttl' => 900,
            ],
            'theme' => [
                'accent_color' => '#2563eb',
                'background_color' => '#0f172a',
                'surface_color' => '#1e293b',
                'text_color' => '#f8fafc',
                'muted_text_color' => '#cbd5f5',
                'logo_url' => '',
                'heading_font' => 'Inter, sans-serif',
                'body_font' => 'Inter, sans-serif',
                'border_radius' => '16',
            ],
            'integrations' => [
                'fastapi_enabled' => false,
                'fastapi_url' => '',
                'fastapi_token' => '',
                'fastapi_cache_ttl' => 900,
                'fastapi_content_limit' => 5,
                'crm_endpoint' => '',
                'crm_token' => '',
                'billing_endpoint' => '',
                'billing_token' => '',
                'calendar_feed' => '',
            ],
            'analytics' => [
                'download_retention_days' => 90,
            ],
            'compliance' => [
                'auto_purge' => false,
                'retention_days' => 365,
                'notify_email' => '',
            ],
            'onboarding' => [
                'guided_tour_enabled' => true,
                'guided_tour_dismissible' => true,
            ],
        ];
    }

    private function get_settings()
    {
        $saved = get_option(self::OPTION_SETTINGS, []);
        $defaults = $this->get_default_settings();

        $settings = wp_parse_args($saved, $defaults);
        $settings['storage'] = wp_parse_args($settings['storage'], $defaults['storage']);
        $settings['theme'] = wp_parse_args($settings['theme'], $defaults['theme']);
        $settings['integrations'] = wp_parse_args($settings['integrations'], $defaults['integrations']);
        $settings['analytics'] = wp_parse_args($settings['analytics'], $defaults['analytics']);
        $settings['compliance'] = wp_parse_args($settings['compliance'], $defaults['compliance']);
        $settings['onboarding'] = wp_parse_args($settings['onboarding'], $defaults['onboarding']);

        return $settings;
    }

    /**
     * Render the settings/admin dashboard.
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $state = $this->get_onboarding_state();
        $steps = $this->get_onboarding_steps();
        $next_step = $this->determine_next_onboarding_step($state);
        $wizard_url = admin_url('admin.php?page=vhona-client-portal-wizard');

        $log_type = isset($_GET['log_type']) ? sanitize_key(wp_unslash($_GET['log_type'])) : '';
        $log_search = isset($_GET['log_search']) ? sanitize_text_field(wp_unslash($_GET['log_search'])) : '';
        $log_after = isset($_GET['log_after']) ? sanitize_text_field(wp_unslash($_GET['log_after'])) : '';
        $log_before = isset($_GET['log_before']) ? sanitize_text_field(wp_unslash($_GET['log_before'])) : '';
        $log_page = isset($_GET['log_page']) ? max(1, (int) $_GET['log_page']) : 1;
        $log_filter_args = array_filter(
            [
                'log_type' => $log_type,
                'log_search' => $log_search,
                'log_after' => $log_after,
                'log_before' => $log_before,
            ],
            function ($value) {
                return '' !== $value;
            }
        );
        $export_query = ['action' => 'vhona_client_portal_export_logs'] + $log_filter_args;
        $export_url = wp_nonce_url(add_query_arg($export_query, admin_url('admin-post.php')), 'vhona_export_logs');
        $log_result = $this->activity_logger->get_entries([
            'posts_per_page' => 10,
            'type' => $log_type,
            'paged' => $log_page,
            'include_meta' => true,
            'search' => $log_search,
            'after' => $log_after,
            'before' => $log_before,
        ]);

        $entries = isset($log_result['entries']) ? $log_result['entries'] : [];
        $log_total_pages = isset($log_result['max_pages']) ? max(1, (int) $log_result['max_pages']) : 1;
        $log_current_page = isset($log_result['page']) ? max(1, (int) $log_result['page']) : 1;
        $log_types = $this->activity_logger->get_types();
        $insights = $this->get_portal_insights();
        $checklist = isset($insights['setup']) && is_array($insights['setup']) ? $insights['setup'] : [];
        $login_summary = isset($insights['logins']) && is_array($insights['logins']) ? $insights['logins'] : ['total' => 0, 'last_7_days' => 0, 'last_30_days' => 0];
        $document_summary = isset($insights['documents']) && is_array($insights['documents']) ? $insights['documents'] : ['total_documents' => 0, 'total_downloads' => 0, 'downloads_last_7_days' => 0, 'downloads_last_30_days' => 0];
        $notice_message = isset($_GET['portal_message']) ? sanitize_text_field(wp_unslash($_GET['portal_message'])) : '';
        $notice_type = isset($_GET['portal_message_type']) ? sanitize_text_field(wp_unslash($_GET['portal_message_type'])) : '';
        $notice_class = $notice_type === 'error' ? 'notice notice-error' : 'notice notice-success';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Client Portal Settings', 'vhona-client-portal'); ?></h1>
            <?php if ($notice_message) : ?>
                <div class="<?php echo esc_attr($notice_class); ?>">
                    <p><?php echo esc_html($notice_message); ?></p>
                </div>
            <?php endif; ?>

            <div class="notice notice-info">
                <?php if ($next_step) : ?>
                    <p>
                        <?php
                        printf(
                            esc_html__('Your setup wizard is %1$d%% complete. Continue with the next step: %2$s.', 'vhona-client-portal'),
                            (int) $this->calculate_onboarding_completion($state),
                            '<a href="' . esc_url(add_query_arg(['step' => $next_step], $wizard_url)) . '">' . esc_html($steps[$next_step]['label']) . '</a>'
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <p><?php esc_html_e('All setup wizard steps are complete. You can revisit the wizard at any time.', 'vhona-client-portal'); ?></p>
                <?php endif; ?>
            </div>

            <form action="<?php echo esc_url(admin_url('options.php')); ?>" method="post">
                <?php settings_fields('vhona_client_portal_settings_group'); ?>
                <h2><?php esc_html_e('Secure storage', 'vhona-client-portal'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Storage provider', 'vhona-client-portal'); ?></th>
                        <td>
                            <label>
                                <input type="radio" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[storage][provider]" value="local" <?php checked($settings['storage']['provider'], 'local'); ?> />
                                <?php esc_html_e('WordPress uploads (default)', 'vhona-client-portal'); ?>
                            </label><br />
                            <label>
                                <input type="radio" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[storage][provider]" value="s3" <?php checked($settings['storage']['provider'], 's3'); ?> />
                                <?php esc_html_e('Amazon S3 or compatible', 'vhona-client-portal'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('S3 bucket', 'vhona-client-portal'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[storage][s3_bucket]" value="<?php echo esc_attr($settings['storage']['s3_bucket']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('S3 region', 'vhona-client-portal'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[storage][s3_region]" value="<?php echo esc_attr($settings['storage']['s3_region']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Access key ID', 'vhona-client-portal'); ?></th>
                        <td><input type="text" class="regular-text" autocomplete="off" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[storage][s3_access_key]" value="<?php echo esc_attr($settings['storage']['s3_access_key']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Secret access key', 'vhona-client-portal'); ?></th>
                        <td><input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[storage][s3_secret_key]" value="<?php echo esc_attr($settings['storage']['s3_secret_key']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Object prefix', 'vhona-client-portal'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[storage][s3_prefix]" value="<?php echo esc_attr($settings['storage']['s3_prefix']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Signed URL expiry (seconds)', 'vhona-client-portal'); ?></th>
                        <td><input type="number" min="60" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[storage][ttl]" value="<?php echo esc_attr($settings['storage']['ttl']); ?>" /></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Portal theme', 'vhona-client-portal'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Logo URL', 'vhona-client-portal'); ?></th>
                        <td><input type="url" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[theme][logo_url]" value="<?php echo esc_attr($settings['theme']['logo_url']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Accent colour', 'vhona-client-portal'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[theme][accent_color]" value="<?php echo esc_attr($settings['theme']['accent_color']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Background colour', 'vhona-client-portal'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[theme][background_color]" value="<?php echo esc_attr($settings['theme']['background_color']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Surface colour', 'vhona-client-portal'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[theme][surface_color]" value="<?php echo esc_attr($settings['theme']['surface_color']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Text colour', 'vhona-client-portal'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[theme][text_color]" value="<?php echo esc_attr($settings['theme']['text_color']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Muted text colour', 'vhona-client-portal'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[theme][muted_text_color]" value="<?php echo esc_attr($settings['theme']['muted_text_color']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Heading font', 'vhona-client-portal'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[theme][heading_font]" value="<?php echo esc_attr($settings['theme']['heading_font']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Body font', 'vhona-client-portal'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[theme][body_font]" value="<?php echo esc_attr($settings['theme']['body_font']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Border radius (px)', 'vhona-client-portal'); ?></th>
                        <td><input type="number" min="0" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[theme][border_radius]" value="<?php echo esc_attr($settings['theme']['border_radius']); ?>" /></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Integrations', 'vhona-client-portal'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable FastAPI integration', 'vhona-client-portal'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[integrations][fastapi_enabled]" value="1" <?php checked($settings['integrations']['fastapi_enabled']); ?> />
                                <?php esc_html_e('Surface AI marketing content, project summaries, and remote authentication from the FastAPI backend.', 'vhona-client-portal'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('FastAPI base URL', 'vhona-client-portal'); ?></th>
                        <td><input type="url" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[integrations][fastapi_url]" value="<?php echo esc_attr($settings['integrations']['fastapi_url']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('FastAPI token', 'vhona-client-portal'); ?></th>
                        <td><input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[integrations][fastapi_token]" value="<?php echo esc_attr($settings['integrations']['fastapi_token']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('FastAPI cache TTL (seconds)', 'vhona-client-portal'); ?></th>
                        <td><input type="number" min="60" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[integrations][fastapi_cache_ttl]" value="<?php echo esc_attr($settings['integrations']['fastapi_cache_ttl']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('FastAPI content limit', 'vhona-client-portal'); ?></th>
                        <td><input type="number" min="1" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[integrations][fastapi_content_limit]" value="<?php echo esc_attr($settings['integrations']['fastapi_content_limit']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('CRM endpoint', 'vhona-client-portal'); ?></th>
                        <td><input type="url" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[integrations][crm_endpoint]" value="<?php echo esc_attr($settings['integrations']['crm_endpoint']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('CRM token', 'vhona-client-portal'); ?></th>
                        <td><input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[integrations][crm_token]" value="<?php echo esc_attr($settings['integrations']['crm_token']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Billing endpoint', 'vhona-client-portal'); ?></th>
                        <td><input type="url" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[integrations][billing_endpoint]" value="<?php echo esc_attr($settings['integrations']['billing_endpoint']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Billing token', 'vhona-client-portal'); ?></th>
                        <td><input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[integrations][billing_token]" value="<?php echo esc_attr($settings['integrations']['billing_token']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Calendar feed URL', 'vhona-client-portal'); ?></th>
                        <td><input type="url" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[integrations][calendar_feed]" value="<?php echo esc_attr($settings['integrations']['calendar_feed']); ?>" /></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Automation & Analytics', 'vhona-client-portal'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Download retention (days)', 'vhona-client-portal'); ?></th>
                        <td><input type="number" min="1" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[analytics][download_retention_days]" value="<?php echo esc_attr($settings['analytics']['download_retention_days']); ?>" /></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Compliance & retention', 'vhona-client-portal'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Automatic data purge', 'vhona-client-portal'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[compliance][auto_purge]" value="1" <?php checked($settings['compliance']['auto_purge']); ?> />
                                <?php esc_html_e('Nightly cron will trim document download logs and login metrics beyond the retention window.', 'vhona-client-portal'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Retention window (days)', 'vhona-client-portal'); ?></th>
                        <td><input type="number" min="7" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[compliance][retention_days]" value="<?php echo esc_attr($settings['compliance']['retention_days']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Erasure confirmation email', 'vhona-client-portal'); ?></th>
                        <td><input type="email" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[compliance][notify_email]" value="<?php echo esc_attr($settings['compliance']['notify_email']); ?>" /></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Guided setup tour', 'vhona-client-portal'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable guided tour for managers', 'vhona-client-portal'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[onboarding][guided_tour_enabled]" value="1" <?php checked($settings['onboarding']['guided_tour_enabled']); ?> />
                                <?php esc_html_e('Show the in-app walkthrough until all steps are acknowledged.', 'vhona-client-portal'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Allow managers to dismiss the tour', 'vhona-client-portal'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[onboarding][guided_tour_dismissible]" value="1" <?php checked($settings['onboarding']['guided_tour_dismissible']); ?> />
                                <?php esc_html_e('When disabled, the tour repeats until every step is marked complete.', 'vhona-client-portal'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('Portal data erasure', 'vhona-client-portal'); ?></h2>
            <p><?php esc_html_e('Remove login metrics, document download history, and conversation participation for an individual user. Optionally delete the WordPress account after portal data is purged.', 'vhona-client-portal'); ?></p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="vhona-portal-erase-form">
                <?php wp_nonce_field('vhona_erase_user'); ?>
                <input type="hidden" name="action" value="vhona_client_portal_erase_user" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('User email', 'vhona-client-portal'); ?></th>
                        <td><input type="email" name="user_email" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('User ID', 'vhona-client-portal'); ?></th>
                        <td><input type="number" name="user_id" class="regular-text" min="1" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Delete WordPress account', 'vhona-client-portal'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="delete_user" value="1" />
                                <?php esc_html_e('Also delete the WordPress user account after portal data has been scrubbed.', 'vhona-client-portal'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Erase portal data', 'vhona-client-portal'), 'delete'); ?>
            </form>

            <h2><?php esc_html_e('Setup checklist', 'vhona-client-portal'); ?></h2>
            <ol>
                <?php foreach ($checklist as $item) : ?>
                    <li>
                        <strong><?php echo esc_html($item['label']); ?></strong>
                        <?php if (!empty($item['description'])) : ?>
                            <p><?php echo esc_html($item['description']); ?></p>
                        <?php endif; ?>
                        <p><span class="dashicons dashicons-<?php echo $item['completed'] ? 'yes' : 'dismiss'; ?>" aria-hidden="true"></span> <?php echo $item['completed'] ? esc_html__('Complete', 'vhona-client-portal') : esc_html__('Pending', 'vhona-client-portal'); ?></p>
                    </li>
                <?php endforeach; ?>
            </ol>

            <h2><?php esc_html_e('Analytics snapshot', 'vhona-client-portal'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Logins (last 7 days)', 'vhona-client-portal'); ?></th>
                    <td><?php echo esc_html((int) $login_summary['last_7_days']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Logins (last 30 days)', 'vhona-client-portal'); ?></th>
                    <td><?php echo esc_html((int) $login_summary['last_30_days']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Total logins recorded', 'vhona-client-portal'); ?></th>
                    <td><?php echo esc_html((int) $login_summary['total']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Published documents', 'vhona-client-portal'); ?></th>
                    <td><?php echo esc_html((int) $document_summary['total_documents']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Downloads (last 7 days)', 'vhona-client-portal'); ?></th>
                    <td><?php echo esc_html((int) $document_summary['downloads_last_7_days']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Total downloads', 'vhona-client-portal'); ?></th>
                    <td><?php echo esc_html((int) $document_summary['total_downloads']); ?></td>
                </tr>
            </table>

            <h2><?php esc_html_e('Recent activity', 'vhona-client-portal'); ?></h2>
            <form method="get" class="vhona-log-filter">
                <input type="hidden" name="page" value="vhona-client-portal" />
                <input type="hidden" name="log_page" value="1" />
                <label for="vhona-log-type"><?php esc_html_e('Filter by type', 'vhona-client-portal'); ?></label>
                <select id="vhona-log-type" name="log_type">
                    <option value=""><?php esc_html_e('All events', 'vhona-client-portal'); ?></option>
                    <?php foreach ($log_types as $type) : ?>
                        <option value="<?php echo esc_attr($type); ?>" <?php selected($log_type, $type); ?>><?php echo esc_html($type); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="vhona-log-search" class="screen-reader-text"><?php esc_html_e('Search activity log', 'vhona-client-portal'); ?></label>
                <input type="search" id="vhona-log-search" name="log_search" value="<?php echo esc_attr($log_search); ?>" placeholder="<?php esc_attr_e('Search message', 'vhona-client-portal'); ?>" />
                <label for="vhona-log-after"><?php esc_html_e('After', 'vhona-client-portal'); ?></label>
                <input type="date" id="vhona-log-after" name="log_after" value="<?php echo esc_attr($log_after); ?>" />
                <label for="vhona-log-before"><?php esc_html_e('Before', 'vhona-client-portal'); ?></label>
                <input type="date" id="vhona-log-before" name="log_before" value="<?php echo esc_attr($log_before); ?>" />
                <button class="button" type="submit"><?php esc_html_e('Apply', 'vhona-client-portal'); ?></button>
                <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=vhona-client-portal')); ?>"><?php esc_html_e('Reset', 'vhona-client-portal'); ?></a>
                <a class="button button-link" href="<?php echo esc_url($export_url); ?>"><?php esc_html_e('Export CSV', 'vhona-client-portal'); ?></a>
            </form>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'vhona-client-portal'); ?></th>
                        <th><?php esc_html_e('Type', 'vhona-client-portal'); ?></th>
                        <th><?php esc_html_e('Actor', 'vhona-client-portal'); ?></th>
                        <th><?php esc_html_e('Message', 'vhona-client-portal'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)) : ?>
                        <tr><td colspan="4"><?php esc_html_e('No activity recorded yet.', 'vhona-client-portal'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($entries as $entry) :
                            $actor_user = $entry['actor'] ? get_userdata($entry['actor']) : false;
                            $actor_label = $actor_user ? $actor_user->display_name : ($entry['actor'] ? sprintf(__('User #%d', 'vhona-client-portal'), (int) $entry['actor']) : __('System', 'vhona-client-portal'));
                            ?>
                            <tr>
                                <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $entry['created'])); ?></td>
                                <td><?php echo esc_html($entry['type']); ?></td>
                                <td><?php echo esc_html($actor_label); ?></td>
                                <td><?php echo esc_html($entry['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ($log_total_pages > 1) : ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php printf(esc_html__('%d pages of activity', 'vhona-client-portal'), (int) $log_total_pages); ?></span>
                        <span class="pagination-links">
                            <?php
                            $base_args = ['page' => 'vhona-client-portal'] + $log_filter_args;
                            $prev_link = $log_current_page > 1 ? add_query_arg($base_args + ['log_page' => $log_current_page - 1], admin_url('admin.php')) : '';
                            $next_link = $log_current_page < $log_total_pages ? add_query_arg($base_args + ['log_page' => $log_current_page + 1], admin_url('admin.php')) : '';
                            ?>
                            <a class="prev-page button <?php echo $log_current_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $prev_link ? esc_url($prev_link) : '#'; ?>" <?php disabled($log_current_page <= 1); ?>><?php esc_html_e('«', 'vhona-client-portal'); ?></a>
                            <span class="paging-input">
                                <?php printf(esc_html__('%1$d of %2$d', 'vhona-client-portal'), (int) $log_current_page, (int) $log_total_pages); ?>
                            </span>
                            <a class="next-page button <?php echo $log_current_page >= $log_total_pages ? 'disabled' : ''; ?>" href="<?php echo $next_link ? esc_url($next_link) : '#'; ?>" <?php disabled($log_current_page >= $log_total_pages); ?>><?php esc_html_e('»', 'vhona-client-portal'); ?></a>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes()
    {
        register_rest_route(
            self::REST_NAMESPACE,
            '/projects',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_get_projects'],
                'permission_callback' => function ($request) {
                    return $this->ensure_portal_user_with_nonce($request, 'vhona_cp_conversation_reply');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/documents',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_get_documents'],
                'permission_callback' => [$this, 'ensure_can_view_portal'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/documents',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_create_document'],
                'permission_callback' => function ($request) {
                    return $this->ensure_manage_portal_with_nonce($request, 'vhona_cp_documents_create');
                },
                'args' => [
                    'title' => [
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'client_id' => [
                        'required' => false,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/documents/(?P<id>\d+)/download',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_download_document'],
                'permission_callback' => [$this, 'ensure_can_view_portal'],
                'args' => [
                    'id' => [
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/documents/(?P<id>\d+)/analytics',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_get_document_analytics'],
                'permission_callback' => function ($request) {
                    return $this->ensure_manage_portal_with_nonce($request, 'vhona_cp_documents_version');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/documents/(?P<id>\d+)/versions',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_get_document_versions'],
                'permission_callback' => [$this, 'ensure_can_view_portal'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/documents/(?P<id>\d+)/versions',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_create_document_version'],
                'permission_callback' => function ($request) {
                    return $this->ensure_manage_portal_with_nonce($request, 'vhona_cp_documents_approve');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/documents/(?P<id>\d+)/versions/(?P<version>[a-z0-9\-]+)/approve',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_approve_document_version'],
                'permission_callback' => function ($request) {
                    return $this->ensure_manage_portal_with_nonce($request, 'vhona_cp_documents_chunk');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/documents/upload/chunk',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_upload_document_chunk'],
                'permission_callback' => function ($request) {
                    return $this->ensure_manage_portal_with_nonce($request, 'vhona_cp_documents_chunk');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/tasks',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_get_tasks'],
                'permission_callback' => [$this, 'ensure_can_view_portal'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/tasks',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_create_task'],
                'permission_callback' => function ($request) {
                    return $this->ensure_manage_portal_with_nonce($request, 'vhona_cp_tasks_create');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/tasks/(?P<id>\d+)',
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'rest_update_task'],
                'permission_callback' => function ($request) {
                    return $this->ensure_manage_portal_with_nonce($request, 'vhona_cp_tasks_update');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/conversations',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_get_conversations'],
                'permission_callback' => [$this, 'ensure_can_view_portal'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/conversations',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_create_conversation'],
                'permission_callback' => function ($request) {
                    return $this->ensure_portal_user_with_nonce($request, 'vhona_cp_conversation_create');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/conversations/(?P<id>\d+)',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_get_conversation_messages'],
                'permission_callback' => [$this, 'ensure_can_view_portal'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/conversations/(?P<id>\d+)/messages',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_add_conversation_message'],
                'permission_callback' => [$this, 'ensure_can_view_portal'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/activity',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_get_activity'],
                'permission_callback' => [$this, 'ensure_can_view_portal'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/integrations/overview',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_get_integration_overview'],
                'permission_callback' => [$this, 'ensure_can_view_portal'],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/integrations/refresh',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_refresh_integrations'],
                'permission_callback' => function ($request) {
                    return $this->ensure_manage_portal_with_nonce($request, 'vhona_cp_integrations_refresh');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/tour',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_get_tour'],
                'permission_callback' => function () {
                    return current_user_can('manage_client_portal');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/tour/progress',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_update_tour_progress'],
                'permission_callback' => function ($request) {
                    return $this->ensure_manage_portal_with_nonce($request, 'vhona_cp_tour_update');
                },
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/analytics/insights',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'rest_get_analytics_insights'],
                'permission_callback' => function () {
                    return current_user_can('manage_client_portal');
                },
            ]
        );
    }

    public function ensure_can_view_portal()
    {
        if (!is_user_logged_in()) {
            return false;
        }

        return $this->user_can_access_portal(get_current_user_id());
    }

    private function ensure_manage_portal_with_nonce(\WP_REST_Request $request, $action)
    {
        if (!current_user_can('manage_client_portal')) {
            return false;
        }

        return $this->verify_portal_nonce($request, $action);
    }

    private function ensure_portal_user_with_nonce(\WP_REST_Request $request, $action)
    {
        if (!$this->ensure_can_view_portal()) {
            return false;
        }

        return $this->verify_portal_nonce($request, $action);
    }

    private function verify_portal_nonce(\WP_REST_Request $request, $action)
    {
        $nonce = $request->get_header('X-Portal-Nonce');
        if (!$nonce) {
            $nonce = $request->get_param('_portal_nonce');
        }

        if (!$nonce) {
            return false;
        }

        return (bool) wp_verify_nonce($nonce, $action);
    }

    /**
     * Determine whether the specified user should see portal resources.
     *
     * @param int $user_id
     *
     * @return bool
     */
    private function user_can_access_portal($user_id)
    {
        $user_id = (int) $user_id;

        if ($user_id <= 0) {
            return false;
        }

        if (user_can($user_id, 'manage_client_portal')) {
            return true;
        }

        if (user_can($user_id, self::CAPABILITY_ACCESS_PORTAL)) {
            return true;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        return in_array('client_portal_client', (array) $user->roles, true);
    }

    public function rest_get_projects()
    {
        $query = new \WP_Query(
            [
                'post_type' => 'client_project',
                'post_status' => 'publish',
                'posts_per_page' => 100,
                'orderby' => 'date',
                'order' => 'DESC',
            ]
        );

        $projects = [];
        foreach ($query->posts as $post) {
            $projects[] = [
                'id' => (int) $post->ID,
                'title' => $post->post_title,
                'summary' => wp_trim_words($post->post_content, 40),
                'updated_at' => get_post_modified_time('c', true, $post),
            ];
        }

        return new \WP_REST_Response(['projects' => $projects]);
    }

    public function rest_get_documents()
    {
        $query = new \WP_Query(
            [
                'post_type' => 'client_document',
                'post_status' => 'publish',
                'posts_per_page' => 100,
                'orderby' => 'date',
                'order' => 'DESC',
            ]
        );

        $documents = [];
        foreach ($query->posts as $post) {
            $documents[] = $this->prepare_document_response($post->ID);
        }

        return new \WP_REST_Response(['documents' => $documents]);
    }

    public function rest_create_document(\WP_REST_Request $request)
    {
        $file = $this->extract_upload_file_from_request($request);
        if (is_wp_error($file)) {
            return $file;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $title = $request->get_param('title');
        $client_id = $request->get_param('client_id');

        $storage_result = $this->store_document_file($file);
        if (is_wp_error($storage_result)) {
            return $storage_result;
        }

        $post_id = wp_insert_post(
            [
                'post_type' => 'client_document',
                'post_title' => $title,
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
            ],
            true
        );

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        if ($client_id) {
            update_post_meta($post_id, '_vhona_client_id', (int) $client_id);
        }

        update_post_meta($post_id, self::META_STORAGE_PROVIDER, $storage_result['provider']);
        update_post_meta($post_id, self::META_STORAGE_KEY, $storage_result['object_key']);
        update_post_meta($post_id, self::META_STORAGE_URL, $storage_result['url']);

        if (isset($storage_result['attachment_id'])) {
            update_post_meta($post_id, self::META_ATTACHMENT_ID, (int) $storage_result['attachment_id']);
        }

        $this->log_activity('document_upload', sprintf(__('Uploaded document "%s"', 'vhona-client-portal'), $title), [
            'post_id' => $post_id,
            'provider' => $storage_result['provider'],
        ]);

        $upload_id = sanitize_text_field($request->get_param('upload_id'));
        if ($upload_id) {
            $this->clear_chunk_upload($upload_id);
        }

        return new \WP_REST_Response($this->prepare_document_response($post_id), 201);
    }

    public function rest_download_document(\WP_REST_Request $request)
    {
        $post_id = (int) $request['id'];
        $post = get_post($post_id);

        if (!$post || 'client_document' !== $post->post_type) {
            return new \WP_Error('not_found', __('Document not found.', 'vhona-client-portal'), ['status' => 404]);
        }

        $provider = get_post_meta($post_id, self::META_STORAGE_PROVIDER, true);
        $object_key = get_post_meta($post_id, self::META_STORAGE_KEY, true);

        if ($provider === self::STORAGE_PROVIDER_S3 && $object_key) {
            $url = $this->generate_s3_download_url($object_key);
            if (is_wp_error($url)) {
                return $url;
            }

            $this->record_document_download($post_id);

            return new \WP_REST_Response(['url' => $url]);
        }

        $attachment_id = (int) get_post_meta($post_id, self::META_ATTACHMENT_ID, true);
        if (!$attachment_id) {
            return new \WP_Error('missing_file', __('File not available.', 'vhona-client-portal'), ['status' => 404]);
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return new \WP_Error('missing_file', __('File not available.', 'vhona-client-portal'), ['status' => 404]);
        }

        $contents = file_get_contents($file_path);
        if (false === $contents) {
            return new \WP_Error('read_error', __('Unable to read file.', 'vhona-client-portal'), ['status' => 500]);
        }

        $response = new \WP_REST_Response($contents);
        $response->set_headers([
            'Content-Type' => mime_content_type($file_path) ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . basename($file_path) . '"',
        ]);

        $this->record_document_download($post_id);

        return $response;
    }

    private function record_document_download($post_id)
    {
        $count = (int) get_post_meta($post_id, self::META_DOCUMENT_DOWNLOAD_COUNT, true);
        $count++;
        update_post_meta($post_id, self::META_DOCUMENT_DOWNLOAD_COUNT, $count);

        $timestamp = current_time('mysql', true);
        update_post_meta($post_id, self::META_DOCUMENT_LAST_DOWNLOAD, $timestamp);

        $history = get_post_meta($post_id, self::META_DOCUMENT_DOWNLOAD_HISTORY, true);
        $history = is_array($history) ? $history : [];
        $history[] = [
            'timestamp' => $timestamp,
            'user' => get_current_user_id(),
        ];

        $settings = $this->get_settings();
        $retention = (int) $settings['analytics']['download_retention_days'];
        $cutoff = strtotime(sprintf('-%d days', max(1, $retention)), current_time('timestamp', true));

        $history = array_filter(
            $history,
            function ($entry) use ($cutoff) {
                $time = isset($entry['timestamp']) ? strtotime($entry['timestamp']) : false;
                return $time && $time >= $cutoff;
            }
        );

        update_post_meta($post_id, self::META_DOCUMENT_DOWNLOAD_HISTORY, array_values($history));

        $this->log_activity('document_download', __('Document downloaded', 'vhona-client-portal'), [
            'post_id' => $post_id,
            'downloads' => $count,
        ]);
    }

    public function rest_get_document_analytics(\WP_REST_Request $request)
    {
        $post_id = (int) $request['id'];
        $post = get_post($post_id);

        if (!$post || 'client_document' !== $post->post_type) {
            return new \WP_Error('not_found', __('Document not found.', 'vhona-client-portal'), ['status' => 404]);
        }

        $count = (int) get_post_meta($post_id, self::META_DOCUMENT_DOWNLOAD_COUNT, true);
        $last_download = get_post_meta($post_id, self::META_DOCUMENT_LAST_DOWNLOAD, true);
        $history = get_post_meta($post_id, self::META_DOCUMENT_DOWNLOAD_HISTORY, true);
        $history = is_array($history) ? $history : [];

        return new \WP_REST_Response([
            'downloads' => $count,
            'last_download_at' => $last_download,
            'history' => array_map(
                function ($entry) {
                    return [
                        'timestamp' => isset($entry['timestamp']) ? $entry['timestamp'] : '',
                        'user' => isset($entry['user']) ? (int) $entry['user'] : 0,
                    ];
                },
                $history
            ),
        ]);
    }

    public function rest_get_document_versions(\WP_REST_Request $request)
    {
        $post_id = (int) $request['id'];
        $post = get_post($post_id);

        if (!$post || 'client_document' !== $post->post_type) {
            return new \WP_Error('not_found', __('Document not found.', 'vhona-client-portal'), ['status' => 404]);
        }

        $versions = $this->get_document_versions($post_id);

        return new \WP_REST_Response([
            'versions' => array_map(function ($version) use ($post_id) {
                return $this->format_document_version($post_id, $version);
            }, $versions),
        ]);
    }

    public function rest_create_document_version(\WP_REST_Request $request)
    {
        $post_id = (int) $request['id'];
        $post = get_post($post_id);

        if (!$post || 'client_document' !== $post->post_type) {
            return new \WP_Error('not_found', __('Document not found.', 'vhona-client-portal'), ['status' => 404]);
        }

        $file = $this->extract_upload_file_from_request($request);
        if (is_wp_error($file) && 'missing_file' !== $file->get_error_code()) {
            return $file;
        }

        $version = [
            'id' => wp_generate_uuid4(),
            'label' => sanitize_text_field($request->get_param('label')) ?: sprintf(__('Version %d', 'vhona-client-portal'), count($this->get_document_versions($post_id)) + 1),
            'notes' => sanitize_textarea_field($request->get_param('notes')),
            'created_at' => current_time('mysql', true),
            'created_by' => get_current_user_id(),
            'status' => 'pending',
        ];

        $upload_id = sanitize_text_field($request->get_param('upload_id'));

        if (!is_wp_error($file) && !empty($file['tmp_name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $storage_result = $this->store_document_file($file);
            if (is_wp_error($storage_result)) {
                return $storage_result;
            }

            $version['storage'] = $storage_result;
        }

        $versions = $this->get_document_versions($post_id);
        $versions[] = $version;
        update_post_meta($post_id, self::META_DOCUMENT_VERSIONS, $versions);

        if ($upload_id) {
            $this->clear_chunk_upload($upload_id);
        }

        $this->log_activity('document_version', __('New document version created', 'vhona-client-portal'), [
            'post_id' => $post_id,
            'version' => $version['id'],
        ]);

        return new \WP_REST_Response($this->format_document_version($post_id, $version), 201);
    }

    public function rest_approve_document_version(\WP_REST_Request $request)
    {
        $post_id = (int) $request['id'];
        $version_id = sanitize_text_field($request['version']);
        $versions = $this->get_document_versions($post_id);

        $updated = false;
        foreach ($versions as &$version) {
            if ($version['id'] === $version_id) {
                $version['status'] = 'approved';
                $version['approved_at'] = current_time('mysql', true);
                $version['approved_by'] = get_current_user_id();
                $updated = true;
                break;
            }
        }
        unset($version);

        if (!$updated) {
            return new \WP_Error('not_found', __('Version not found.', 'vhona-client-portal'), ['status' => 404]);
        }

        update_post_meta($post_id, self::META_DOCUMENT_VERSIONS, $versions);

        $this->log_activity('document_approval', __('Document version approved', 'vhona-client-portal'), [
            'post_id' => $post_id,
            'version' => $version_id,
        ]);

        return new \WP_REST_Response($this->format_document_version($post_id, $this->find_document_version($versions, $version_id)));
    }

    public function rest_upload_document_chunk(\WP_REST_Request $request)
    {
        $upload_id = sanitize_key($request->get_param('upload_id'));
        if (!$upload_id) {
            $upload_id = uniqid('upload_', true);
        }

        $files = $request->get_file_params();
        if (empty($files)) {
            return new \WP_Error('missing_chunk', __('No chunk uploaded.', 'vhona-client-portal'), ['status' => 400]);
        }

        $chunk = reset($files);
        if (!isset($chunk['tmp_name']) || !file_exists($chunk['tmp_name'])) {
            return new \WP_Error('invalid_chunk', __('Chunk not readable.', 'vhona-client-portal'), ['status' => 400]);
        }

        $dir = $this->get_chunk_storage_dir();
        if (is_wp_error($dir)) {
            return $dir;
        }

        $part_path = trailingslashit($dir) . $upload_id . '.part';
        $data = file_get_contents($chunk['tmp_name']);
        if (false === $data) {
            return new \WP_Error('invalid_chunk', __('Unable to read uploaded chunk.', 'vhona-client-portal'), ['status' => 400]);
        }

        $written = file_put_contents($part_path, $data, FILE_APPEND);
        if (false === $written) {
            return new \WP_Error('storage_error', __('Unable to write chunk to disk.', 'vhona-client-portal'), ['status' => 500]);
        }

        $is_last = (bool) $request->get_param('is_last');
        $original_name = sanitize_file_name($request->get_param('name') ?: $chunk['name']);
        $meta = [
            'path' => $part_path,
            'name' => $original_name,
            'type' => sanitize_text_field($chunk['type']),
            'complete' => false,
        ];

        if ($is_last) {
            $final_path = trailingslashit($dir) . $upload_id . '.bin';
            if (!@rename($part_path, $final_path)) {
                return new \WP_Error('storage_error', __('Unable to finalise upload.', 'vhona-client-portal'), ['status' => 500]);
            }
            $meta['path'] = $final_path;
            $meta['complete'] = true;
        }

        set_transient($this->get_chunk_transient_key($upload_id), $meta, DAY_IN_SECONDS);

        return new \WP_REST_Response([
            'uploadId' => $upload_id,
            'completed' => $meta['complete'],
        ], $meta['complete'] ? 201 : 200);
    }

    public function rest_get_tasks(\WP_REST_Request $request)
    {
        $project_id = absint($request->get_param('project_id'));
        $query_args = [
            'post_type' => 'client_task',
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        if ($project_id) {
            $query_args['meta_query'] = [
                [
                    'key' => self::META_TASK_PROJECT,
                    'value' => $project_id,
                    'compare' => '=',
                ],
            ];
        }

        $query = new \WP_Query($query_args);

        $tasks = [];
        foreach ($query->posts as $post) {
            $tasks[] = $this->prepare_task_response($post->ID);
        }

        return new \WP_REST_Response(['tasks' => $tasks]);
    }

    public function rest_create_task(\WP_REST_Request $request)
    {
        $title = sanitize_text_field($request->get_param('title'));
        if (!$title) {
            return new \WP_Error('invalid_task', __('Task title is required.', 'vhona-client-portal'), ['status' => 400]);
        }

        $project_id = absint($request->get_param('project_id'));
        $due_at = sanitize_text_field($request->get_param('due_at'));

        $post_id = wp_insert_post(
            [
                'post_type' => 'client_task',
                'post_title' => $title,
                'post_content' => sanitize_textarea_field($request->get_param('description')),
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
            ],
            true
        );

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        if ($project_id) {
            update_post_meta($post_id, self::META_TASK_PROJECT, $project_id);
        }

        if ($due_at) {
            update_post_meta($post_id, self::META_TASK_DUE, $due_at);
        }

        $assignee = absint($request->get_param('assignee'));
        if ($assignee) {
            update_post_meta($post_id, self::META_TASK_ASSIGNEE, $assignee);
        }

        update_post_meta($post_id, self::META_TASK_STATUS, 'open');

        $this->log_activity('task_created', __('Task created', 'vhona-client-portal'), [
            'task_id' => $post_id,
            'project_id' => $project_id,
        ]);

        return new \WP_REST_Response($this->prepare_task_response($post_id), 201);
    }

    public function rest_update_task(\WP_REST_Request $request)
    {
        $task_id = (int) $request['id'];
        $post = get_post($task_id);

        if (!$post || 'client_task' !== $post->post_type) {
            return new \WP_Error('not_found', __('Task not found.', 'vhona-client-portal'), ['status' => 404]);
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = $request->get_body_params();
        }

        if (isset($params['status'])) {
            $status = sanitize_key($params['status']);
            update_post_meta($task_id, self::META_TASK_STATUS, $status);
        }

        if (isset($params['due_at'])) {
            update_post_meta($task_id, self::META_TASK_DUE, sanitize_text_field($params['due_at']));
        }

        if (isset($params['assignee'])) {
            update_post_meta($task_id, self::META_TASK_ASSIGNEE, absint($params['assignee']));
        }

        if (isset($params['title'])) {
            wp_update_post([
                'ID' => $task_id,
                'post_title' => sanitize_text_field($params['title']),
            ]);
        }

        if (isset($params['description'])) {
            wp_update_post([
                'ID' => $task_id,
                'post_content' => sanitize_textarea_field($params['description']),
            ]);
        }

        $this->log_activity('task_updated', __('Task updated', 'vhona-client-portal'), [
            'task_id' => $task_id,
        ]);

        return new \WP_REST_Response($this->prepare_task_response($task_id));
    }

    public function rest_get_conversations(\WP_REST_Request $request)
    {
        $query = new \WP_Query(
            [
                'post_type' => 'client_conversation',
                'post_status' => 'publish',
                'posts_per_page' => 100,
                'orderby' => 'modified',
                'order' => 'DESC',
            ]
        );

        $conversations = [];
        foreach ($query->posts as $post) {
            $conversations[] = $this->prepare_conversation_response($post->ID);
        }

        return new \WP_REST_Response(['conversations' => $conversations]);
    }

    public function rest_create_conversation(\WP_REST_Request $request)
    {
        $title = sanitize_text_field($request->get_param('title'));
        $message = sanitize_textarea_field($request->get_param('message'));
        $project_id = absint($request->get_param('project_id'));

        if (!$title || !$message) {
            return new \WP_Error('invalid_conversation', __('Title and message are required.', 'vhona-client-portal'), ['status' => 400]);
        }

        $post_id = wp_insert_post(
            [
                'post_type' => 'client_conversation',
                'post_title' => $title,
                'post_content' => $message,
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
                'comment_status' => 'open',
            ],
            true
        );

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        if ($project_id) {
            update_post_meta($post_id, self::META_CONVERSATION_PROJECT, $project_id);
        }

        $this->log_activity('conversation_created', __('Conversation started', 'vhona-client-portal'), [
            'conversation_id' => $post_id,
            'project_id' => $project_id,
        ]);

        return new \WP_REST_Response($this->prepare_conversation_response($post_id), 201);
    }

    public function rest_get_conversation_messages(\WP_REST_Request $request)
    {
        $conversation_id = (int) $request['id'];
        $post = get_post($conversation_id);

        if (!$post || 'client_conversation' !== $post->post_type) {
            return new \WP_Error('not_found', __('Conversation not found.', 'vhona-client-portal'), ['status' => 404]);
        }

        $messages = get_comments(
            [
                'post_id' => $conversation_id,
                'status' => 'approve',
                'orderby' => 'comment_date_gmt',
                'order' => 'ASC',
            ]
        );

        $formatted = array_map(
            function ($comment) {
                return [
                    'id' => (int) $comment->comment_ID,
                    'message' => wpautop($comment->comment_content),
                    'author' => (int) $comment->user_id,
                    'created_at' => $comment->comment_date_gmt,
                ];
            },
            $messages
        );

        return new \WP_REST_Response([
            'conversation' => $this->prepare_conversation_response($conversation_id),
            'messages' => $formatted,
        ]);
    }

    public function rest_add_conversation_message(\WP_REST_Request $request)
    {
        $conversation_id = (int) $request['id'];
        $post = get_post($conversation_id);

        if (!$post || 'client_conversation' !== $post->post_type) {
            return new \WP_Error('not_found', __('Conversation not found.', 'vhona-client-portal'), ['status' => 404]);
        }

        $message = sanitize_textarea_field($request->get_param('message'));
        if (!$message) {
            return new \WP_Error('invalid_message', __('Message cannot be empty.', 'vhona-client-portal'), ['status' => 400]);
        }

        $comment_id = wp_insert_comment(
            [
                'comment_post_ID' => $conversation_id,
                'comment_content' => $message,
                'user_id' => get_current_user_id(),
                'comment_approved' => 1,
            ]
        );

        if (!$comment_id) {
            return new \WP_Error('comment_error', __('Unable to add message.', 'vhona-client-portal'), ['status' => 500]);
        }

        wp_update_post([
            'ID' => $conversation_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', true),
        ]);

        $this->log_activity('conversation_message', __('Conversation reply added', 'vhona-client-portal'), [
            'conversation_id' => $conversation_id,
            'comment_id' => $comment_id,
        ]);

        return $this->rest_get_conversation_messages($request);
    }

    public function rest_get_activity(\WP_REST_Request $request)
    {
        $entries = $this->activity_logger->get_entries(['posts_per_page' => 20]);

        $timeline = array_map(
            function ($entry) {
                return [
                    'id' => $entry['id'],
                    'type' => $entry['type'],
                    'message' => $entry['message'],
                    'created_at' => $entry['created'],
                ];
            },
            $entries
        );

        return new \WP_REST_Response(['timeline' => $timeline]);
    }

    public function rest_get_integration_overview(\WP_REST_Request $request)
    {
        return new \WP_REST_Response($this->get_integration_cache());
    }

    public function rest_refresh_integrations(\WP_REST_Request $request)
    {
        $data = $this->refresh_integration_cache('manual');

        return new \WP_REST_Response($data, 201);
    }

    public function rest_get_analytics_insights(\WP_REST_Request $request)
    {
        return new \WP_REST_Response($this->get_portal_insights());
    }

    private function get_document_versions($post_id)
    {
        $versions = get_post_meta($post_id, self::META_DOCUMENT_VERSIONS, true);
        return is_array($versions) ? $versions : [];
    }

    private function format_document_version($post_id, array $version)
    {
        $data = [
            'id' => $version['id'],
            'label' => isset($version['label']) ? $version['label'] : '',
            'notes' => isset($version['notes']) ? $version['notes'] : '',
            'status' => isset($version['status']) ? $version['status'] : 'pending',
            'created_at' => isset($version['created_at']) ? $version['created_at'] : '',
            'created_by' => isset($version['created_by']) ? (int) $version['created_by'] : 0,
        ];

        if (!empty($version['approved_at'])) {
            $data['approved_at'] = $version['approved_at'];
        }

        if (!empty($version['approved_by'])) {
            $data['approved_by'] = (int) $version['approved_by'];
        }

        if (!empty($version['storage']) && is_array($version['storage'])) {
            $storage = $version['storage'];
            $download_url = '';

            if (!empty($storage['provider']) && $storage['provider'] === self::STORAGE_PROVIDER_S3 && !empty($storage['object_key'])) {
                $maybe_url = $this->generate_s3_download_url($storage['object_key']);
                if (!is_wp_error($maybe_url)) {
                    $download_url = $maybe_url;
                }
            } elseif (!empty($storage['attachment_id'])) {
                $download_url = wp_get_attachment_url((int) $storage['attachment_id']);
            } elseif (!empty($storage['url'])) {
                $download_url = $storage['url'];
            }

            $data['downloadUrl'] = $download_url;
        }

        return $data;
    }

    private function find_document_version(array $versions, $version_id)
    {
        foreach ($versions as $version) {
            if ($version['id'] === $version_id) {
                return $version;
            }
        }

        return null;
    }

    private function get_chunk_storage_dir()
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new \WP_Error('upload_dir', $uploads['error']);
        }

        if (!function_exists('wp_mkdir_p')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $dir = trailingslashit($uploads['basedir']) . 'vhona-client-portal-chunks';
        if (!file_exists($dir) && !wp_mkdir_p($dir)) {
            return new \WP_Error('upload_dir', __('Unable to prepare chunk directory.', 'vhona-client-portal'));
        }

        return $dir;
    }

    private function get_chunk_transient_key($upload_id)
    {
        return 'vhona_upload_' . sanitize_key($upload_id);
    }

    private function extract_upload_file_from_request(\WP_REST_Request $request)
    {
        $files = $request->get_file_params();
        if (!empty($files)) {
            $file = reset($files);
            if (!empty($file['error'])) {
                return new \WP_Error('upload_error', __('Upload failed.', 'vhona-client-portal'), ['status' => 400]);
            }
            if (!empty($file['tmp_name'])) {
                return $file;
            }
        }

        $upload_id = sanitize_key($request->get_param('upload_id'));
        if (!$upload_id) {
            return new \WP_Error('missing_file', __('Please attach a file.', 'vhona-client-portal'), ['status' => 400]);
        }

        $meta = get_transient($this->get_chunk_transient_key($upload_id));
        if (!$meta || empty($meta['complete']) || empty($meta['path']) || !file_exists($meta['path'])) {
            return new \WP_Error('upload_incomplete', __('Upload has not completed.', 'vhona-client-portal'), ['status' => 400]);
        }

        return [
            'name' => $meta['name'],
            'type' => $meta['type'],
            'tmp_name' => $meta['path'],
            'error' => 0,
            'size' => filesize($meta['path']),
        ];
    }

    private function clear_chunk_upload($upload_id)
    {
        $key = $this->get_chunk_transient_key($upload_id);
        $meta = get_transient($key);
        if ($meta && !empty($meta['path']) && file_exists($meta['path'])) {
            @unlink($meta['path']);
        }
        delete_transient($key);
    }

    private function prepare_task_response($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        return [
            'id' => (int) $post_id,
            'title' => $post->post_title,
            'description' => $post->post_content,
            'status' => get_post_meta($post_id, self::META_TASK_STATUS, true) ?: 'open',
            'project_id' => (int) get_post_meta($post_id, self::META_TASK_PROJECT, true),
            'due_at' => get_post_meta($post_id, self::META_TASK_DUE, true),
            'assignee' => (int) get_post_meta($post_id, self::META_TASK_ASSIGNEE, true),
            'updated_at' => get_post_modified_time('c', true, $post),
        ];
    }

    private function prepare_conversation_response($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $last_comment = get_comments([
            'post_id' => $post_id,
            'number' => 1,
            'status' => 'approve',
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC',
        ]);

        $last_reply = null;
        if (!empty($last_comment)) {
            $comment = $last_comment[0];
            $last_reply = [
                'id' => (int) $comment->comment_ID,
                'message' => wpautop($comment->comment_content),
                'created_at' => $comment->comment_date_gmt,
            ];
        }

        return [
            'id' => (int) $post_id,
            'title' => $post->post_title,
            'message' => $post->post_content,
            'project_id' => (int) get_post_meta($post_id, self::META_CONVERSATION_PROJECT, true),
            'updated_at' => get_post_modified_time('c', true, $post),
            'last_reply' => $last_reply,
        ];
    }

    private function fetch_integration_payload($endpoint, $token)
    {
        return $this->request_remote_json($endpoint, $token);
    }

    private function fetch_calendar_feed($url)
    {
        if (!$url) {
            return [];
        }

        $response = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['error' => sprintf(__('Unexpected response (%d)', 'vhona-client-portal'), $code)];
        }

        $body = wp_remote_retrieve_body($response);

        return [
            'feed' => $url,
            'length' => strlen($body),
        ];
    }

    private function get_integration_cache($force = false)
    {
        $cache = get_transient(self::OPTION_INTEGRATION_CACHE);

        if ($force || !is_array($cache)) {
            $settings = $this->get_settings();
            $integrations = $settings['integrations'];

            $cache = [
                'fastapi' => $this->fetch_fastapi_snapshot($integrations),
                'crm' => $this->fetch_integration_payload($integrations['crm_endpoint'], $integrations['crm_token']),
                'billing' => $this->fetch_integration_payload($integrations['billing_endpoint'], $integrations['billing_token']),
                'calendar' => $this->fetch_calendar_feed($integrations['calendar_feed']),
                'cached_at' => current_time('mysql', true),
            ];

            $ttl = isset($integrations['fastapi_cache_ttl']) ? (int) $integrations['fastapi_cache_ttl'] : 900;
            set_transient(self::OPTION_INTEGRATION_CACHE, $cache, max(60, $ttl));
        }

        return $cache;
    }

    public function refresh_integration_cache($context = 'cron')
    {
        delete_transient(self::OPTION_INTEGRATION_CACHE);
        $cache = $this->get_integration_cache(true);

        $this->log_activity('integrations_refresh', __('Integration snapshot refreshed', 'vhona-client-portal'), [
            'context' => $context,
        ]);

        return $cache;
    }

    private function fetch_fastapi_snapshot(array $integrations)
    {
        if (empty($integrations['fastapi_enabled']) || empty($integrations['fastapi_url'])) {
            return ['enabled' => false];
        }

        $base = untrailingslashit($integrations['fastapi_url']);
        $token = $integrations['fastapi_token'];
        $limit = max(1, (int) $integrations['fastapi_content_limit']);

        $snapshot = [
            'enabled' => true,
        ];

        $status = $this->request_remote_json($base . '/api', $token);
        if (isset($status['error'])) {
            $snapshot['status_error'] = $status['error'];
        } elseif (!empty($status)) {
            $snapshot['status'] = $status;
        }

        $profile = $this->request_remote_json($base . '/api/auth/profile', $token);
        if (isset($profile['error'])) {
            $snapshot['profile_error'] = $profile['error'];
        } elseif (!empty($profile)) {
            $snapshot['profile'] = $profile;
        }

        $history_url = add_query_arg('limit', $limit, $base . '/api/content/history');
        $history = $this->request_remote_json($history_url, $token);
        if (isset($history['error'])) {
            $snapshot['history_error'] = $history['error'];
        } else {
            $items = $history;
            if (is_array($history) && isset($history['items']) && is_array($history['items'])) {
                $items = $history['items'];
            }

            if (is_array($items)) {
                $snapshot['recent_content'] = array_slice($items, 0, $limit);
            } else {
                $snapshot['recent_content'] = $history;
            }
        }

        return $snapshot;
    }

    private function request_remote_json($endpoint, $token = '', array $args = [])
    {
        if (!$endpoint) {
            return [];
        }

        $defaults = [
            'timeout' => 10,
        ];

        $args = wp_parse_args($args, $defaults);

        if (!isset($args['headers'])) {
            $args['headers'] = [];
        }

        if ($token) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get($endpoint, $args);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['error' => sprintf(__('Unexpected response (%d)', 'vhona-client-portal'), $code)];
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return ['raw' => $body];
    }

    public function handle_portal_login($user_login, $user)
    {
        if (!$user instanceof \WP_User) {
            return;
        }

        if (!$this->user_can_access_portal($user->ID)) {
            return;
        }

        $this->record_login_metric((int) $user->ID);
        $this->log_activity('portal_login', __('Portal login recorded', 'vhona-client-portal'), ['user_id' => (int) $user->ID]);
    }

    private function record_login_metric($user_id)
    {
        $metrics = $this->get_metrics();

        if (!isset($metrics['logins']) || !is_array($metrics['logins'])) {
            $metrics['logins'] = [];
        }

        $metrics['logins'][] = [
            'timestamp' => current_time('timestamp', true),
            'user' => $user_id,
        ];

        $metrics['logins'] = array_slice($metrics['logins'], -500);

        update_option(self::OPTION_METRICS, $metrics);
    }

    private function get_metrics()
    {
        $metrics = get_option(self::OPTION_METRICS, []);

        if (!is_array($metrics)) {
            $metrics = [];
        }

        if (empty($metrics['logins']) || !is_array($metrics['logins'])) {
            $metrics['logins'] = [];
        }

        return $metrics;
    }

    private function summarise_login_metrics(array $logins)
    {
        $now = current_time('timestamp', true);
        $seven_cutoff = $now - WEEK_IN_SECONDS;
        $thirty_cutoff = $now - (30 * DAY_IN_SECONDS);

        $summary = [
            'total' => 0,
            'last_7_days' => 0,
            'last_30_days' => 0,
        ];

        foreach ($logins as $entry) {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            if ($timestamp <= 0) {
                continue;
            }

            $summary['total']++;

            if ($timestamp >= $seven_cutoff) {
                $summary['last_7_days']++;
            }

            if ($timestamp >= $thirty_cutoff) {
                $summary['last_30_days']++;
            }
        }

        return $summary;
    }

    private function get_document_download_summary()
    {
        $ids = get_posts([
            'post_type' => 'client_document',
            'post_status' => 'publish',
            'fields' => 'ids',
            'numberposts' => -1,
        ]);

        $summary = [
            'total_documents' => is_array($ids) ? count($ids) : 0,
            'total_downloads' => 0,
            'downloads_last_7_days' => 0,
            'downloads_last_30_days' => 0,
        ];

        if (empty($ids)) {
            return $summary;
        }

        $seven_cutoff = strtotime('-7 days', current_time('timestamp', true));
        $thirty_cutoff = strtotime('-30 days', current_time('timestamp', true));

        foreach ($ids as $post_id) {
            $summary['total_downloads'] += (int) get_post_meta($post_id, self::META_DOCUMENT_DOWNLOAD_COUNT, true);

            $history = get_post_meta($post_id, self::META_DOCUMENT_DOWNLOAD_HISTORY, true);
            if (!is_array($history)) {
                continue;
            }

            foreach ($history as $entry) {
                $timestamp = isset($entry['timestamp']) ? mysql2date('U', $entry['timestamp'], true) : false;
                if (!$timestamp) {
                    continue;
                }

                if ($timestamp >= $seven_cutoff) {
                    $summary['downloads_last_7_days']++;
                }

                if ($timestamp >= $thirty_cutoff) {
                    $summary['downloads_last_30_days']++;
                }
            }
        }

        return $summary;
    }

    private function get_portal_insights()
    {
        $metrics = $this->get_metrics();

        return [
            'logins' => $this->summarise_login_metrics($metrics['logins']),
            'documents' => $this->get_document_download_summary(),
            'setup' => $this->get_onboarding_checklist(),
        ];
    }

    private function get_onboarding_checklist()
    {
        $defaults = $this->get_default_settings();
        $settings = $this->get_settings();

        $branding_configured = !empty($settings['theme']['logo_url'])
            || $settings['theme']['accent_color'] !== $defaults['theme']['accent_color']
            || $settings['theme']['background_color'] !== $defaults['theme']['background_color'];

        $integrations_configured = (!empty($settings['integrations']['fastapi_enabled']) && !empty($settings['integrations']['fastapi_url']))
            || !empty($settings['integrations']['crm_endpoint'])
            || !empty($settings['integrations']['billing_endpoint']);

        $projects_count = wp_count_posts('client_project');
        $projects_created = $projects_count && (int) $projects_count->publish > 0;

        return [
            [
                'label' => __('Assign portal managers', 'vhona-client-portal'),
                'completed' => $this->has_user_with_capability('manage_client_portal'),
                'description' => __('Ensure at least one user can manage the portal and invite clients.', 'vhona-client-portal'),
            ],
            [
                'label' => __('Add client users', 'vhona-client-portal'),
                'completed' => $this->has_user_with_capability(self::CAPABILITY_ACCESS_PORTAL, ['role' => 'client_portal_client']),
                'description' => __('Invite clients or assign the access capability to existing accounts.', 'vhona-client-portal'),
            ],
            [
                'label' => __('Configure branding', 'vhona-client-portal'),
                'completed' => $branding_configured,
                'description' => __('Upload a logo or adjust colours so the portal matches your brand.', 'vhona-client-portal'),
            ],
            [
                'label' => __('Connect integrations', 'vhona-client-portal'),
                'completed' => $integrations_configured,
                'description' => __('Provide FastAPI, CRM, or billing endpoints to surface remote data.', 'vhona-client-portal'),
            ],
            [
                'label' => __('Create the first project', 'vhona-client-portal'),
                'completed' => $projects_created,
                'description' => __('Publish a client project so the dashboard has something to display.', 'vhona-client-portal'),
            ],
        ];
    }

    private function has_user_with_capability($capability, array $args = [])
    {
        $query_args = [
            'fields' => 'ids',
            'capability' => $capability,
            'number' => 1,
        ];

        $users = get_users(array_merge($query_args, $args));

        return !empty($users);
    }

    /**
     * Log document save events.
     */
    public function handle_document_save($post_id, $post, $update)
    {
        if (!$update) {
            return;
        }

        $this->log_activity('document_update', sprintf(__('Updated document "%s"', 'vhona-client-portal'), $post->post_title), [
            'post_id' => $post_id,
        ]);
    }

    /**
     * Register meta boxes for additional document info.
     */
    public function register_meta_boxes()
    {
        add_meta_box(
            'vhona_document_meta',
            __('Document details', 'vhona-client-portal'),
            [$this, 'render_document_meta_box'],
            'client_document',
            'side'
        );
    }

    public function render_document_meta_box($post)
    {
        $provider = get_post_meta($post->ID, self::META_STORAGE_PROVIDER, true) ?: 'local';
        $url = get_post_meta($post->ID, self::META_STORAGE_URL, true);
        ?>
        <p><?php esc_html_e('Storage provider:', 'vhona-client-portal'); ?> <strong><?php echo esc_html($provider); ?></strong></p>
        <?php if ($url) : ?>
            <p><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View current object', 'vhona-client-portal'); ?></a></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Store an uploaded document either locally or in S3.
     *
     * @param array $file
     *
     * @return array|\WP_Error
     */
    private function store_document_file(array $file)
    {
        $settings = $this->get_settings();
        $storage = $settings['storage'];

        if ($storage['provider'] === self::STORAGE_PROVIDER_S3 && $this->is_s3_configured($storage)) {
            return $this->upload_to_s3($file, $storage);
        }

        return $this->save_local_attachment($file);
    }

    private function save_local_attachment(array $file)
    {
        $file_array = [
            'name' => sanitize_file_name($file['name']),
            'type' => $file['type'],
            'tmp_name' => $file['tmp_name'],
            'error' => $file['error'],
            'size' => $file['size'],
        ];

        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        $url = wp_get_attachment_url($attachment_id);

        return [
            'provider' => 'local',
            'object_key' => (string) $attachment_id,
            'url' => $url,
            'attachment_id' => $attachment_id,
        ];
    }

    private function upload_to_s3(array $file, array $storage)
    {
        $contents = file_get_contents($file['tmp_name']);
        if (false === $contents) {
            return new \WP_Error('upload_error', __('Unable to read uploaded file.', 'vhona-client-portal'));
        }

        $object_key = $this->build_object_key($file['name'], $storage['s3_prefix']);
        $bucket = $storage['s3_bucket'];
        $region = $storage['s3_region'];
        $host = sprintf('%s.s3.%s.amazonaws.com', $bucket, $region);
        $endpoint = sprintf('https://%s/%s', $host, rawurlencode($object_key));

        $payload_hash = hash('sha256', $contents);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);

        $canonical_headers = sprintf("host:%s\n", $host);
        $canonical_headers .= sprintf("x-amz-content-sha256:%s\n", $payload_hash);
        $canonical_headers .= sprintf("x-amz-date:%s\n", $timestamp);
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';

        $canonical_request = implode("\n", [
            'PUT',
            '/' . str_replace('%2F', '/', rawurlencode($object_key)),
            '',
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);

        $credential_scope = sprintf('%s/%s/s3/aws4_request', $date, $region);
        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $timestamp,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        $signing_key = $this->get_aws_signing_key($storage['s3_secret_key'], $date, $region, 's3');
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $storage['s3_access_key'],
            $credential_scope,
            $signed_headers,
            $signature
        );

        $response = wp_remote_request(
            $endpoint,
            [
                'method' => 'PUT',
                'body' => $contents,
                'headers' => [
                    'Authorization' => $authorization,
                    'x-amz-content-sha256' => $payload_hash,
                    'x-amz-date' => $timestamp,
                    'Content-Type' => $file['type'] ?: 'application/octet-stream',
                ],
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return new \WP_Error('upload_error', __('Object storage upload failed.', 'vhona-client-portal'));
        }

        return [
            'provider' => self::STORAGE_PROVIDER_S3,
            'object_key' => $object_key,
            'url' => $endpoint,
        ];
    }

    private function build_object_key($filename, $prefix)
    {
        $safe = sanitize_file_name($filename);
        $unique = uniqid('', true);
        $path = trim($prefix, '/');

        if ($path) {
            return sprintf('%s/%s-%s', $path, gmdate('Y/m'), $unique . '-' . $safe);
        }

        return sprintf('%s-%s', $unique, $safe);
    }

    private function is_s3_configured(array $storage)
    {
        return $storage['s3_bucket'] && $storage['s3_region'] && $storage['s3_access_key'] && $storage['s3_secret_key'];
    }

    private function get_aws_signing_key($secret, $date, $region, $service)
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function generate_s3_download_url($object_key)
    {
        $settings = $this->get_settings();
        $storage = $settings['storage'];

        if (!$this->is_s3_configured($storage)) {
            return new \WP_Error('storage_disabled', __('Object storage not configured.', 'vhona-client-portal'));
        }

        $bucket = $storage['s3_bucket'];
        $region = $storage['s3_region'];
        $host = sprintf('%s.s3.%s.amazonaws.com', $bucket, $region);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);
        $expires = (int) $storage['ttl'];

        $credential_scope = sprintf('%s/%s/s3/aws4_request', $date, $region);
        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => sprintf('%s/%s', $storage['s3_access_key'], $credential_scope),
            'X-Amz-Date' => $timestamp,
            'X-Amz-Expires' => $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];

        $canonical_query = $this->build_canonical_query($query);

        $canonical_request = implode("\n", [
            'GET',
            '/' . str_replace('%2F', '/', rawurlencode($object_key)),
            $canonical_query,
            sprintf('host:%s\n\n', $host),
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $timestamp,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        $signing_key = $this->get_aws_signing_key($storage['s3_secret_key'], $date, $region, 's3');
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

        $query['X-Amz-Signature'] = $signature;

        return sprintf('https://%s/%s?%s', $host, rawurlencode($object_key), $this->build_canonical_query($query));
    }

    private function build_canonical_query(array $params)
    {
        ksort($params);

        $encoded = [];
        foreach ($params as $key => $value) {
            $encoded[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        return implode('&', $encoded);
    }

    private function prepare_document_response($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $provider = get_post_meta($post_id, self::META_STORAGE_PROVIDER, true) ?: 'local';
        $object_key = get_post_meta($post_id, self::META_STORAGE_KEY, true);
        $download_url = '';

        if ($provider === self::STORAGE_PROVIDER_S3 && $object_key) {
            $download_url = $this->generate_s3_download_url($object_key);
            if (is_wp_error($download_url)) {
                $download_url = '';
            }
        } else {
            $download_url = rest_url(sprintf('%s/documents/%d/download', self::REST_NAMESPACE, $post_id));
        }

        return [
            'id' => (int) $post_id,
            'title' => get_the_title($post_id),
            'provider' => $provider,
            'downloadUrl' => $download_url,
            'updated_at' => get_post_modified_time('c', true, $post),
        ];
    }

    /**
     * Enqueue the React bundle when needed.
     */
    public function enqueue_frontend_assets()
    {
        if (!is_singular() && !is_admin()) {
            return;
        }

        $handle = 'vhona-client-portal-app';
        $build_dir = $this->plugin_path . 'build/portal';
        $script_path = $build_dir . '/index.js';
        $style_path = $build_dir . '/index.css';

        if (file_exists($script_path)) {
            wp_enqueue_script(
                $handle,
                $this->plugin_url . 'build/portal/index.js',
                [],
                filemtime($script_path),
                true
            );

            if (file_exists($style_path)) {
                wp_enqueue_style(
                    $handle,
                    $this->plugin_url . 'build/portal/index.css',
                    [],
                    filemtime($style_path)
                );
            }
        } else {
            wp_register_script($handle, '', [], false, true);
            wp_enqueue_script($handle);
            wp_add_inline_script($handle, 'console.warn("Client portal build missing. Run yarn build:portal.");');
        }

        $this->localize_script($handle);
    }

    private function localize_script($handle)
    {
        $user = wp_get_current_user();
        $settings = $this->get_settings();

        wp_localize_script(
            $handle,
            'vhonaClientPortal',
            [
                'restUrl' => rest_url(self::REST_NAMESPACE),
                'nonce' => wp_create_nonce('wp_rest'),
                'user' => [
                    'id' => (int) $user->ID,
                    'name' => $user ? $user->display_name : '',
                ],
                'capabilities' => [
                    'manageClientPortal' => current_user_can('manage_client_portal'),
                ],
                'theme' => $settings['theme'],
                'integrations' => [
                    'calendarFeed' => $settings['integrations']['calendar_feed'],
                ],
                'nonces' => [
                    'documentsCreate' => wp_create_nonce('vhona_cp_documents_create'),
                    'documentsChunk' => wp_create_nonce('vhona_cp_documents_chunk'),
                    'documentsVersion' => wp_create_nonce('vhona_cp_documents_version'),
                    'documentsApprove' => wp_create_nonce('vhona_cp_documents_approve'),
                    'tasksCreate' => wp_create_nonce('vhona_cp_tasks_create'),
                    'tasksUpdate' => wp_create_nonce('vhona_cp_tasks_update'),
                    'conversationCreate' => wp_create_nonce('vhona_cp_conversation_create'),
                    'conversationReply' => wp_create_nonce('vhona_cp_conversation_reply'),
                    'integrationsRefresh' => wp_create_nonce('vhona_cp_integrations_refresh'),
                    'tourUpdate' => wp_create_nonce('vhona_cp_tour_update'),
                ],
                'analytics' => $settings['analytics'],
                'guidedTour' => $this->build_guided_tour_payload((int) $user->ID),
            ]
        );
    }

    /**
     * Render shortcode output.
     */
    public function render_portal_shortcode()
    {
        $this->enqueue_frontend_assets();
        return '<div id="vhona-client-portal-app"></div>';
    }

    private function log_activity($type, $message, array $context = [])
    {
        $this->activity_logger->log($type, $message, $context + ['actor' => get_current_user_id()]);
    }

    private function register_cli_commands()
    {
        if (!class_exists('\WP_CLI')) {
            return;
        }

        require_once __DIR__ . '/class-activity-cli.php';

        \WP_CLI::add_command('vhona-portal logs', '\\VHONA\\ClientPortal\\Activity_CLI_Command');
    }

    public function handle_export_logs()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to export logs.', 'vhona-client-portal'));
        }

        check_admin_referer('vhona_export_logs');

        $filters = [
            'type' => isset($_GET['log_type']) ? sanitize_key(wp_unslash($_GET['log_type'])) : '',
            'search' => isset($_GET['log_search']) ? sanitize_text_field(wp_unslash($_GET['log_search'])) : '',
            'after' => isset($_GET['log_after']) ? sanitize_text_field(wp_unslash($_GET['log_after'])) : '',
            'before' => isset($_GET['log_before']) ? sanitize_text_field(wp_unslash($_GET['log_before'])) : '',
        ];
        $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 200;
        $filters['posts_per_page'] = $limit;

        $filter_context = array_filter($filters, function ($value) {
            return '' !== $value && null !== $value;
        });

        $this->log_activity('logs_exported', __('Activity log exported', 'vhona-client-portal'), ['filters' => $filter_context]);
        $csv = $this->activity_logger->to_csv($filters);
        nocache_headers();
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="vhona-client-portal-logs.csv"');
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function handle_manual_erasure()
    {
        if (!current_user_can('manage_client_portal')) {
            wp_die(__('You do not have permission to erase portal data.', 'vhona-client-portal'));
        }

        check_admin_referer('vhona_erase_user');

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $email = isset($_POST['user_email']) ? sanitize_email(wp_unslash($_POST['user_email'])) : '';
        $delete_user = !empty($_POST['delete_user']);

        if ($user_id <= 0 && $email) {
            $user = get_user_by('email', $email);
            $user_id = $user ? (int) $user->ID : 0;
        } else {
            $user = $user_id > 0 ? get_user_by('id', $user_id) : false;
        }

        if (!$user instanceof \WP_User) {
            $this->log_activity('portal_erasure_failed', __('Portal erasure failed: user not found', 'vhona-client-portal'), [
                'email' => $email,
                'user_id' => $user_id,
            ]);
            $redirect = add_query_arg(
                [
                    'page' => 'vhona-client-portal',
                    'portal_message' => __('Unable to locate the selected user.', 'vhona-client-portal'),
                    'portal_message_type' => 'error',
                ],
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect);
            exit;
        }

        $summary = $this->erase_user_portal_data((int) $user->ID);

        if (is_wp_error($summary)) {
            $this->log_activity('portal_erasure_failed', __('Portal erasure failed', 'vhona-client-portal'), [
                'user_id' => (int) $user->ID,
                'error' => $summary->get_error_message(),
            ]);
            $redirect = add_query_arg(
                [
                    'page' => 'vhona-client-portal',
                    'portal_message' => $summary->get_error_message(),
                    'portal_message_type' => 'error',
                ],
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect);
            exit;
        }

        if ($delete_user) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user->ID);
        }

        $settings = $this->get_settings();
        if (!empty($settings['compliance']['notify_email'])) {
            wp_mail(
                $settings['compliance']['notify_email'],
                __('Portal erasure completed', 'vhona-client-portal'),
                sprintf(
                    /* translators: %s user login */
                    __('Portal data for %s has been scrubbed based on an administrator request.', 'vhona-client-portal'),
                    $user->user_login
                )
            );
        }

        $message = sprintf(
            /* translators: %s user login */
            __('Portal data erased for %s.', 'vhona-client-portal'),
            $user->user_login
        );

        $redirect = add_query_arg(
            [
                'page' => 'vhona-client-portal',
                'portal_message' => $message,
                'portal_message_type' => 'success',
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    private function erase_user_portal_data($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return new \WP_Error('invalid_user', __('Invalid user.', 'vhona-client-portal'));
        }

        $removed_download_entries = 0;
        $documents = get_posts([
            'post_type' => 'client_document',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        foreach ($documents as $document_id) {
            $history = get_post_meta($document_id, self::META_DOCUMENT_DOWNLOAD_HISTORY, true);
            if (empty($history) || !is_array($history)) {
                continue;
            }

            $filtered = array_filter(
                $history,
                function ($entry) use ($user_id, &$removed_download_entries) {
                    if (!is_array($entry)) {
                        return true;
                    }

                    $matches = isset($entry['user']) && (int) $entry['user'] === $user_id;
                    if ($matches) {
                        $removed_download_entries++;
                    }

                    return !$matches;
                }
            );

            if (count($filtered) !== count($history)) {
                update_post_meta($document_id, self::META_DOCUMENT_DOWNLOAD_HISTORY, array_values($filtered));
            }
        }

        $tasks_removed = 0;
        $task_ids = get_posts([
            'post_type' => 'client_task',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'author' => $user_id,
        ]);

        foreach ($task_ids as $task_id) {
            wp_delete_post($task_id, true);
            $tasks_removed++;
        }

        $tasks_unassigned = 0;
        $assigned_tasks = get_posts([
            'post_type' => 'client_task',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => self::META_TASK_ASSIGNEE,
                    'value' => $user_id,
                    'compare' => '=',
                ],
            ],
        ]);

        foreach ($assigned_tasks as $task_id) {
            delete_post_meta($task_id, self::META_TASK_ASSIGNEE);
            $tasks_unassigned++;
        }

        $conversation_posts_removed = 0;
        $conversations = get_posts([
            'post_type' => 'client_conversation',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'author' => $user_id,
        ]);
        foreach ($conversations as $conversation_id) {
            wp_delete_post($conversation_id, true);
            $conversation_posts_removed++;
        }

        $comments_removed = 0;
        $comments = get_comments([
            'user_id' => $user_id,
            'post_type' => 'client_conversation',
            'status' => 'approve',
            'number' => 0,
        ]);

        foreach ($comments as $comment) {
            wp_delete_comment($comment->comment_ID, true);
            $comments_removed++;
        }

        $logins_removed = $this->purge_user_metrics_for_user($user_id);

        $this->log_activity('portal_erasure', __('Portal data erased for user', 'vhona-client-portal'), [
            'user_id' => $user_id,
            'downloads_removed' => $removed_download_entries,
            'tasks_deleted' => $tasks_removed,
            'tasks_unassigned' => $tasks_unassigned,
            'conversations_deleted' => $conversation_posts_removed,
            'comments_deleted' => $comments_removed,
            'logins_removed' => $logins_removed,
        ]);

        return [
            'downloads_removed' => $removed_download_entries,
            'tasks_deleted' => $tasks_removed,
            'tasks_unassigned' => $tasks_unassigned,
            'conversations_deleted' => $conversation_posts_removed,
            'comments_deleted' => $comments_removed,
            'logins_removed' => $logins_removed,
        ];
    }

    private function purge_user_metrics_for_user($user_id)
    {
        $metrics = $this->get_metrics();
        $original = count($metrics['logins']);

        $metrics['logins'] = array_values(array_filter(
            $metrics['logins'],
            function ($entry) use ($user_id) {
                if (!is_array($entry)) {
                    return true;
                }

                return (int) ($entry['user'] ?? 0) !== $user_id;
            }
        ));

        if ($original !== count($metrics['logins'])) {
            update_option(self::OPTION_METRICS, $metrics);
        }

        return $original - count($metrics['logins']);
    }

    public function handle_retention_cleanup()
    {
        $settings = $this->get_settings();
        if (empty($settings['compliance']['auto_purge'])) {
            return;
        }

        $retention_days = max(7, (int) $settings['compliance']['retention_days']);
        $cutoff = current_time('timestamp', true) - ($retention_days * DAY_IN_SECONDS);

        $downloads_trimmed = $this->prune_document_download_history($cutoff);

        $metrics = $this->get_metrics();
        $before = count($metrics['logins']);
        $metrics['logins'] = array_values(array_filter(
            $metrics['logins'],
            function ($entry) use ($cutoff) {
                $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                return $timestamp === 0 || $timestamp >= $cutoff;
            }
        ));
        $logins_trimmed = $before - count($metrics['logins']);

        if ($logins_trimmed > 0) {
            update_option(self::OPTION_METRICS, $metrics);
        }

        if ($downloads_trimmed > 0 || $logins_trimmed > 0) {
            $this->log_activity('retention_cleanup', __('Retention policy purge executed', 'vhona-client-portal'), [
                'downloads_trimmed' => $downloads_trimmed,
                'logins_trimmed' => $logins_trimmed,
            ]);
        }
    }

    private function prune_document_download_history($cutoff)
    {
        $documents = get_posts([
            'post_type' => 'client_document',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        $trimmed = 0;

        foreach ($documents as $document_id) {
            $history = get_post_meta($document_id, self::META_DOCUMENT_DOWNLOAD_HISTORY, true);
            if (empty($history) || !is_array($history)) {
                continue;
            }

            $filtered = array_filter(
                $history,
                function ($entry) use ($cutoff, &$trimmed) {
                    if (!is_array($entry) || empty($entry['timestamp'])) {
                        return true;
                    }

                    $time = strtotime($entry['timestamp']);
                    if ($time && $time < $cutoff) {
                        $trimmed++;
                        return false;
                    }

                    return true;
                }
            );

            if (count($filtered) !== count($history)) {
                update_post_meta($document_id, self::META_DOCUMENT_DOWNLOAD_HISTORY, array_values($filtered));
            }
        }

        return $trimmed;
    }

    private function build_guided_tour_payload($user_id)
    {
        $settings = $this->get_settings();
        $enabled = !empty($settings['onboarding']['guided_tour_enabled']);
        $steps = $this->get_guided_tour_steps();
        $state = $user_id > 0 ? $this->get_user_tour_state($user_id) : ['completed' => [], 'dismissed' => false];

        return [
            'enabled' => $enabled,
            'dismissible' => !empty($settings['onboarding']['guided_tour_dismissible']),
            'steps' => $steps,
            'completed' => $state['completed'],
            'dismissed' => $state['dismissed'],
        ];
    }

    private function get_guided_tour_steps()
    {
        $settings_url = admin_url('admin.php?page=vhona-client-portal');
        $projects_url = admin_url('edit.php?post_type=client_project');
        $documents_url = admin_url('edit.php?post_type=client_document');

        return [
            [
                'id' => 'portal-settings',
                'title' => __('Review portal settings', 'vhona-client-portal'),
                'description' => __('Confirm branding, storage, and security settings before inviting clients.', 'vhona-client-portal'),
                'cta_label' => __('Open settings', 'vhona-client-portal'),
                'cta_url' => $settings_url,
            ],
            [
                'id' => 'create-project',
                'title' => __('Create a client project', 'vhona-client-portal'),
                'description' => __('Publish your first client project so the dashboard can showcase active work.', 'vhona-client-portal'),
                'cta_label' => __('Add project', 'vhona-client-portal'),
                'cta_url' => $projects_url,
            ],
            [
                'id' => 'upload-document',
                'title' => __('Upload a document', 'vhona-client-portal'),
                'description' => __('Share a deliverable or template to test secure downloads and analytics.', 'vhona-client-portal'),
                'cta_label' => __('Upload document', 'vhona-client-portal'),
                'cta_url' => $documents_url,
            ],
        ];
    }

    private function get_user_tour_state($user_id)
    {
        $completed = get_user_meta($user_id, self::USER_META_TOUR_COMPLETED, true);
        $dismissed = (bool) get_user_meta($user_id, self::USER_META_TOUR_DISMISSED, true);

        return [
            'completed' => is_array($completed) ? array_values(array_unique($completed)) : [],
            'dismissed' => $dismissed,
        ];
    }

    private function set_user_tour_state($user_id, array $state)
    {
        update_user_meta($user_id, self::USER_META_TOUR_COMPLETED, array_values(array_unique($state['completed'])));
        update_user_meta($user_id, self::USER_META_TOUR_DISMISSED, !empty($state['dismissed']));
    }

    public function rest_get_tour(\WP_REST_Request $request)
    {
        return new \WP_REST_Response($this->build_guided_tour_payload(get_current_user_id()));
    }

    public function rest_update_tour_progress(\WP_REST_Request $request)
    {
        $settings = $this->get_settings();
        if (empty($settings['onboarding']['guided_tour_enabled'])) {
            return new \WP_REST_Response($this->build_guided_tour_payload(get_current_user_id()));
        }

        $user_id = get_current_user_id();
        $action = sanitize_text_field($request->get_param('action')) ?: 'complete';
        $step_id = sanitize_text_field($request->get_param('step'));

        $state = $this->get_user_tour_state($user_id);

        if ('dismiss' === $action) {
            if (empty($settings['onboarding']['guided_tour_dismissible'])) {
                return new \WP_Error('tour_not_dismissible', __('The guided tour cannot be dismissed until all steps are complete.', 'vhona-client-portal'), ['status' => 403]);
            }

            $state['dismissed'] = true;
            $this->log_activity('tour_dismissed', __('Guided tour dismissed', 'vhona-client-portal'), ['user_id' => $user_id]);
        } elseif ('reset' === $action) {
            $state = ['completed' => [], 'dismissed' => false];
        } else {
            if (!$step_id) {
                return new \WP_Error('missing_step', __('A step identifier is required.', 'vhona-client-portal'), ['status' => 400]);
            }

            if (!in_array($step_id, $state['completed'], true)) {
                $state['completed'][] = $step_id;
                $this->log_activity('tour_step_completed', __('Guided tour step completed', 'vhona-client-portal'), [
                    'user_id' => $user_id,
                    'step' => $step_id,
                ]);
            }

            $state['dismissed'] = false;
        }

        $this->set_user_tour_state($user_id, $state);

        return new \WP_REST_Response($this->build_guided_tour_payload($user_id));
    }
}
