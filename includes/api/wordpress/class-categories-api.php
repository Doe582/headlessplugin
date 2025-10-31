<?php

class RESTBridge_Categories_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/categories', [
			'methods' => 'GET',
			'callback' => [$this, 'get_categories'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/categories', [
			'methods' => 'POST',
			'callback' => [$this, 'create_category'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/categories/(?P<id>\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_category'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/categories/(?P<id>\d+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_category'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/categories/(?P<id>\d+)', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_category'],
			'permission_callback' => [$this, 'check_permission'],
		]);
	}

	public function get_categories(WP_REST_Request $request) {
		$params = $request->get_query_params();
		
		$args = [
			'hide_empty' => isset($params['hide_empty']) ? (bool) $params['hide_empty'] : false,
			'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'name',
			'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'ASC',
		];

		if (isset($params['parent'])) {
			$args['parent'] = (int) $params['parent'];
		}

		if (isset($params['search'])) {
			$args['search'] = sanitize_text_field($params['search']);
		}

		$categories = get_categories($args);
		$formatted_categories = array_map([$this, 'format_category'], $categories);

		return rest_ensure_response([
			'categories' => $formatted_categories,
			'total' => count($formatted_categories),
		]);
	}

	public function get_category(WP_REST_Request $request) {
		$category_id = (int) $request['id'];
		$category = get_category($category_id);

		if (!$category || is_wp_error($category)) {
			return new WP_Error('category_not_found', 'Category not found', ['status' => 404]);
		}

		return rest_ensure_response($this->format_category($category));
	}

	public function create_category(WP_REST_Request $request) {
		$params = $request->get_json_params();

		$category_data = [
			'cat_name' => isset($params['name']) ? sanitize_text_field($params['name']) : '',
			'category_description' => isset($params['description']) ? sanitize_textarea_field($params['description']) : '',
			'category_parent' => isset($params['parent']) ? (int) $params['parent'] : 0,
			'category_nicename' => isset($params['slug']) ? sanitize_title($params['slug']) : '',
		];

		$category_id = wp_create_category($category_data['cat_name'], $category_data['category_parent']);

		if (!$category_id || is_wp_error($category_id)) {
			return new WP_Error('category_failed', 'Failed to create category', ['status' => 500]);
		}

		if (!empty($category_data['category_description'])) {
			wp_update_term($category_id, 'category', ['description' => $category_data['category_description']]);
		}

		return rest_ensure_response($this->format_category(get_category($category_id)), 201);
	}

	public function update_category(WP_REST_Request $request) {
		$category_id = (int) $request['id'];
		$params = $request->get_json_params();

		$category = get_category($category_id);
		if (!$category || is_wp_error($category)) {
			return new WP_Error('category_not_found', 'Category not found', ['status' => 404]);
		}

		$update_data = [];
		
		if (isset($params['name'])) {
			$update_data['name'] = sanitize_text_field($params['name']);
		}
		if (isset($params['description'])) {
			$update_data['description'] = sanitize_textarea_field($params['description']);
		}
		if (isset($params['parent'])) {
			$update_data['parent'] = (int) $params['parent'];
		}
		if (isset($params['slug'])) {
			$update_data['slug'] = sanitize_title($params['slug']);
		}

		$updated = wp_update_term($category_id, 'category', $update_data);

		if (is_wp_error($updated)) {
			return $updated;
		}

		return rest_ensure_response($this->format_category(get_category($category_id)));
	}

	public function delete_category(WP_REST_Request $request) {
		$category_id = (int) $request['id'];
		$force = isset($request['force']) && $request['force'];

		$result = wp_delete_category($category_id);

		if (!$result) {
			return new WP_Error('delete_failed', 'Failed to delete category', ['status' => 500]);
		}

		return rest_ensure_response(['deleted' => true, 'id' => $category_id]);
	}

	private function format_category($category) {
		return [
			'id' => $category->term_id,
			'name' => $category->name,
			'slug' => $category->slug,
			'description' => $category->description,
			'parent' => $category->parent,
			'count' => $category->count,
			'link' => get_category_link($category->term_id),
		];
	}

	public function check_permission() {
		return current_user_can('manage_categories');
	}
}

