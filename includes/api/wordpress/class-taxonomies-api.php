<?php

class RESTBridge_Taxonomies_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxonomies', [
			'methods' => 'GET',
			'callback' => [$this, 'get_taxonomies'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxonomies/(?P<taxonomy>[a-zA-Z0-9_-]+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_taxonomy'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxonomies/(?P<taxonomy>[a-zA-Z0-9_-]+)/terms', [
			'methods' => 'GET',
			'callback' => [$this, 'get_terms'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxonomies/(?P<taxonomy>[a-zA-Z0-9_-]+)/terms', [
			'methods' => 'POST',
			'callback' => [$this, 'create_term'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxonomies/(?P<taxonomy>[a-zA-Z0-9_-]+)/terms/(?P<id>\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_term'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxonomies/(?P<taxonomy>[a-zA-Z0-9_-]+)/terms/(?P<id>\d+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_term'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxonomies/(?P<taxonomy>[a-zA-Z0-9_-]+)/terms/(?P<id>\d+)', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_term'],
			'permission_callback' => [$this, 'check_permission'],
		]);
	}

	public function get_taxonomies(WP_REST_Request $request) {
		$params = $request->get_query_params();
		$args = [];
		
		if (isset($params['post_type'])) {
			$args['object_type'] = [sanitize_text_field($params['post_type'])];
		}

		$taxonomies = get_taxonomies($args, 'objects');
		$formatted_taxonomies = [];

		foreach ($taxonomies as $taxonomy) {
			$formatted_taxonomies[] = [
				'name' => $taxonomy->name,
				'label' => $taxonomy->label,
				'hierarchical' => $taxonomy->hierarchical,
				'public' => $taxonomy->public,
				'show_in_rest' => $taxonomy->show_in_rest,
				'object_type' => $taxonomy->object_type,
			];
		}

		return rest_ensure_response([
			'taxonomies' => $formatted_taxonomies,
			'total' => count($formatted_taxonomies),
		]);
	}

	public function get_taxonomy(WP_REST_Request $request) {
		$taxonomy_name = sanitize_text_field($request['taxonomy']);
		$taxonomy = get_taxonomy($taxonomy_name);

		if (!$taxonomy) {
			return new WP_Error('taxonomy_not_found', 'Taxonomy not found', ['status' => 404]);
		}

		return rest_ensure_response([
			'name' => $taxonomy->name,
			'label' => $taxonomy->label,
			'hierarchical' => $taxonomy->hierarchical,
			'public' => $taxonomy->public,
			'show_in_rest' => $taxonomy->show_in_rest,
			'object_type' => $taxonomy->object_type,
		]);
	}

	public function get_terms(WP_REST_Request $request) {
		$taxonomy_name = sanitize_text_field($request['taxonomy']);
		
		if (!taxonomy_exists($taxonomy_name)) {
			return new WP_Error('taxonomy_not_found', 'Taxonomy not found', ['status' => 404]);
		}

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

		$terms = get_terms(['taxonomy' => $taxonomy_name, 'hide_empty' => $args['hide_empty']] + $args);
		
		if (is_wp_error($terms)) {
			return $terms;
		}

		$formatted_terms = array_map(function($term) use ($taxonomy_name) {
			return $this->format_term($term, $taxonomy_name);
		}, $terms);

		return rest_ensure_response([
			'terms' => $formatted_terms,
			'total' => count($formatted_terms),
		]);
	}

	public function get_term(WP_REST_Request $request) {
		$taxonomy_name = sanitize_text_field($request['taxonomy']);
		$term_id = (int) $request['id'];

		if (!taxonomy_exists($taxonomy_name)) {
			return new WP_Error('taxonomy_not_found', 'Taxonomy not found', ['status' => 404]);
		}

		$term = get_term($term_id, $taxonomy_name);

		if (!$term || is_wp_error($term)) {
			return new WP_Error('term_not_found', 'Term not found', ['status' => 404]);
		}

		return rest_ensure_response($this->format_term($term, $taxonomy_name));
	}

	public function create_term(WP_REST_Request $request) {
		$taxonomy_name = sanitize_text_field($request['taxonomy']);
		$params = $request->get_json_params();

		if (!taxonomy_exists($taxonomy_name)) {
			return new WP_Error('taxonomy_not_found', 'Taxonomy not found', ['status' => 404]);
		}

		$term_data = [
			'description' => isset($params['description']) ? sanitize_textarea_field($params['description']) : '',
			'slug' => isset($params['slug']) ? sanitize_title($params['slug']) : '',
			'parent' => isset($params['parent']) ? (int) $params['parent'] : 0,
		];

		$term_name = isset($params['name']) ? sanitize_text_field($params['name']) : '';

		$result = wp_insert_term($term_name, $taxonomy_name, $term_data);

		if (is_wp_error($result)) {
			return $result;
		}

		$term = get_term($result['term_id'], $taxonomy_name);
		return rest_ensure_response($this->format_term($term, $taxonomy_name), 201);
	}

	public function update_term(WP_REST_Request $request) {
		$taxonomy_name = sanitize_text_field($request['taxonomy']);
		$term_id = (int) $request['id'];
		$params = $request->get_json_params();

		if (!taxonomy_exists($taxonomy_name)) {
			return new WP_Error('taxonomy_not_found', 'Taxonomy not found', ['status' => 404]);
		}

		$term = get_term($term_id, $taxonomy_name);
		if (!$term || is_wp_error($term)) {
			return new WP_Error('term_not_found', 'Term not found', ['status' => 404]);
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

		$updated = wp_update_term($term_id, $taxonomy_name, $update_data);

		if (is_wp_error($updated)) {
			return $updated;
		}

		$term = get_term($term_id, $taxonomy_name);
		return rest_ensure_response($this->format_term($term, $taxonomy_name));
	}

	public function delete_term(WP_REST_Request $request) {
		$taxonomy_name = sanitize_text_field($request['taxonomy']);
		$term_id = (int) $request['id'];

		if (!taxonomy_exists($taxonomy_name)) {
			return new WP_Error('taxonomy_not_found', 'Taxonomy not found', ['status' => 404]);
		}

		$result = wp_delete_term($term_id, $taxonomy_name);

		if (is_wp_error($result)) {
			return $result;
		}

		if (!$result) {
			return new WP_Error('delete_failed', 'Failed to delete term', ['status' => 500]);
		}

		return rest_ensure_response(['deleted' => true, 'id' => $term_id]);
	}

	private function format_term($term, $taxonomy_name) {
		return [
			'id' => $term->term_id,
			'name' => $term->name,
			'slug' => $term->slug,
			'description' => $term->description,
			'parent' => $term->parent,
			'count' => $term->count,
			'taxonomy' => $taxonomy_name,
			'link' => get_term_link($term->term_id, $taxonomy_name),
		];
	}

	public function check_permission() {
		return current_user_can('manage_categories');
	}
}

