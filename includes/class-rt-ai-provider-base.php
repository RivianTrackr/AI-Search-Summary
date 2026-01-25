<?php
/**
 * Abstract base class for AI providers
 * 
 * Defines the common interface that all AI providers must implement
 * 
 * @package RivianTrackr_AI_Search
 * @since 3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class RT_AI_Provider_Base {

    /**
     * Provider identifier (e.g., 'openai', 'gemini', 'claude')
     * 
     * @var string
     */
    protected $provider_id;

    /**
     * Provider display name (e.g., 'OpenAI', 'Google Gemini', 'Anthropic Claude')
     * 
     * @var string
     */
    protected $provider_name;

    /**
     * API key for this provider
     * 
     * @var string
     */
    protected $api_key;

    /**
     * Selected model for this provider
     * 
     * @var string
     */
    protected $model;

    /**
     * Constructor
     * 
     * @param string $api_key API key for the provider
     * @param string $model Model identifier
     */
    public function __construct( $api_key = '', $model = '' ) {
        $this->api_key = $api_key;
        $this->model = $model;
    }

    /**
     * Get provider identifier
     * 
     * @return string Provider ID
     */
    public function get_provider_id() {
        return $this->provider_id;
    }

    /**
     * Get provider display name
     * 
     * @return string Provider name
     */
    public function get_provider_name() {
        return $this->provider_name;
    }

    /**
     * Get available models for this provider
     * 
     * @return array Array of model identifiers
     */
    abstract public function get_available_models();

    /**
     * Get default model for this provider
     * 
     * @return string Default model identifier
     */
    abstract public function get_default_model();

    /**
     * Test API key validity
     * 
     * @return array Array with 'success' (bool) and 'message' (string)
     */
    abstract public function test_api_key();

    /**
     * Fetch available models from API
     * 
     * @return array Array of model identifiers, or empty array on failure
     */
    abstract public function fetch_models_from_api();

    /**
     * Generate AI summary for search query
     * 
     * @param string $search_query User's search query
     * @param array  $posts Array of post data to use as context
     * @return array Array with 'answer_html' and 'results', or array with 'error' on failure
     */
    abstract public function generate_summary( $search_query, $posts );

    /**
     * Get API endpoint URL for this provider
     * 
     * @return string API endpoint URL
     */
    abstract protected function get_api_endpoint();

    /**
     * Build request body for API call
     * 
     * @param string $system_message System prompt
     * @param string $user_message User message with context
     * @return array Request body array
     */
    abstract protected function build_request_body( $system_message, $user_message );

    /**
     * Parse API response and extract answer
     * 
     * @param array $api_response Decoded API response
     * @return array Parsed response with 'answer_html' and 'results'
     */
    abstract protected function parse_api_response( $api_response );

    /**
     * Get request headers for API call
     * 
     * @return array Request headers
     */
    protected function get_request_headers() {
        return array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
        );
    }

    /**
     * Make HTTP request to AI API
     * 
     * @param string $endpoint API endpoint URL
     * @param array  $body Request body
     * @param int    $timeout Request timeout in seconds
     * @return array|WP_Error API response or WP_Error on failure
     */
    protected function make_api_request( $endpoint, $body, $timeout = 60 ) {
        $args = array(
            'headers' => $this->get_request_headers(),
            'body'    => wp_json_encode( $body ),
            'timeout' => $timeout,
        );

        $response = wp_safe_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'api_error',
                sprintf( 'API returned HTTP %d: %s', $code, $response_body ),
                array( 'status' => $code, 'body' => $response_body )
            );
        }

        $decoded = json_decode( $response_body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error(
                'json_error',
                'Failed to decode API response: ' . json_last_error_msg()
            );
        }

        return $decoded;
    }

    /**
     * Build system prompt for AI
     * 
     * @return string System prompt
     */
    protected function get_system_prompt() {
        return "You are the AI search engine for RivianTrackr.com, a Rivian focused news and guide site.
Use the provided posts as your entire knowledge base.
Answer the user query based only on these posts.
Prefer newer posts over older ones when there is conflicting or overlapping information, especially for news, software updates, or product changes.
If something is not covered, say that the site does not have that information yet instead of making something up.

Always respond as a single JSON object using this structure:
{
  \"answer_html\": \"HTML formatted summary answer for the user\",
  \"results\": [
     {
       \"id\": 123,
       \"title\": \"Post title\",
       \"url\": \"https://...\",
       \"excerpt\": \"Short snippet\",
       \"type\": \"post or page\"
     }
  ]
}

The results array should list up to 5 of the most relevant posts you used when creating the summary, so they can be shown as sources under the answer.";
    }

    /**
     * Build user message with post context
     * 
     * @param string $search_query User's search query
     * @param array  $posts Array of post data
     * @return string Formatted user message
     */
    protected function build_user_message( $search_query, $posts ) {
        $posts_text = '';
        foreach ( $posts as $p ) {
            $date = isset( $p['date'] ) ? $p['date'] : '';
            $posts_text .= "ID: {$p['id']}\n";
            $posts_text .= "Title: {$p['title']}\n";
            $posts_text .= "URL: {$p['url']}\n";
            $posts_text .= "Type: {$p['type']}\n";
            if ( $date ) {
                $posts_text .= "Published: {$date}\n";
            }
            $posts_text .= "Content: {$p['content']}\n";
            $posts_text .= "-----\n";
        }

        $user_message  = "User search query: {$search_query}\n\n";
        $user_message .= "Here are the posts from the site (with newer posts listed first where possible):\n\n{$posts_text}";

        return $user_message;
    }

    /**
     * Handle user-friendly error messages
     * 
     * @param WP_Error $error WordPress error object
     * @return string User-friendly error message
     */
    protected function get_user_friendly_error( $error ) {
        $error_msg = $error->get_error_message();
        $error_data = $error->get_error_data();

        // Timeout errors
        if ( strpos( $error_msg, 'cURL error 28' ) !== false || strpos( $error_msg, 'timed out' ) !== false ) {
            return 'Request timed out. The AI service may be slow right now. Please try again.';
        }

        // Connection errors
        if ( strpos( $error_msg, 'cURL error 6' ) !== false || strpos( $error_msg, 'resolve host' ) !== false ) {
            return 'Could not connect to AI service. Please check your internet connection.';
        }

        // HTTP errors
        if ( isset( $error_data['status'] ) ) {
            $status = $error_data['status'];
            $body = isset( $error_data['body'] ) ? json_decode( $error_data['body'], true ) : null;

            if ( $status === 401 ) {
                return 'Invalid API key. Please check your plugin settings.';
            }

            if ( $status === 429 ) {
                return $this->provider_name . ' rate limit exceeded. Please try again in a few moments.';
            }

            if ( $status === 500 || $status === 503 ) {
                return $this->provider_name . ' service temporarily unavailable. Please try again later.';
            }

            // Try to extract error message from body
            if ( $body && isset( $body['error']['message'] ) ) {
                return $body['error']['message'];
            }
        }

        // Generic error
        return 'AI service error: ' . $error_msg;
    }
}