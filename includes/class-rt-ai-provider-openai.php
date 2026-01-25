<?php
/**
 * OpenAI Provider Implementation
 * 
 * Handles all OpenAI API interactions for the AI Search plugin
 * 
 * @package RivianTrackr_AI_Search
 * @since 3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-rt-ai-provider-base.php';

class RT_AI_Provider_OpenAI extends RT_AI_Provider_Base {

    /**
     * Constructor
     */
    public function __construct( $api_key = '', $model = '' ) {
        parent::__construct( $api_key, $model );
        
        $this->provider_id = 'openai';
        $this->provider_name = 'OpenAI';
        
        if ( empty( $this->model ) ) {
            $this->model = $this->get_default_model();
        }
    }

    /**
     * Get available models for OpenAI
     * 
     * @return array Array of model identifiers
     */
    public function get_available_models() {
        // Default curated list of chat completion models
        return array(
            'gpt-4o-mini',
            'gpt-4o',
            'gpt-4-turbo',
            'gpt-4.1-mini',
            'gpt-4.1-nano',
            'gpt-4.1',
            'gpt-4',
            'gpt-3.5-turbo',
            // Future models
            'gpt-5.2',
            'gpt-5.1',
            'gpt-5',
            'gpt-5-mini',
            'gpt-5-nano',
        );
    }

    /**
     * Get default model
     * 
     * @return string Default model identifier
     */
    public function get_default_model() {
        return 'gpt-4o-mini';
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

        $response = wp_safe_remote_get(
            'https://api.openai.com/v1/models',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                ),
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
            return array(
                'success' => false,
                'message' => 'API error (HTTP ' . $code . '). Please try again later.',
            );
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return array(
                'success' => false,
                'message' => 'Could not parse API response.',
            );
        }

        // Count available models
        $model_count = isset( $data['data'] ) ? count( $data['data'] ) : 0;
        
        // Check for chat models specifically
        $chat_models = array();
        if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
            foreach ( $data['data'] as $model ) {
                if ( isset( $model['id'] ) ) {
                    $id = $model['id'];
                    if ( strpos( $id, 'gpt-4' ) === 0 || strpos( $id, 'gpt-3.5' ) === 0 ) {
                        $chat_models[] = $id;
                    }
                }
            }
        }

        return array(
            'success'      => true,
            'message'      => 'API key is valid and working!',
            'model_count'  => $model_count,
            'chat_models'  => count( $chat_models ),
        );
    }

    /**
     * Fetch available models from OpenAI API
     * 
     * @return array Array of model identifiers
     */
    public function fetch_models_from_api() {
        if ( empty( $this->api_key ) ) {
            return array();
        }

        $response = wp_remote_get(
            'https://api.openai.com/v1/models',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[RivianTrackr AI Search] OpenAI model list error: ' . $response->get_error_message() );
            return array();
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            error_log( '[RivianTrackr AI Search] OpenAI model list HTTP error ' . $code );
            return array();
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['data'] ) ) {
            return array();
        }

        $models = array();
        foreach ( $data['data'] as $model ) {
            if ( empty( $model['id'] ) ) {
                continue;
            }

            $id = $model['id'];

            // Only include GPT chat models
            if (
                strpos( $id, 'gpt-5.1' ) === 0 ||
                strpos( $id, 'gpt-5' ) === 0 ||
                strpos( $id, 'gpt-4.1' ) === 0 ||
                strpos( $id, 'gpt-4o' ) === 0 ||
                strpos( $id, 'gpt-4-turbo' ) === 0 ||
                strpos( $id, 'gpt-4-' ) === 0 ||
                strpos( $id, 'gpt-4' ) === 0 ||
                strpos( $id, 'gpt-3.5-turbo' ) === 0
            ) {
                $models[] = $id;
            }
        }

        $models = array_unique( $models );
        sort( $models );

        return $models;
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
            return array( 'error' => 'OpenAI API key is not configured.' );
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
        return 'https://api.openai.com/v1/chat/completions';
    }

    /**
     * Build request body
     * 
     * @param string $system_message System prompt
     * @param string $user_message User message
     * @return array Request body
     */
    protected function build_request_body( $system_message, $user_message ) {
        $body = array(
            'model'    => $this->model,
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => $system_message,
                ),
                array(
                    'role'    => 'user',
                    'content' => $user_message,
                ),
            ),
        );

        // Don't set temperature for GPT-5 models (not supported yet)
        if ( strpos( $this->model, 'gpt-5' ) !== 0 ) {
            $body['temperature'] = 0.2;
        }

        // Use response_format for models that support it
        $supports_response_format = (
            strpos( $this->model, 'gpt-4o' ) === 0 ||
            strpos( $this->model, 'gpt-4.1' ) === 0 ||
            strpos( $this->model, 'gpt-5' ) === 0
        );

        if ( $supports_response_format ) {
            $body['response_format'] = array( 'type' => 'json_object' );
        }

        return $body;
    }

    /**
     * Parse API response
     * 
     * @param array $api_response API response
     * @return array Parsed response with 'answer_html' and 'results'
     */
    protected function parse_api_response( $api_response ) {
        if ( empty( $api_response['choices'][0]['message']['content'] ) ) {
            return array( 'error' => 'OpenAI returned an empty response. Please try again.' );
        }

        $raw_content = $api_response['choices'][0]['message']['content'];

        // Handle both string and array responses
        if ( is_array( $raw_content ) ) {
            $decoded = $raw_content;
        } else {
            $decoded = json_decode( $raw_content, true );

            // Try to extract JSON if wrapped in markdown or other text
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $first = strpos( $raw_content, '{' );
                $last  = strrpos( $raw_content, '}' );
                if ( $first !== false && $last !== false && $last > $first ) {
                    $json_candidate = substr( $raw_content, $first, $last - $first + 1 );
                    $decoded        = json_decode( $json_candidate, true );
                }
            }
        }

        if ( ! is_array( $decoded ) ) {
            return array( 'error' => 'Could not parse AI response. The service may be experiencing issues.' );
        }

        // Handle nested JSON (sometimes OpenAI double-encodes)
        if ( isset( $decoded['answer_html'] ) && is_string( $decoded['answer_html'] ) ) {
            $inner = trim( $decoded['answer_html'] );
            if ( strlen( $inner ) > 0 && $inner[0] === '{' && strpos( $inner, '"answer_html"' ) !== false ) {
                $inner_decoded = json_decode( $inner, true );
                if ( json_last_error() === JSON_ERROR_NONE && is_array( $inner_decoded ) && isset( $inner_decoded['answer_html'] ) ) {
                    $decoded = $inner_decoded;
                }
            }
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