<?php
/**
 * FluentForm REST API Integration
 *
 * Provides a REST endpoint that proxies submissions through FluentForm's
 * native submission handler so that all processors, notifications, and
 * integrations continue to run.
 */

use Exception;
use FluentForm\App\Services\Form\SubmissionHandlerService;
use FluentForm\Framework\Validator\ValidationException;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class RESTBridge_FluentForm_API {

    /**
     * Register REST API routes for FluentForm
     */
    public function register_routes() {
        register_rest_route('fluentapi/v1', '/submit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'submit_form'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handle form submission requests.
     */
    public function submit_form(WP_REST_Request $request): WP_REST_Response {
        $form_id   = $request->get_param('form_id');
        $rawFields = $request->get_param('fields');
        $source    = $request->get_param('source_url');

        if (!$form_id || !$rawFields) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Missing form data. Both form_id and fields are required.',
            ], 400);
        }

        if (!class_exists('\FluentForm\App\Models\Submission') || !class_exists(SubmissionHandlerService::class)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'FluentForm plugin is not active or not found.',
            ], 500);
        }

        $form_id = absint($form_id);
        if ($form_id <= 0) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Invalid form_id. Must be a positive integer.',
            ], 400);
        }

        if (!is_array($rawFields)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Fields must be provided as an array.',
            ], 400);
        }

        $fields = $this->normalize_fields($rawFields);

        if (empty($fields['_wp_http_referer'])) {
            $fields['_wp_http_referer'] = $source ? esc_url_raw($source) : home_url('/');
        }

        try {
            $handler  = new SubmissionHandlerService();
            $result   = $handler->handleSubmission($fields, $form_id);
            $entry_id = isset($result['insert_id']) ? (int) $result['insert_id'] : 0;

            if ($entry_id <= 0) {
                return new WP_REST_Response([
                    'success' => false,
                    'error'   => 'Failed to save form submission.',
                ], 500);
            }

            return new WP_REST_Response([
                'success'  => true,
                'entry_id' => $entry_id,
                'message'  => 'Form submitted successfully.',
                'result'   => $result['result'] ?? [],
            ], 200);
        } catch (ValidationException $exception) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Validation failed.',
                'details' => $exception->getErrors(),
            ], 422);
        } catch (Exception $exception) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'An error occurred while processing the form submission.',
                'details' => $exception->getMessage(),
            ], 500);
        }
    }

    /**
     * Recursively sanitize field values.
     */
    private function normalize_fields(array $fields): array {
        $normalized = [];

        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = $this->normalize_fields($value);
                continue;
            }

            if (is_bool($value) || is_numeric($value)) {
                $normalized[$key] = $value;
            } else {
                $normalized[$key] = sanitize_textarea_field((string) $value);
            }
        }

        return $normalized;
    }
}
