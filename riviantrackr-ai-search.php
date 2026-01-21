<?php
declare(strict_types=1);
/**
 * Plugin Name: RivianTrackr AI Search
 * Plugin URI: https://github.com/RivianTrackr/RivianTrackr-AI-Search
 * Description: Add an OpenAI powered AI summary to WordPress search on RivianTrackr.com without delaying normal results, with analytics, cache control, and collapsible sources.
 * Version: 3.3.6
 * Author URI: https://riviantrackr.com
 * Author: RivianTrackr
 * License: GPL v2 or later
 */

define( 'RT_AI_SEARCH_VERSION', '3.3.6' );
define( 'RT_AI_SEARCH_MODELS_CACHE_TTL', 7 * DAY_IN_SECONDS );
define( 'RT_AI_SEARCH_MIN_CACHE_TTL', 60 );
define( 'RT_AI_SEARCH_MAX_CACHE_TTL', 86400 );
define( 'RT_AI_SEARCH_DEFAULT_CACHE_TTL', 3600 );
define( 'RT_AI_SEARCH_CONTENT_LENGTH', 400 );
define( 'RT_AI_SEARCH_EXCERPT_LENGTH', 200 );
define( 'RT_AI_SEARCH_MAX_SOURCES_DISPLAY', 5 );
define( 'RT_AI_SEARCH_API_TIMEOUT', 60 );
define( 'RT_AI_SEARCH_RATE_LIMIT_WINDOW', 70 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RivianTrackr_AI_Search {

    private $option_name         = 'rt_ai_search_options';
    private $models_cache_option = 'rt_ai_search_models_cache';
    private $cache_keys_option      = 'rt_ai_search_cache_keys';
    private $cache_namespace_option = 'rt_ai_search_cache_namespace';
    private $cache_prefix;
    private $cache_ttl           = 3600;

    private $logs_table_checked = false;
    private $logs_table_exists  = false;
    private $options_cache      = null;

    public function __construct() {
        
        $this->cache_prefix = 'rt_ai_search_v' . str_replace( '.', '_', RT_AI_SEARCH_VERSION ) . '_';
        
        // Hook into admin_init with higher priority to ensure it runs
        add_action( 'admin_init', array( $this, 'register_settings' ), 5 );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
        add_action( 'loop_start', array( $this, 'inject_ai_summary_placeholder' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'wp_ajax_rt_ai_test_api_key', array( $this, 'ajax_test_api_key' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_print_styles-index.php', array( $this, 'enqueue_dashboard_widget_css' ) );
        
        // Add debug action
        add_action( 'admin_notices', array( $this, 'debug_options_notice' ) );

        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_settings_link' ) );
    }
    
    /**
     * Debug function to show current options state
     */
    public function debug_options_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        // Only show on our settings page
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'rt-ai-search' ) === false ) {
            return;
        }
        
        $options = get_option( $this->option_name, false );
        
        if ( isset( $_GET['rt_debug'] ) && $_GET['rt_debug'] === '1' ) {
            ?>
            <div class="notice notice-info">
                <p><strong>Debug Info:</strong></p>
                <p>Option name: <?php echo esc_html( $this->option_name ); ?></p>
                <p>Options exist: <?php echo $options !== false ? 'Yes' : 'No'; ?></p>
                <p>Current options: <pre><?php echo esc_html( print_r( $options, true ) ); ?></pre></p>
            </div>
            <?php
        }
    }

    public function register_settings() {
        // Log that this is being called
        error_log( '[RivianTrackr AI Search] register_settings() called' );
        
        // Register the option
        register_setting(
            'rt_ai_search_group',           // Option group
            $this->option_name,             // Option name
            array(
                'sanitize_callback' => array( $this, 'sanitize_options' ),
                'default' => array(
                    'api_key'              => '',
                    'model'                => 'gpt-4o-mini',
                    'max_posts'            => 10,
                    'enable'               => 0,
                    'max_calls_per_minute' => 30,
                    'cache_ttl'            => RT_AI_SEARCH_DEFAULT_CACHE_TTL,
                    'custom_css'           => '',
                )
            )
        );

        // Add settings section
        add_settings_section(
            'rt_ai_search_main',
            'AI Search Settings',
            '__return_false',
            'rt-ai-search'
        );

        // Add fields (keeping simplified for debugging)
        add_settings_field(
            'api_key',
            'OpenAI API Key',
            array( $this, 'field_api_key' ),
            'rt-ai-search',
            'rt_ai_search_main'
        );

        add_settings_field(
            'enable',
            'Enable AI search summary',
            array( $this, 'field_enable' ),
            'rt-ai-search',
            'rt_ai_search_main'
        );
        
        error_log( '[RivianTrackr AI Search] register_settings() completed' );
    }

    public function sanitize_options( $input ) {
        error_log( '[RivianTrackr AI Search] sanitize_options() called' );
        error_log( '[RivianTrackr AI Search] Input: ' . print_r( $input, true ) );
        
        if ( ! is_array( $input ) ) {
            error_log( '[RivianTrackr AI Search] ERROR: Input is not an array!' );
            $input = array();
        }
        
        $output = array();

        $output['api_key']   = isset( $input['api_key'] ) ? trim( $input['api_key'] ) : '';
        $output['model']     = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : 'gpt-4o-mini';
        $output['max_posts'] = isset( $input['max_posts'] ) ? max( 1, intval( $input['max_posts'] ) ) : 10;
        $output['enable']    = ! empty( $input['enable'] ) ? 1 : 0;
        $output['max_calls_per_minute'] = isset( $input['max_calls_per_minute'] )
            ? max( 0, intval( $input['max_calls_per_minute'] ) )
            : 30;
            
        if ( isset( $input['cache_ttl'] ) ) {
            $ttl = intval( $input['cache_ttl'] );
            if ( $ttl < RT_AI_SEARCH_MIN_CACHE_TTL ) {
                $ttl = RT_AI_SEARCH_MIN_CACHE_TTL;
            } elseif ( $ttl > RT_AI_SEARCH_MAX_CACHE_TTL ) {
                $ttl = RT_AI_SEARCH_MAX_CACHE_TTL;
            }
            $output['cache_ttl'] = $ttl;
        } else {
            $output['cache_ttl'] = RT_AI_SEARCH_DEFAULT_CACHE_TTL;
        }
        
        $output['custom_css'] = isset( $input['custom_css'] ) ? wp_strip_all_tags( $input['custom_css'] ) : '';

        $this->options_cache = null;
        
        error_log( '[RivianTrackr AI Search] Output: ' . print_r( $output, true ) );
        
        // Add a success notice
        add_settings_error(
            $this->option_name,
            'rt_ai_settings_updated',
            'Settings saved successfully.',
            'success'
        );
        
        return $output;
    }

    public function get_options() {
        if ( is_array( $this->options_cache ) ) {
            return $this->options_cache;
        }

        $defaults = array(
            'api_key'              => '',
            'model'                => 'gpt-4o-mini',
            'max_posts'            => 10,
            'enable'               => 0,
            'max_calls_per_minute' => 30,
            'cache_ttl'            => RT_AI_SEARCH_DEFAULT_CACHE_TTL,
            'custom_css'           => '',
        );

        $opts = get_option( $this->option_name, array() );
        
        if ( ! is_array( $opts ) ) {
            error_log( '[RivianTrackr AI Search] WARNING: get_option returned non-array, using defaults' );
            $opts = array();
        }
        
        $this->options_cache = wp_parse_args( $opts, $defaults );

        return $this->options_cache;
    }

    public function field_api_key() {
        $options = $this->get_options();
        ?>
        <div class="rt-ai-field-input">
            <input type="password" 
                   id="rt-ai-api-key"
                   name="<?php echo esc_attr( $this->option_name ); ?>[api_key]"
                   value="<?php echo esc_attr( $options['api_key'] ); ?>"
                   placeholder="sk-proj-..." 
                   autocomplete="off"
                   style="width: 400px;" />
        </div>
        
        <p class="description">
            Create an API key in the OpenAI dashboard and paste it here.
            <br>
            <a href="https://platform.openai.com/api-keys" target="_blank">Get your API key →</a>
        </p>
        <?php
    }

    public function field_enable() {
        $options = $this->get_options();
        ?>
        <label>
            <input type="checkbox" 
                   name="<?php echo esc_attr( $this->option_name ); ?>[enable]"
                   value="1" 
                   <?php checked( $options['enable'], 1 ); ?> />
            Enable AI search summary
        </label>
        <p class="description">
            Turn on AI-powered search summaries site-wide.
        </p>
        <?php
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have sufficient permissions to access this page.' );
        }

        $options = $this->get_options();
        ?>
        
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            
            <!-- Debug link -->
            <p style="opacity: 0.6; font-size: 12px;">
                <a href="<?php echo admin_url( 'admin.php?page=rt-ai-search-settings&rt_debug=1' ); ?>">
                    Show debug info
                </a>
            </p>
            
            <?php settings_errors(); ?>
            
            <form method="post" action="options.php">
                <?php
                // Output security fields
                settings_fields( 'rt_ai_search_group' );
                
                // Output setting sections
                do_settings_sections( 'rt-ai-search' );
                
                // Output save button
                submit_button( 'Save Settings' );
                ?>
            </form>
            
            <hr>
            
            <h2>Manual Option Check</h2>
            <p>Current API Key: <?php echo ! empty( $options['api_key'] ) ? '(Set - ' . strlen( $options['api_key'] ) . ' characters)' : '(Not set)'; ?></p>
            <p>Enabled: <?php echo $options['enable'] ? 'Yes' : 'No'; ?></p>
            <p>Option name in database: <code><?php echo esc_html( $this->option_name ); ?></code></p>
            
            <hr>
            
            <h2>Manual Save Test</h2>
            <form method="post">
                <?php wp_nonce_field( 'rt_manual_save', 'rt_manual_save_nonce' ); ?>
                <p>
                    <label>
                        Test API Key:
                        <input type="text" name="test_api_key" value="" style="width: 400px;" />
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="test_enable" value="1" />
                        Enable
                    </label>
                </p>
                <p>
                    <button type="submit" name="rt_manual_save_submit" class="button button-secondary">
                        Manual Save (Bypass WordPress Settings API)
                    </button>
                </p>
            </form>
            
            <?php
            // Handle manual save
            if ( isset( $_POST['rt_manual_save_submit'] ) && 
                 isset( $_POST['rt_manual_save_nonce'] ) && 
                 wp_verify_nonce( $_POST['rt_manual_save_nonce'], 'rt_manual_save' ) ) {
                
                $test_options = array(
                    'api_key'              => isset( $_POST['test_api_key'] ) ? sanitize_text_field( $_POST['test_api_key'] ) : '',
                    'model'                => 'gpt-4o-mini',
                    'max_posts'            => 10,
                    'enable'               => ! empty( $_POST['test_enable'] ) ? 1 : 0,
                    'max_calls_per_minute' => 30,
                    'cache_ttl'            => 3600,
                    'custom_css'           => '',
                );
                
                $result = update_option( $this->option_name, $test_options );
                
                echo '<div class="notice notice-' . ( $result ? 'success' : 'error' ) . '"><p>';
                echo $result ? 'Manual save successful!' : 'Manual save failed!';
                echo '</p></div>';
                
                if ( $result ) {
                    echo '<p>Refresh the page to see updated values above.</p>';
                }
            }
            ?>
        </div>
        <?php
    }

    public function add_settings_page() {
        $capability  = 'manage_options';
        $parent_slug = 'rt-ai-search-settings';

        add_menu_page(
            'AI Search',
            'AI Search',
            $capability,
            $parent_slug,
            array( $this, 'render_settings_page' ),
            'dashicons-search',
            65
        );

        add_submenu_page(
            $parent_slug,
            'AI Search Settings',
            'Settings',
            $capability,
            $parent_slug,
            array( $this, 'render_settings_page' )
        );
    }

    public function add_plugin_settings_link( $links ) {
        $url = admin_url( 'admin.php?page=rt-ai-search-settings' );
        $settings_link = '<a href="' . esc_url( $url ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    // Stub methods to prevent errors
    public function enqueue_dashboard_widget_css() {}
    public function register_dashboard_widget() {}
    public function inject_ai_summary_placeholder() {}
    public function enqueue_frontend_assets() {}
    public function register_rest_routes() {}
    public function ajax_test_api_key() {}
    public function enqueue_admin_assets() {}
}

// Initialize the plugin
new RivianTrackr_AI_Search();