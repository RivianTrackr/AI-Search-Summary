<?php
/**
 * Anthropic Claude Provider Implementation
 * 
 * Handles all Anthropic Claude API interactions for the AI Search plugin
 * 
 * @package RivianTrackr_AI_Search
 * @since 3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-rt-ai-provider-base.php';

class RT_AI_Provider_Claude extends RT_AI_Provider_Base {

    /**
     * API version for Claude
     * 
     * @var string
     */
    private $api_version = '2023-06-01';

    /**
     * Constructor
     */
    public function __construct( $api_key = '', $model = '' ) {
        parent::__construct( $api_key, $model );
        
        $this->provider_id = 'claude';
        $this->provider_name = 'Anthropic Claude';
        
        if ( empty( $this->model ) ) {
            $this->model = $this->get_default_model();
        }
    }

    /**
     * Get available models for Claude
     * 
     * @return array Array of model identifiers
     */
    public function get_available_models() {
        return array(
            'claude-3-5-sonnet-20241022',
            'claude-3-5-haiku-20241022',
            'claude-3-opus-20240229',
            'claude-3-sonnet-20240229',
            'claude-3-haiku-20240307',
        );
    }

    /**
     * Get default model
     * 
     * @return string Default model identifier
     */
    public function get_default_model() {
        return 'claude-3-5-haiku-20241022';
    }

    /**
     * Test API key validity
     * 
     * @return array Array with 'success' (bool) and 'message' (string)
     */
    public function test_api_key() {
        if ( empty( $this->api_key ) ) {
            return array(
                'success' => false,
                'message' => 'API key is empty.',
            );
        }

        // Test with a minimal message request
        $response = wp_safe_remote_post(
            'https://api.anthropic.com/v1/messages',
            array(
                'headers' => array(
                    'x-api-key' => $this->api_key,
                    'anthropic-version' => $this->api_version,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode( array(
                    'model' => $this->get_default_model(),
                    'max_tokens' => 10,
                    'messages' => array(
                        array(
                            'role' => 'user',
                            'content' => 'Hello'
                        )
                    )
                ) ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'message' => 'Connection error: ' . $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code === 401 ) {
            return array(
                'success' => false,
                'message' => 'Invalid API key. Please check your key and try again.',
            );
        }

        if ( $code === 429 ) {
            return array(
                'success' => false,
                'message' => 'Rate limit exceeded. Your API key works but has hit rate limits.',
            );
        }

        if ( $code < 200 || $code >= 300 ) {
            $error_data = json_decode( $body, true );
            $error_message = isset( $error_data['error']['message'] ) 
                ? $error_data['error']['message'] 
                : 'API error (HTTP ' . $code . ')';
            
            return array(
                'success' => false,
                'message' => $error_message,
            );
        }

        return array(
            'success' => true,
            'message' => 'API key is valid and working!',
        );
    }

    /**
     * Fetch available models from Claude API
     * 
     * Note: Claude doesn't have a models endpoint, so we return the static list
     * 
     * @return array Array of model identifiers
     */
    public function fetch_models_from_api() {
        // Claude doesn't have a public models list endpoint
        // Return the static list of known models
        return $this->get_available_models();
    }

    /**
     * Generate AI summary
     * 
     * @param string $search_query User's search query
     * @param array  $posts Array of post data
     * @return array Result with 'answer_html' and 'results', or 'error'
     */
    public function generate_summary( $search_query, $posts ) {
        if ( empty( $this->api_key ) ) {
            return array( 'error' => 'Anthropic Claude API key is not configured.' );
        }

        $system_message = $this->get_system_prompt();
        $user_message = $this->build_user_message( $search_query, $posts );
        
        $endpoint = $this->get_api_endpoint();
        $body = $this->build_request_body( $system_message, $user_message );

        $response = $this->make_api_request( $endpoint, $body, RT_AI_SEARCH_API_TIMEOUT );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => $this->get_user_friendly_error( $response ) );
        }

        return $this->parse_api_response( $response );
    }

    /**
     * Get API endpoint
     * 
     * @return string API endpoint URL
     */
    protected function get_api_endpoint() {
        return 'https://api.anthropic.com/v1/messages';
    }

    /**
     * Get request headers (Claude uses x-api-key instead of Bearer)
     * 
     * @return array Request headers
     */
    protected function get_request_headers() {
        return array(
            'x-api-key' => $this->api_key,
            'anthropic-version' => $this->api_version,
            'Content-Type' => 'application/json',
        );
    }

    /**
     * Build request body for Claude API
     * 
     * @param string $system_message System prompt
     * @param string $user_message User message
     * @return array Request body
     */
    protected function build_request_body( $system_message, $user_message ) {
        return array(
            'model' => $this->model,
            'max_tokens' => 4096,
            'temperature' => 0.2,
            'system' => $system_message,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $user_message
                )
            )
        );
    }

    /**
     * Parse Claude API response
     * 
     * @param array $api_response API response
     * @return array Parsed response with 'answer_html' and 'results'
     */
    protected function parse_api_response( $api_response ) {
        // Claude response structure
        if ( empty( $api_response['content'][0]['text'] ) ) {
            return array( 'error' => 'Claude returned an empty response. Please try again.' );
        }

        $raw_content = $api_response['content'][0]['text'];

        // Try to parse as JSON
        $decoded = json_decode( $raw_content, true );

        // Try to extract JSON if wrapped in markdown code blocks or other text
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            // Remove markdown code fences if present
            $cleaned = preg_replace( '/```json\s*|\s*```/', '', $raw_content );
            $decoded = json_decode( $cleaned, true );

            // If still not valid, try to find JSON object
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $first = strpos( $raw_content, '{' );
                $last  = strrpos( $raw_content, '}' );
                if ( $first !== false && $last !== false && $last > $first ) {
                    $json_candidate = substr( $raw_content, $first, $last - $first + 1 );
                    $decoded = json_decode( $json_candidate, true );
                }
            }
        }

        if ( ! is_array( $decoded ) ) {
            return array( 'error' => 'Could not parse AI response. The service may be experiencing issues.' );
        }

        // Ensure required fields exist
        if ( empty( $decoded['answer_html'] ) ) {
            $decoded['answer_html'] = '<p>AI summary did not return a valid answer.</p>';
        }

        if ( empty( $decoded['results'] ) || ! is_array( $decoded['results'] ) ) {
            $decoded['results'] = array();
        }

        return $decoded;
    }
}