<?php

class RESTBridge_Menus_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/menus', [
			'methods' => 'GET',
			'callback' => [$this, 'get_menus'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/menus/(?P<id>\\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_menu_by_id'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/menus/locations', [
			'methods' => 'GET',
			'callback' => [$this, 'get_menu_locations'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/menus/locations/(?P<location>[a-zA-Z0-9_-]+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_menu_by_location'],
			'permission_callback' => '__return_true',
		]);

		// All published pages as a nested menu-like tree
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/menus/pages', [
			'methods' => 'GET',
			'callback' => [$this, 'get_all_pages_menu'],
			'permission_callback' => '__return_true',
		]);
	}

	public function get_menus() {
		$menus = wp_get_nav_menus();
		$locations = get_nav_menu_locations();

		$result = array_map(function($menu) use ($locations) {
			return [
				'id' => (int) $menu->term_id,
				'name' => $menu->name,
				'slug' => $menu->slug,
				'description' => $menu->description,
				'count' => (int) $menu->count,
				'locations' => $this->get_locations_for_menu($menu->term_id, $locations),
			];
		}, $menus);

		return rest_ensure_response([
			'menus' => $result,
			'locations' => $this->format_locations($locations),
		]);
	}

	public function get_menu_by_id(WP_REST_Request $request) {
		$menu_id = (int) $request['id'];
		$items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache' => false]);
		if (!$items) {
			return rest_ensure_response(['items' => [], 'id' => $menu_id]);
		}
		return rest_ensure_response([
			'id' => $menu_id,
			'items' => $this->build_menu_tree($items),
		]);
	}

	public function get_menu_locations() {
		$locations = get_nav_menu_locations();
		return rest_ensure_response($this->format_locations($locations));
	}

	public function get_menu_by_location(WP_REST_Request $request) {
		$location = sanitize_key($request['location']);
		$locations = get_nav_menu_locations();
		if (!isset($locations[$location])) {
			return new WP_Error('menu_location_not_found', 'Menu location not found', ['status' => 404]);
		}
		$menu_id = (int) $locations[$location];
		$items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache' => false]);
		return rest_ensure_response([
			'location' => $location,
			'id' => $menu_id,
			'items' => $this->build_menu_tree($items ?: []),
		]);
	}

	private function get_locations_for_menu($menu_id, $locations) {
		$names = [];
		foreach ($locations as $location => $id) {
			if ((int) $id === (int) $menu_id) {
				$names[] = $location;
			}
		}
		return $names;
	}

	private function format_locations($locations) {
		$formatted = [];
		foreach ($locations as $location => $menu_id) {
			$menu = wp_get_nav_menu_object($menu_id);
			$formatted[] = [
				'location' => $location,
				'menu_id' => (int) $menu_id,
				'menu_name' => $menu ? $menu->name : null,
			];
		}
		return $formatted;
	}

	private function build_menu_tree($items) {
		$by_id = [];
		$tree = [];

		foreach ($items as $item) {
			$by_id[$item->ID] = $this->format_menu_item($item);
		}

		foreach ($by_id as $id => &$node) {
			$parent_id = $node['parent'];
			if ($parent_id && isset($by_id[$parent_id])) {
				$by_id[$parent_id]['children'][] = &$node;
			} else {
				$tree[] = &$node;
			}
		}
		unset($node);

		return $tree;
	}

	public function get_all_pages_menu() {
		$pages = get_pages([
			'post_status' => 'publish',
			'sort_column' => 'menu_order,post_title',
			'sort_order' => 'ASC',
		]);

		$by_id = [];
		$tree = [];

		foreach ($pages as $page) {
			$by_id[$page->ID] = [
				'id' => (int) $page->ID,
				'parent' => (int) $page->post_parent,
				'title' => get_the_title($page->ID),
				'url' => get_permalink($page->ID),
				'menu_order' => (int) $page->menu_order,
				'slug' => $page->post_name,
				'children' => [],
			];
		}

		foreach ($by_id as $id => &$node) {
			$parent_id = $node['parent'];
			if ($parent_id && isset($by_id[$parent_id])) {
				$by_id[$parent_id]['children'][] = &$node;
			} else {
				$tree[] = &$node;
			}
		}
		unset($node);

		// Sort children by menu_order then title
		$sortFn = function(&$nodes) use (&$sortFn) {
			usort($nodes, function($a, $b) {
				if ($a['menu_order'] === $b['menu_order']) {
					return strcasecmp($a['title'], $b['title']);
				}
				return $a['menu_order'] <=> $b['menu_order'];
			});
			foreach ($nodes as &$n) {
				if (!empty($n['children'])) {
					$sortFn($n['children']);
				}
			}
		};
		$sortFn($tree);

		return rest_ensure_response([
			'items' => $tree,
			'flat' => array_values($by_id),
		]);
	}

	private function format_menu_item($item) {
		return [
			'id' => (int) $item->ID,
			'parent' => (int) $item->menu_item_parent,
			'object_id' => (int) $item->object_id,
			'object' => $item->object,
			'title' => $item->title,
			'url' => $this->normalize_url($item->url),
			'target' => $item->target,
			'attr_title' => $item->attr_title,
			'description' => $item->description,
			'classes' => is_array($item->classes) ? array_values(array_filter($item->classes)) : [],
			'xfn' => $item->xfn,
			'menu_order' => (int) $item->menu_order,
			'type' => $item->type,
			'type_label' => $item->type_label,
			'children' => [],
		];
	}

	private function normalize_url($url) {
		if (!$url) {
			return $url;
		}
		return esc_url_raw(html_entity_decode($url));
	}
}


