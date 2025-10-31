<?php

class RESTBridge_Comments_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/comments', [
			'methods' => 'GET',
			'callback' => [$this, 'get_comments'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/comments', [
			'methods' => 'POST',
			'callback' => [$this, 'create_comment'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/comments/(?P<id>\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_comment'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/comments/(?P<id>\d+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_comment'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/comments/(?P<id>\d+)', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_comment'],
			'permission_callback' => [$this, 'check_permission'],
		]);
	}

	public function get_comments(WP_REST_Request $request) {
		$params = $request->get_query_params();
		
		$args = [
			'status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'approve',
			'number' => isset($params['per_page']) ? (int) $params['per_page'] : 10,
			'offset' => isset($params['page']) ? ((int) $params['page'] - 1) * (int) $params['per_page'] : 0,
			'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'comment_date',
			'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
		];

		if (isset($params['post'])) {
			$args['post_id'] = (int) $params['post'];
		}

		if (isset($params['parent'])) {
			$args['parent'] = (int) $params['parent'];
		}

		$comments = get_comments($args);
		$formatted_comments = array_map([$this, 'format_comment'], $comments);

		return rest_ensure_response([
			'comments' => $formatted_comments,
			'total' => wp_count_comments()->approved,
		]);
	}

	public function get_comment(WP_REST_Request $request) {
		$comment_id = (int) $request['id'];
		$comment = get_comment($comment_id);

		if (!$comment) {
			return new WP_Error('comment_not_found', 'Comment not found', ['status' => 404]);
		}

		return rest_ensure_response($this->format_comment($comment));
	}

	public function create_comment(WP_REST_Request $request) {
		$params = $request->get_json_params();

		$comment_data = [
			'comment_post_ID' => isset($params['post_id']) ? (int) $params['post_id'] : 0,
			'comment_author' => isset($params['author']) ? sanitize_text_field($params['author']) : '',
			'comment_author_email' => isset($params['author_email']) ? sanitize_email($params['author_email']) : '',
			'comment_content' => isset($params['content']) ? sanitize_textarea_field($params['content']) : '',
			'comment_parent' => isset($params['parent']) ? (int) $params['parent'] : 0,
			'comment_approved' => isset($params['approved']) ? (int) $params['approved'] : 0,
		];

		$comment_id = wp_insert_comment($comment_data);

		if (!$comment_id) {
			return new WP_Error('comment_failed', 'Failed to create comment', ['status' => 500]);
		}

		return rest_ensure_response($this->format_comment(get_comment($comment_id)), 201);
	}

	public function update_comment(WP_REST_Request $request) {
		$comment_id = (int) $request['id'];
		$params = $request->get_json_params();

		$comment = get_comment($comment_id);
		if (!$comment) {
			return new WP_Error('comment_not_found', 'Comment not found', ['status' => 404]);
		}

		$comment_data = ['comment_ID' => $comment_id];
		
		if (isset($params['content'])) {
			$comment_data['comment_content'] = sanitize_textarea_field($params['content']);
		}
		if (isset($params['approved'])) {
			$comment_data['comment_approved'] = (int) $params['approved'];
		}

		$updated = wp_update_comment($comment_data);

		if (!$updated) {
			return new WP_Error('update_failed', 'Failed to update comment', ['status' => 500]);
		}

		return rest_ensure_response($this->format_comment(get_comment($comment_id)));
	}

	public function delete_comment(WP_REST_Request $request) {
		$comment_id = (int) $request['id'];
		$force = isset($request['force']) && $request['force'];

		$result = wp_delete_comment($comment_id, $force);

		if (!$result) {
			return new WP_Error('delete_failed', 'Failed to delete comment', ['status' => 500]);
		}

		return rest_ensure_response(['deleted' => true, 'id' => $comment_id]);
	}

	private function format_comment($comment) {
		return [
			'id' => $comment->comment_ID,
			'post_id' => $comment->comment_post_ID,
			'author' => $comment->comment_author,
			'author_email' => $comment->comment_author_email,
			'author_url' => $comment->comment_author_url,
			'content' => $comment->comment_content,
			'date' => $comment->comment_date,
			'approved' => $comment->comment_approved,
			'parent' => $comment->comment_parent,
			'type' => $comment->comment_type,
		];
	}

	public function check_permission() {
		return current_user_can('moderate_comments');
	}
}

