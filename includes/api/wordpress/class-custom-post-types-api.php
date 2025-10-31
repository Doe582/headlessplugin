<?php

class RESTBridge_Custom_Post_Types_API {

	public function register_routes() {
		// Dynamic route for all custom post types
		// This will handle any registered post type dynamically
		$this->register_dynamic_routes();
	}

	public function register_dynamic_routes() {
		$post_types = get_post_types(['public' => true, '_builtin' => false], 'names');

		foreach ($post_types as $post_type) {
			$post_type_obj = get_post_type_object($post_type);
			
			// Only register if post type supports REST API
			if (!$post_type_obj || !$post_type_obj->show_in_rest) {
				continue;
			}

			$base = !empty($post_type_obj->rest_base) ? $post_type_obj->rest_base : $post_type;

			// List all posts of this type
			register_rest_route(RESTBRIDGE_API_NAMESPACE, '/' . $base, [
				'methods' => 'GET',
				'callback' => function($request) use ($post_type) {
					return $this->get_posts($request, $post_type);
				},
				'permission_callback' => '__return_true',
			]);

			// Create new post
			register_rest_route(RESTBRIDGE_API_NAMESPACE, '/' . $base, [
				'methods' => 'POST',
				'callback' => function($request) use ($post_type) {
					return $this->create_post($request, $post_type);
				},
				'permission_callback' => [$this, 'check_permission'],
			]);

			// Get single post
			register_rest_route(RESTBRIDGE_API_NAMESPACE, '/' . $base . '/(?P<id>\d+)', [
				'methods' => 'GET',
				'callback' => function($request) use ($post_type) {
					return $this->get_post($request, $post_type);
				},
				'permission_callback' => '__return_true',
			]);

			// Update post
			register_rest_route(RESTBRIDGE_API_NAMESPACE, '/' . $base . '/(?P<id>\d+)', [
				'methods' => 'PUT',
				'callback' => function($request) use ($post_type) {
					return $this->update_post($request, $post_type);
				},
				'permission_callback' => [$this, 'check_permission'],
			]);

			// Delete post
			register_rest_route(RESTBRIDGE_API_NAMESPACE, '/' . $base . '/(?P<id>\d+)', [
				'methods' => 'DELETE',
				'callback' => function($request) use ($post_type) {
					return $this->delete_post($request, $post_type);
				},
				'permission_callback' => [$this, 'check_permission'],
			]);
		}
	}

	public function get_posts(WP_REST_Request $request, $post_type = null) {
		if (!$post_type) {
			return new WP_Error('missing_post_type', 'Post type is required', ['status' => 400]);
		}
		$params = $request->get_query_params();
		
		if (!post_type_exists($post_type)) {
			return new WP_Error('invalid_post_type', 'Invalid post type', ['status' => 400]);
		}

		$args = [
			'post_type' => $post_type,
			'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'publish',
			'posts_per_page' => isset($params['per_page']) ? (int) $params['per_page'] : 10,
			'paged' => isset($params['page']) ? (int) $params['page'] : 1,
			'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'date',
			'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
		];

		if (isset($params['search'])) {
			$args['s'] = sanitize_text_field($params['search']);
		}

		$query = new WP_Query($args);
		$posts = [];

		while ($query->have_posts()) {
			$query->the_post();
			$posts[] = $this->format_post(get_post(), $post_type);
		}
		wp_reset_postdata();

		return rest_ensure_response([
			'posts' => $posts,
			'total' => $query->found_posts,
			'pages' => $query->max_num_pages,
			'post_type' => $post_type,
		]);
	}

	public function get_post(WP_REST_Request $request, $post_type = null) {
		if (!$post_type) {
			return new WP_Error('missing_post_type', 'Post type is required', ['status' => 400]);
		}
		$post_id = (int) $request['id'];
		$post = get_post($post_id);

		if (!$post || $post->post_type !== $post_type) {
			return new WP_Error('post_not_found', 'Post not found', ['status' => 404]);
		}

		return rest_ensure_response($this->format_post($post, $post_type));
	}

	public function create_post(WP_REST_Request $request, $post_type = null) {
		if (!$post_type) {
			return new WP_Error('missing_post_type', 'Post type is required', ['status' => 400]);
		}
		$params = $request->get_json_params();

		if (!post_type_exists($post_type)) {
			return new WP_Error('invalid_post_type', 'Invalid post type', ['status' => 400]);
		}

		$post_data = [
			'post_title' => isset($params['title']) ? sanitize_text_field($params['title']) : '',
			'post_content' => isset($params['content']) ? wp_kses_post($params['content']) : '',
			'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'draft',
			'post_type' => $post_type,
		];

		$post_id = wp_insert_post($post_data);

		if (is_wp_error($post_id)) {
			return $post_id;
		}

		return rest_ensure_response($this->format_post(get_post($post_id), $post_type), 201);
	}

	public function update_post(WP_REST_Request $request, $post_type = null) {
		if (!$post_type) {
			return new WP_Error('missing_post_type', 'Post type is required', ['status' => 400]);
		}
		$post_id = (int) $request['id'];
		$params = $request->get_json_params();

		$post = get_post($post_id);
		if (!$post || $post->post_type !== $post_type) {
			return new WP_Error('post_not_found', 'Post not found', ['status' => 404]);
		}

		$post_data = ['ID' => $post_id];
		
		if (isset($params['title'])) {
			$post_data['post_title'] = sanitize_text_field($params['title']);
		}
		if (isset($params['content'])) {
			$post_data['post_content'] = wp_kses_post($params['content']);
		}
		if (isset($params['status'])) {
			$post_data['post_status'] = sanitize_text_field($params['status']);
		}

		$updated = wp_update_post($post_data);

		if (is_wp_error($updated)) {
			return $updated;
		}

		return rest_ensure_response($this->format_post(get_post($post_id), $post_type));
	}

	public function delete_post(WP_REST_Request $request, $post_type = null) {
		$post_id = (int) $request['id'];
		$force = isset($request['force']) && $request['force'];

		$result = wp_delete_post($post_id, $force);

		if (!$result) {
			return new WP_Error('delete_failed', 'Failed to delete post', ['status' => 500]);
		}

		return rest_ensure_response(['deleted' => true, 'id' => $post_id]);
	}

	private function format_post($post, $post_type) {
		$formatted = [
			'id' => $post->ID,
			'title' => get_the_title($post->ID),
			'content' => apply_filters('the_content', $post->post_content),
			'excerpt' => get_the_excerpt($post->ID),
			'date' => $post->post_date,
			'modified' => $post->post_modified,
			'status' => $post->post_status,
			'author' => $post->post_author,
			'slug' => $post->post_name,
			'permalink' => get_permalink($post->ID),
			'post_type' => $post_type,
			'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
			'meta' => get_post_meta($post->ID),
		];

		// Get associated taxonomies
		$taxonomies = get_object_taxonomies($post_type);
		foreach ($taxonomies as $taxonomy) {
			$terms = wp_get_post_terms($post->ID, $taxonomy, ['fields' => 'all']);
			$formatted['taxonomies'][$taxonomy] = array_map(function($term) {
				return [
					'id' => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				];
			}, $terms);
		}

		return $formatted;
	}

	public function check_permission() {
		return current_user_can('edit_posts');
	}
}

