<?php

class RESTBridge_Posts_API {

    public function register_routes() {
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/posts', [
            'methods' => 'GET',
            'callback' => [$this, 'get_posts'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/posts', [
            'methods' => 'POST',
            'callback' => [$this, 'create_post'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/posts/(?P<identifier>[a-zA-Z0-9-_]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_post'],
            'permission_callback' => '__return_true',
        ]);

        // New endpoint for detailed single post/blog page
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/posts/(?P<identifier>[a-zA-Z0-9-_]+)/details', [
            'methods' => 'GET',
            'callback' => [$this, 'get_post_details'],
            'permission_callback' => '__return_true',
            'args' => [
                'include_comments' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Include comments data',
                ],
                'include_related' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Include related posts',
                ],
                'include_author' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Include author details',
                ],
                'related_count' => [
                    'type' => 'integer',
                    'default' => 3,
                    'description' => 'Number of related posts',
                ],
                'popular_tags_count' => [
                    'type' => 'integer',
                    'default' => 10,
                    'description' => 'Number of popular tags to include',
                ],
                'popular_posts_count' => [
                    'type' => 'integer',
                    'default' => 5,
                    'description' => 'Number of popular posts to include',
                ],
            ],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/posts/(?P<id>\\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_post'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/posts/(?P<id>\\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_post'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function get_posts(WP_REST_Request $request) {
        $params = $request->get_query_params();

        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => (int) ($params['per_page'] ?? 10),
            'paged'          => (int) ($params['page'] ?? 1),
            'orderby'        => $params['orderby'] ?? 'date',
            'order'          => $params['order'] ?? 'DESC',
            'tax_query'      => [],
        ];

        if (!empty($params['search'])) {
            $args['s'] = sanitize_text_field($params['search']);
        }

        if (!empty($params['tag'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'post_tag',
                'field'    => 'slug',
                'terms'    => sanitize_title($params['tag']),
            ];
        }

        if (!empty($params['category'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'category',
                'field'    => 'slug',
                'terms'    => sanitize_title($params['category']),
            ];
        }

        if (!empty($args['tax_query'])) {
            $args['tax_query']['relation'] = 'AND';
        } else {
            unset($args['tax_query']);
        }

        $query = new WP_Query($args);
        $posts = [];

        while ($query->have_posts()) {
            $query->the_post();
            $posts[] = $this->format_post(get_post());
        }
        wp_reset_postdata();

        return rest_ensure_response([
            'posts' => $posts,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
        ]);
    }

    public function get_post( WP_REST_Request $request ) {
        $identifier = $request['identifier'];

        if ( is_numeric( $identifier ) ) {
            $post = get_post( (int) $identifier );
        } else {
            $post = get_page_by_path( sanitize_title( $identifier ), OBJECT, 'post' );
        }

        if ( ! $post || $post->post_type !== 'post' ) {
            return new WP_Error(
                'post_not_found',
                'Post not found',
                [ 'status' => 404 ]
            );
        }

        return rest_ensure_response( $this->format_post( $post ) );
    }


    /**
     * Get detailed post information with comments, related posts, and author info
     */
    public function get_post_details( WP_REST_Request $request ) {

        $identifier = $request['identifier'];

        // Resolve post by ID or slug
        if ( is_numeric( $identifier ) ) {
            $post = get_post( (int) $identifier );
        } else {
            $post = get_page_by_path(
                sanitize_title( $identifier ),
                OBJECT,
                'post'
            );
        }

        if ( ! $post || $post->post_type !== 'post' ) {
            return new WP_Error(
                'post_not_found',
                'Post not found',
                [ 'status' => 404 ]
            );
        }

        $post_id = $post->ID;

        // Query params (REST already casts booleans, but this is safe)
        $params = $request->get_query_params();

        $include_comments = filter_var( $params['include_comments'] ?? true, FILTER_VALIDATE_BOOLEAN );
        $include_related  = filter_var( $params['include_related'] ?? true, FILTER_VALIDATE_BOOLEAN );
        $include_author   = filter_var( $params['include_author'] ?? true, FILTER_VALIDATE_BOOLEAN );

        $related_count        = (int) ( $params['related_count'] ?? 3 );
        $popular_tags_count   = (int) ( $params['popular_tags_count'] ?? 10 );
        $popular_posts_count  = (int) ( $params['popular_posts_count'] ?? 5 );

        // Base response
        $response = [
            'post' => $this->format_post( $post ),
            'metadata' => [
                'reading_time' => $this->calculate_reading_time( $post->post_content ),
                'word_count'   => str_word_count( wp_strip_all_tags( $post->post_content ) ),
                'comment_count'=> (int) $post->comment_count,
            ],
        ];

        if ( $include_author ) {
            $response['author'] = $this->get_author_details( $post->post_author );
        }

        if ( $include_comments ) {
            $response['comments'] = $this->get_post_comments( $post_id );
        }

        if ( $include_related ) {
            $response['related_posts'] = $this->get_related_posts( $post_id, $related_count );
        }

        $response['popular_tags']  = $this->get_popular_tags( $popular_tags_count );
        $response['popular_posts'] = $this->get_popular_posts( $popular_posts_count );

        $response['offer'] = $this->get_post_offer( $post_id );

        // Extension hook
        $response = apply_filters(
            'restbridge_post_details',
            $response,
            $post_id,
            $post
        );

        return rest_ensure_response( $response );
    }


    public function create_post(WP_REST_Request $request) {
        $params = $request->get_json_params();

        $post_data = [
            'post_title' => isset($params['title']) ? sanitize_text_field($params['title']) : '',
            'post_content' => isset($params['content']) ? wp_kses_post($params['content']) : '',
            'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'draft',
            'post_type' => 'post',
        ];

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        return rest_ensure_response($this->format_post(get_post($post_id)), 201);
    }

    public function update_post(WP_REST_Request $request) {
        $post_id = (int) $request['id'];
        $params = $request->get_json_params();

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
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

        return rest_ensure_response($this->format_post(get_post($post_id)));
    }

    public function delete_post(WP_REST_Request $request) {
        $post_id = (int) $request['id'];
        $force = isset($request['force']) && $request['force'];

        $result = wp_delete_post($post_id, $force);

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete post', ['status' => 500]);
        }

        return rest_ensure_response(['deleted' => true, 'id' => $post_id]);
    }

    private function format_post($post) {
        return [
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
            'categories' => $this->format_terms($post->ID, 'category'),
            'tags'       => $this->format_terms($post->ID, 'post_tag'),
            'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
            'meta' => get_post_meta($post->ID),
        ];
    }

    private function format_terms(int $post_id, string $taxonomy): array {
        $terms = get_the_terms($post_id, $taxonomy);

        if (empty($terms) || is_wp_error($terms)) {
            return [];
        }

        return array_map(function ($term) {
            return [
                'id'   => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }, $terms);
    }

    /**
     * Get author details
     */
    private function get_author_details($author_id) {
        $author = get_user_by('id', $author_id);
        
        if (!$author) {
            return null;
        }

        return [
            'id' => $author->ID,
            'name' => $author->display_name,
            'email' => $author->user_email,
            'url' => $author->user_url,
            'description' => get_the_author_meta('description', $author->ID),
            'avatar' => get_avatar_url($author->ID),
            'posts_count' => count_user_posts($author->ID, 'post'),
        ];
    }

    /**
     * Get post comments
     */
   private function get_post_comments($post_id) {

        $args = [
            'post_id' => (int) $post_id,
            'status'  => 'approve',
            'parent'  => 0, // 🔥 only main comments
            'orderby' => 'comment_date',
            'order'   => 'DESC',
        ];

        $comments = get_comments($args);

        $formatted = [];

        foreach ($comments as $comment) {
            $data = $this->format_comment($comment);
            $data['children'] = $this->get_child_comments($comment->comment_ID);
            $formatted[] = $data;
        }

        return $formatted;
    }

    private function get_child_comments($parent_id) {

        $children = get_comments([
            'parent'  => (int) $parent_id,
            'status'  => 'approve',
            'orderby' => 'comment_date',
            'order'   => 'ASC',
        ]);

        $formatted = [];

        foreach ($children as $child) {
            $data = $this->format_comment($child);
            $data['children'] = $this->get_child_comments($child->comment_ID);
            $formatted[] = $data;
        }

        return $formatted;
    }

    /**
     * Format comment data
     */
    private function format_comment($comment) {

        // Get comment content as plain text (no HTML)
        $content = wp_strip_all_tags( get_comment_text( $comment ) );

        $avatar_url = get_avatar_url(
            $comment->comment_author_email,
            ['size' => 96]
        );

        return [
            'id'           => (int) $comment->comment_ID,
            'post_id'      => (int) $comment->comment_post_ID,
            'author'       => $comment->comment_author,
            'author_email' => $comment->comment_author_email,
            'author_url'   => $comment->comment_author_url,
            'avatar'       => $avatar_url,
            'content'      => trim($content),
            'date'         => $comment->comment_date,
            'approved'     => (int) $comment->comment_approved,
            'parent'       => (int) $comment->comment_parent,
            'type'         => $comment->comment_type,
        ];
    }

    /**
     * Get related posts based on categories and tags
     */
    private function get_related_posts($post_id, $count = 3) {
        $post = get_post($post_id);
        $categories = wp_get_post_categories($post_id);
        $tags = wp_get_post_tags($post_id, ['fields' => 'ids']);

        $args = [
            'post__not_in' => [$post_id],
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $count,
            'orderby' => 'date',
            'order' => 'DESC',
            'tax_query' => [
                'relation' => 'OR',
            ],
        ];

        // Add category filter if post has categories
        if (!empty($categories)) {
            $args['tax_query'][] = [
                'taxonomy' => 'category',
                'terms' => $categories,
                'field' => 'term_id',
            ];
        }

        // Add tag filter if post has tags
        if (!empty($tags)) {
            $args['tax_query'][] = [
                'taxonomy' => 'post_tag',
                'terms' => $tags,
                'field' => 'term_id',
            ];
        }

        $query = new WP_Query($args);
        $related = [];

        while ($query->have_posts()) {
            $query->the_post();
            $post_data = get_post();
            $related[] = [
                'id' => $post_data->ID,
                'title' => get_the_title($post_data->ID),
                'excerpt' => get_the_excerpt($post_data->ID),
                'permalink' => get_permalink($post_data->ID),
                'featured_image' => get_the_post_thumbnail_url($post_data->ID, 'thumbnail'),
                'date' => $post_data->post_date,
            ];
        }
        wp_reset_postdata();

        return $related;
    }

    /**
     * Get popular tags
     */
    private function get_popular_tags($count = 10) {
        $terms = get_terms([
            'taxonomy' => 'post_tag',
            'orderby' => 'count',
            'order' => 'DESC',
            'number' => (int) $count,
            'hide_empty' => true,
        ]);

        $popular = [];
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                $popular[] = [
                    'id' => (int) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'count' => (int) $term->count,
                    'link' => get_term_link($term),
                ];
            }
        }

        return $popular;
    }

    /**
     * Get popular posts (by comment count)
     */
    private function get_popular_posts($count = 5) {
            $args = [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => (int) $count,
                'orderby'        => 'comment_count',
                'order'          => 'DESC',
            ];

            $query = new WP_Query($args);
            $popular = [];

            while ($query->have_posts()) {
                $query->the_post();
                $p = get_post();

                $popular[] = [
                    'id'             => $p->ID,
                    'title'          => get_the_title($p->ID),
                    'slug'           => $p->post_name, // ✅ slug added
                    'excerpt'        => get_the_excerpt($p->ID),
                    'permalink'      => get_permalink($p->ID),
                    'featured_image' => get_the_post_thumbnail_url($p->ID, 'thumbnail'),
                    'comment_count'  => (int) $p->comment_count,
                    'date'           => $p->post_date,
                ];
            }

            wp_reset_postdata();

            return $popular;
    }

    /**
     * Calculate reading time in minutes
     */
    private function calculate_reading_time($content) {
        $word_count = str_word_count(strip_tags($content));
        $reading_time = ceil($word_count / 200); // Average 200 words per minute
        return $reading_time < 1 ? 1 : $reading_time;
    }

    /**
     * Get offer data for a post from post meta.
     * Expected meta keys (optional):
     * - offer_enabled (truthy to show)
     * - offer_title
     * - offer_subtitle
     * - offer_discount
     * - offer_image_id (attachment ID)
     * - offer_link
     * Returns null when no offer is configured.
     */
    private function get_post_offer($post_id) {
        // Get the first offer term
        $terms = get_terms([
            'taxonomy' => 'offer',
            'hide_empty' => false,
            'number' => 1
        ]);
        if (empty($terms) || is_wp_error($terms)) {
            return null;
        }
        $offer = $terms[0];
        $img      = get_term_meta($offer->term_id, 'offer_image', true);
        $heading  = get_term_meta($offer->term_id, 'offer_heading', true);
        $bigtext  = get_term_meta($offer->term_id, 'offer_big_text', true);
        $btn_text = get_term_meta($offer->term_id, 'offer_btn_text', true);
        $btn_url  = get_term_meta($offer->term_id, 'offer_btn_url', true);

        return [
            'image' => $img ?: null,
            'heading' => $heading ?: null,
            'big_text' => $bigtext ?: null,
            'button_text' => $btn_text ?: null,
            'button_url' => $btn_url ?: null,
            'term_id' => $offer->term_id,
            'term_name' => $offer->name,
        ];
    }

    public function check_permission() {
        return current_user_can('edit_posts');
    }
}

