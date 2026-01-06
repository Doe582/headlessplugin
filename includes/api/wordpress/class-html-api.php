<?php

class RESTBridge_HTML_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/html', [
			'methods'  => WP_REST_Server::READABLE,
			'callback' => [$this, 'get_html_snippet'],
			'permission_callback' => '__return_true',
			'args' => [
				'page_id' => [
					'type' => 'integer',
					'sanitize_callback' => 'absint',
					'description' => __('Page ID to pull content from (optional).', 'headlessplugin'),
				],
				'slug' => [
					'type' => 'string',
					'sanitize_callback' => 'sanitize_title',
					'description' => __('Page slug to pull content from (optional).', 'headlessplugin'),
				],
				'title' => [
					'type' => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description' => __('Heading text (fallback when page_id not supplied).', 'headlessplugin'),
				],
				'body' => [
					'type' => 'string',
					'description' => __('Paragraph text (fallback when page_id not supplied).', 'headlessplugin'),
				],
			],
		]);
	}

	public function get_html_snippet(WP_REST_Request $request) {

		$page_id = $request->get_param('page_id');
		$slug    = $request->get_param('slug');

		$post = null;

		// 1️⃣ Priority: page_id
		if ($page_id) {
			$post = get_post($page_id);
		}

		// 2️⃣ Fallback: slug
		if (!$post && $slug) {
			$post = get_page_by_path($slug, OBJECT, 'page');
		}

		if ($post) {
			if ($post->post_status !== 'publish') {
				return new WP_Error(
					'html_page_not_found',
					__('Page not found or not published.', 'headlessplugin'),
					['status' => 404]
				);
			}

			$title = wp_strip_all_tags(get_the_title($post));
			if ($title === '') {
				$title = __("Untitled Page", 'headlessplugin');
			}

			$content = apply_filters('the_content', $post->post_content);
			$content = $this->normalize_block_level_markup($content);

			return rest_ensure_response([
				'page_id' => $post->ID,
				'slug'    => $post->post_name,
				'title'   => $title,
				'body'    => wp_strip_all_tags($content),
				'html'    => $content,
			]);
		}
			return new WP_Error(
			'html_not_found',
			__('No data found.', 'headlessplugin'),
			['status' => 404]
		);
	}


	private function normalize_block_level_markup($content) {
		if (!is_string($content) || $content === '') {
			return $content;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();

		$xpath = new DOMXPath($dom);
		$paragraphs = $xpath->query('//p');

		foreach ($paragraphs as $paragraph) {
			// Skip paragraphs that contain block-level elements (like headings, divs, lists)
			$contains_block = false;
			foreach ($paragraph->childNodes as $child) {
				if ($child instanceof DOMElement && $this->is_block_level_element($child->tagName)) {
					$contains_block = true;
					break;
				}
			}

			if ($contains_block) {
				$this->unwrap_element($paragraph);
			}
		}

		$html = $dom->saveHTML();
		$html = preg_replace('/^<\?xml.*?\?>/i', '', $html);
		return trim($html);
	}

	private function unwrap_element(DOMElement $element) {
		$parent = $element->parentNode;
		while ($element->firstChild) {
			$parent->insertBefore($element->firstChild, $element);
		}
		$parent->removeChild($element);
	}

	private function is_block_level_element($tag) {
		$block_tags = [
			'h1','h2','h3','h4','h5','h6',
			'div','section','article','aside','nav',
			'ul','ol','li',
			'blockquote','pre','table','figure',
		];

		return in_array(strtolower($tag), $block_tags, true);
	}
}

