<?php

class RESTBridge_Media_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/media', [
			'methods' => 'GET',
			'callback' => [$this, 'get_media'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/media', [
			'methods' => 'POST',
			'callback' => [$this, 'create_media'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/media/(?P<id>\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_media_item'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/media/(?P<id>\d+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_media'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/media/(?P<id>\d+)', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_media'],
			'permission_callback' => [$this, 'check_permission'],
		]);
	}

	public function get_media(WP_REST_Request $request) {
		$params = $request->get_query_params();
		
		$args = [
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => isset($params['per_page']) ? (int) $params['per_page'] : 10,
			'paged' => isset($params['page']) ? (int) $params['page'] : 1,
			'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'date',
			'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
		];

		if (isset($params['mime_type'])) {
			$args['post_mime_type'] = sanitize_text_field($params['mime_type']);
		}

		if (isset($params['author'])) {
			$args['author'] = (int) $params['author'];
		}

		$query = new WP_Query($args);
		$media = [];

		while ($query->have_posts()) {
			$query->the_post();
			$media[] = $this->format_media(get_post());
		}
		wp_reset_postdata();

		return rest_ensure_response([
			'media' => $media,
			'total' => $query->found_posts,
			'pages' => $query->max_num_pages,
		]);
	}

	public function get_media_item(WP_REST_Request $request) {
		$media_id = (int) $request['id'];
		$media = get_post($media_id);

		if (!$media || $media->post_type !== 'attachment') {
			return new WP_Error('media_not_found', 'Media not found', ['status' => 404]);
		}

		return rest_ensure_response($this->format_media($media));
	}

	public function create_media(WP_REST_Request $request) {
		$files = $request->get_file_params();
		
		if (!isset($files['file'])) {
			return new WP_Error('no_file', 'No file provided', ['status' => 400]);
		}

		$file = $files['file'];
		$params = $request->get_body_params();

		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		$upload = wp_handle_upload($file, ['test_form' => false]);

		if (isset($upload['error'])) {
			return new WP_Error('upload_error', $upload['error'], ['status' => 500]);
		}

		$attachment_data = [
			'post_mime_type' => $upload['type'],
			'post_title' => isset($params['title']) ? sanitize_text_field($params['title']) : basename($upload['file']),
			'post_content' => isset($params['description']) ? sanitize_textarea_field($params['description']) : '',
			'post_status' => 'inherit',
		];

		$attachment_id = wp_insert_attachment($attachment_data, $upload['file']);
		
		if (is_wp_error($attachment_id)) {
			return $attachment_id;
		}

		$attach_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
		wp_update_attachment_metadata($attachment_id, $attach_data);

		return rest_ensure_response($this->format_media(get_post($attachment_id)), 201);
	}

	public function update_media(WP_REST_Request $request) {
		$media_id = (int) $request['id'];
		$params = $request->get_json_params();

		$media = get_post($media_id);
		if (!$media || $media->post_type !== 'attachment') {
			return new WP_Error('media_not_found', 'Media not found', ['status' => 404]);
		}

		$update_data = ['ID' => $media_id];
		
		if (isset($params['title'])) {
			$update_data['post_title'] = sanitize_text_field($params['title']);
		}
		if (isset($params['description'])) {
			$update_data['post_content'] = sanitize_textarea_field($params['description']);
		}

		$updated = wp_update_post($update_data);

		if (is_wp_error($updated)) {
			return $updated;
		}

		return rest_ensure_response($this->format_media(get_post($media_id)));
	}

	public function delete_media(WP_REST_Request $request) {
		$media_id = (int) $request['id'];
		$force = isset($request['force']) && $request['force'];

		$result = wp_delete_attachment($media_id, $force);

		if (!$result) {
			return new WP_Error('delete_failed', 'Failed to delete media', ['status' => 500]);
		}

		return rest_ensure_response(['deleted' => true, 'id' => $media_id]);
	}

	private function format_media($media) {
		$attachment_url = wp_get_attachment_url($media->ID);
		$attachment_metadata = wp_get_attachment_metadata($media->ID);

		return [
			'id' => $media->ID,
			'title' => get_the_title($media->ID),
			'description' => $media->post_content,
			'date' => $media->post_date,
			'modified' => $media->post_modified,
			'author' => $media->post_author,
			'mime_type' => $media->post_mime_type,
			'url' => $attachment_url,
			'link' => get_attachment_link($media->ID),
			'sizes' => isset($attachment_metadata['sizes']) ? $attachment_metadata['sizes'] : [],
			'meta' => get_post_meta($media->ID),
		];
	}

	public function check_permission() {
		return current_user_can('upload_files');
	}
}

