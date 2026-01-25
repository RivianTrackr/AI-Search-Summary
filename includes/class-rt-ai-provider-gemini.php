<?php
/**
 * Google Gemini Provider Implementation
 * 
 * Handles all Google Gemini API interactions for the AI Search plugin
 * 
 * @package RivianTrackr_AI_Search
 * @since 3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-rt-ai-provider-base.php';

class RT_AI_Provider_Gemini extends RT_AI_Provider_Base {

    /**
     * Constructor
     */
    public function __construct( $api_key = '', $model = '' ) {
        parent::__construct( $api_key, $model );
        
        $this->provider_id = 'gemini';
        $this->provider_name = 'Google Gemini';
        
        if ( empty( $this->model ) ) {
            $this->model = $this->get_default_model();
        }
    }

    /**
     * Get available models for Gemini
     * 
     * @return array Array of model identifiers
     */
    public function get_available_models() {
        return array(
            'gemini-2.0-flash-exp',
            'gemini-1.5-flash',
            'gemini-1.5-flash-8b',
            'gemini-1.5-pro',
            'gemini-pro',
        );
    }

    /**
     * Get default model
     * 
     * @return string Default model identifier
     */
    public function get_default_model() {
        return 'gemini-1.5-flash';
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

        // Test with a simple request
        $test_endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            'gemini-pro',
            $this->api_key
        );

        $response = wp_safe_remote_post(
            $test_endpoint,
            array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode( array(
                    'contents' => array(
                        array(
                            'parts' => array(
                                array( 'text' => 'Hello' )
                            )
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

        if ( $code === 400 ) {
            // Check if it's an API key error
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['error']['message'] ) && strpos( $body['error']['message'], 'API key' ) !== false ) {
                return array(
                    'success' => false,
                    'message' => 'Invalid API key. Please check your key and try again.',
                );
            }
        }

        if ( $code === 429 ) {
            return array(
                'success' => false,
                'message' => 'Rate limit exceeded. Your API key works but has hit rate limits.',
            );
        }

        if ( $code < 200 || $code >= 300 ) {
            return array(
                'success' => false,
                'message' => 'API error (HTTP ' . $code . '). Please try again later.',
            );
        }

        return array(
            'success' => true,
            'message' => 'API key is valid and working!',
        );
    }

    /**
     * Fetch available models from Gemini API
     * 
     * @return array Array of model identifiers
     */
    public function fetch_models_from_api() {
        if ( empty( $this->api_key ) ) {
            return array();
        }

        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $this->api_key;

        $response = wp_remote_get(
            $endpoint,
            array(
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[RivianTrackr AI Search] Gemini model list error: ' . $response->get_error_message() );
            return array();
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            error_log( '[RivianTrackr AI Search] Gemini model list HTTP error ' . $code );
            return array();
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['models'] ) ) {
            return $this->get_available_models(); // Fallback to defaults
        }

        $models = array();
        foreach ( $data['models'] as $model ) {
            if ( empty( $model['name'] ) ) {
                continue;
            }

            // Extract model ID from full name (e.g., "models/gemini-pro" -> "gemini-pro")
            $name = $model['name'];
            if ( strpos( $name, 'models/' ) === 0 ) {
                $name = substr( $name, 7 );
            }

            // Only include generative models
            if ( strpos( $name, 'gemini' ) === 0 ) {
                $models[] = $name;
            }
        }

        $models = array_unique( $models );
        sort( $models );

        return ! empty( $models ) ? $models : $this->get_available_models();
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
            return array( 'error' => 'Google Gemini API key is not configured.' );
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
        return sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $this->model,
            $this->api_key
        );
    }

    /**
     * Get request headers (Gemini doesn't use Authorization header)
     * 
     * @return array Request headers
     */
    protected function get_request_headers() {
        return array(
            'Content-Type' => 'application/json',
        );
    }

    /**
     * Build request body for Gemini API
     * 
     * @param string $system_message System prompt
     * @param string $user_message User message
     * @return array Request body
     */
    protected function build_request_body( $system_message, $user_message ) {
        // Gemini uses a different format than OpenAI
        // Combine system and user messages into a single prompt
        $full_prompt = $system_message . "\n\n" . $user_message;

        return array(
            'contents' => array(
                array(
                    'parts' => array(
                        array(
                            'text' => $full_prompt
                        )
                    )
                )
            ),
            'generationConfig' => array(
                'temperature' => 0.2,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 2048,
            ),
        );
    }

    /**
     * Parse Gemini API response
     * 
     * @param array $api_response API response
     * @return array Parsed response with 'answer_html' and 'results'
     */
    protected function parse_api_response( $api_response ) {
        // Gemini response structure is different from OpenAI
        if ( empty( $api_response['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return array( 'error' => 'Gemini returned an empty response. Please try again.' );
        }

        $raw_content = $api_response['candidates'][0]['content']['parts'][0]['text'];

        // Try to parse as JSON
        $decoded = json_decode( $raw_content, true );

        // Try to extract JSON if wrapped in markdown code blocks
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