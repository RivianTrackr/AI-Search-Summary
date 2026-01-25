<?php
/**
 * AI Provider Factory
 * 
 * Factory class for creating and managing AI provider instances
 * 
 * @package RivianTrackr_AI_Search
 * @since 3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RT_AI_Provider_Factory {

    /**
     * Available provider classes
     * 
     * @var array
     */
    private static $providers = array(
        'openai' => 'RT_AI_Provider_OpenAI',
        'gemini' => 'RT_AI_Provider_Gemini',
        'claude' => 'RT_AI_Provider_Claude',
    );

    /**
     * Get list of available providers
     * 
     * @return array Array of provider IDs and names
     */
    public static function get_available_providers() {
        return array(
            'openai' => 'OpenAI (ChatGPT)',
            'gemini' => 'Google Gemini',
            'claude' => 'Anthropic Claude',
        );
    }

    /**
     * Create a provider instance
     * 
     * @param string $provider_id Provider identifier (openai, gemini, claude)
     * @param string $api_key API key for the provider
     * @param string $model Model identifier
     * @return RT_AI_Provider_Base|null Provider instance or null on failure
     */
    public static function create( $provider_id, $api_key = '', $model = '' ) {
        if ( ! isset( self::$providers[ $provider_id ] ) ) {
            error_log( '[RivianTrackr AI Search] Unknown provider: ' . $provider_id );
            return null;
        }

        $class_name = self::$providers[ $provider_id ];
        
        // Load the provider class file
        $file_path = plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-rt-ai-provider-' . $provider_id . '.php';
        
        if ( ! file_exists( $file_path ) ) {
            error_log( '[RivianTrackr AI Search] Provider file not found: ' . $file_path );
            return null;
        }

        require_once $file_path;

        if ( ! class_exists( $class_name ) ) {
            error_log( '[RivianTrackr AI Search] Provider class not found: ' . $class_name );
            return null;
        }

        try {
            $provider = new $class_name( $api_key, $model );
            return $provider;
        } catch ( Exception $e ) {
            error_log( '[RivianTrackr AI Search] Error creating provider: ' . $e->getMessage() );
            return null;
        }
    }

    /**
     * Get provider display name
     * 
     * @param string $provider_id Provider identifier
     * @return string Provider display name
     */
    public static function get_provider_name( $provider_id ) {
        $providers = self::get_available_providers();
        return isset( $providers[ $provider_id ] ) ? $providers[ $provider_id ] : '';
    }

    /**
     * Validate provider ID
     * 
     * @param string $provider_id Provider identifier to validate
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_provider( $provider_id ) {
        return isset( self::$providers[ $provider_id ] );
    }

    /**
     * Get default provider
     * 
     * @return string Default provider ID
     */
    public static function get_default_provider() {
        return 'openai';
    }
}