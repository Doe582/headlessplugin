<?php

use Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema;

/**
 * Extends WooCommerce Store API product detail response with additional fields
 * This adds all the fields needed for a complete product detail page
 */
class RESTBridge_Store_API_Product_Detail {

    private static $instance = null;

    public function __construct() {
        self::$instance = $this;
        // Use rest_post_dispatch as primary method - this definitely fires for all REST API requests
        // This is the most reliable hook that works for Store API
        add_filter('rest_post_dispatch', [$this, 'modify_store_api_response'], 999, 3);
        
        // Also try rest_prepare_product_object as backup (in case it fires for Store API)
        // Note: Store API might not use this hook, but it's worth trying
        add_filter('rest_prepare_product_object', [$this, 'extend_product_detail_response'], 999, 3);

        $this->register_store_api_extensions();

        add_filter('woocommerce_product_add_to_cart_url', [$this, 'filter_product_add_to_cart_url'], 50, 2);
    }

    public static function get_instance() {
        return self::$instance;
    }

    /**
     * Extend Store API product detail response with additional fields
     * This adds all the fields needed for a complete product detail page
     */
    public function extend_product_detail_response($response, $post, $request) {
        // Only extend Store API product responses
        if (!$this->is_store_api_request($request)) {
            return $response;
        }

        if (!function_exists('wc_get_product')) {
            return $response;
        }

        $product = wc_get_product($post);
        if (!$product) {
            return $response;
        }

        $data = $response->get_data();

        // Get product attributes (color, size, etc.)
        $data['attributes'] = $this->get_product_attributes($product);
        
        // Don't set variations here - let Store API set them first, then enhance them at the end

        // Get product specifications/attributes table
        $data['specifications'] = $this->get_product_specifications($product);

        // Get shipping details
        $data['shipping_details'] = $this->get_shipping_details($product);

        // Get return and refund information
        $data['return_refund'] = $this->get_return_refund_info($product);

        // Get reviews
        $data['reviews'] = $this->get_product_reviews($product->get_id());

        // Attribute swatch data
        $data['attribute_swatches'] = $this->get_attribute_swatches($product);

        // Custom product tabs payload
        $data['tabs'] = $this->build_product_tabs($product, $data);

        // Theme-driven feature banners/content
        $data['features'] = $this->get_product_features();
        $data['wishlist'] = $this->get_wishlist_payload($product->get_id());
        $data['delivery_return'] = $this->get_delivery_return_entry();

        // Get related products + heading/description from Customizer
        $data['related_products_meta'] = $this->get_related_products_meta();
        $data['related_products'] = $this->get_related_products($product->get_id());

        // Enhanced image data
        $image_id = $product->get_image_id();
        if ($image_id && isset($data['images'][0])) {
            $data['image'] = [
                'id' => $image_id,
                'url' => $data['images'][0]['src'] ?? wp_get_attachment_image_url($image_id, 'full'),
                'thumbnail' => wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail'),
                'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true) ?: $product->get_name(),
            ];
        }

        // Enhanced gallery data
        $gallery_ids = $product->get_gallery_image_ids();
        $gallery = [];
        foreach ($gallery_ids as $gallery_id) {
            $gallery[] = [
                'id' => $gallery_id,
                'url' => wp_get_attachment_image_url($gallery_id, 'full'),
                'thumbnail' => wp_get_attachment_image_url($gallery_id, 'woocommerce_thumbnail'),
                'alt' => get_post_meta($gallery_id, '_wp_attachment_image_alt', true) ?: $product->get_name(),
            ];
        }
        $data['gallery'] = $gallery;
        $data['all_images'] = array_merge(
            isset($data['image']) ? [$data['image']] : [],
            $gallery
        );

        // Enhanced rating data
        $average_rating = (float) $product->get_average_rating();
        $review_count = (int) $product->get_review_count();
        $data['rating'] = [
            'average' => $average_rating > 0 ? $average_rating : null,
            'count' => $review_count,
            'stars' => $average_rating > 0 ? round($average_rating, 1) : 0,
        ];

        // Enhanced stock information
        $data['stock'] = [
            'status' => $product->get_stock_status(),
            'quantity' => $product->get_stock_quantity(),
            'manage_stock' => $product->get_manage_stock(),
            'in_stock' => $product->is_in_stock(),
            'backorders_allowed' => $product->backorders_allowed(),
            'stock_status_text' => $this->get_stock_status_text($product->get_stock_status(), $product->is_in_stock()),
        ];

        // Tax information
        $data['tax'] = [
            'status' => $product->get_tax_status(),
            'class' => $product->get_tax_class(),
            'inclusive' => wc_prices_include_tax(),
        ];
        $data['tax_notice'] = $this->get_tax_notice_text();

        // Additional product data
        $data['weight'] = $product->get_weight();
        $data['dimensions'] = [
            'length' => $product->get_length(),
            'width' => $product->get_width(),
            'height' => $product->get_height(),
        ];
        $data['featured'] = $product->is_featured();
        $data['purchasable'] = $product->is_purchasable();
        $data['sold_individually'] = $product->is_sold_individually();
        $data['virtual'] = $product->is_virtual();
        $data['downloadable'] = $product->is_downloadable();
        $data['permalink'] = $product->get_permalink();
        $data['add_to_cart_url'] = $product->add_to_cart_url();
        $data['buy_now_button'] = $this->get_buy_now_button_data($product);

        // Enhanced categories and tags
        $categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'all']);
        $tags = wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'all']);

        $formatted_categories = [];
        foreach ($categories as $category) {
            $formatted_categories[] = [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'permalink' => get_term_link($category),
            ];
        }
        $data['categories'] = $formatted_categories;

        $formatted_tags = [];
        foreach ($tags as $tag) {
            $formatted_tags[] = [
                'id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ];
        }
        $data['tags'] = $formatted_tags;

        $data['layout'] = $this->build_product_layout($product, $data);

        $primary_add_to_cart_url = $this->get_popup_add_to_cart_url($product);
        $data['add_to_cart_url'] = $primary_add_to_cart_url;
        $data['add_to_cart'] = $this->normalize_add_to_cart_payload($data['add_to_cart'] ?? [], $primary_add_to_cart_url, $product);

        if (isset($data['prices'])) {
            $data['prices'] = $this->normalize_store_api_prices($data['prices']);
        }

        // Final step: Enhance existing variations with prices
        // Loop through existing variations and add price to each one
        if ($product->is_type('variable') && isset($data['variations']) && is_array($data['variations'])) {
            foreach ($data['variations'] as $key => $variation) {
                if (!isset($variation['id'])) {
                    continue;
                }
                
                $variation_id = (int) $variation['id'];
                $variation_obj = wc_get_product($variation_id);
                
                if (!$variation_obj || !$variation_obj->exists()) {
                    continue;
                }

                // Get price information directly from variation meta
                $regular_price = get_post_meta($variation_id, '_regular_price', true);
                $sale_price = get_post_meta($variation_id, '_sale_price', true);
                $price_meta = get_post_meta($variation_id, '_price', true);
                
                // Fallback to variation object methods
                if (empty($price_meta)) {
                    $price_meta = $variation_obj->get_price();
                }
                if (empty($regular_price)) {
                    $regular_price = $variation_obj->get_regular_price();
                }
                if (empty($sale_price)) {
                    $sale_price = $variation_obj->get_sale_price();
                }
                
                // Determine the actual price
                $price = !empty($sale_price) && $variation_obj->is_on_sale() ? $sale_price : $regular_price;
                if (empty($price)) {
                    $price = $price_meta;
                }
                
                // Generate price HTML
                $price_html = '';
                if (!empty($price)) {
                    if (!empty($sale_price) && $variation_obj->is_on_sale()) {
                        $price_html = wc_format_sale_price($regular_price, $sale_price);
                    } else {
                        $price_html = wc_price($price);
                    }
                }
                
                // Convert to proper types
                $regular_price = $regular_price !== '' ? (float) $regular_price : null;
                $sale_price = $sale_price !== '' ? (float) $sale_price : null;
                $price = $price !== '' ? (float) $price : null;
                
                // Add price to existing variation (preserve all other data)
                $data['variations'][$key]['price'] = [
                    'raw' => $price !== null ? (string) $price : null,
                    'formatted' => $price_html ?: '',
                    'regular' => $regular_price,
                    'sale' => $sale_price,
                    'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
                    'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '',
                    'on_sale' => $variation_obj->is_on_sale(),
                ];
                $variation_add_to_cart_url = $variation_obj->add_to_cart_url();
                $data['variations'][$key]['add_to_cart_url'] = $variation_add_to_cart_url;
                $data['variations'][$key]['add_to_cart'] = $this->normalize_add_to_cart_payload(
                    $data['variations'][$key]['add_to_cart'] ?? [],
                    $variation_add_to_cart_url,
                    $variation_obj
                );

                if (isset($data['variations'][$key]['attributes'])) {
                    $formatted_attrs = $this->format_variation_attributes_output(
                        $product,
                        $data['variations'][$key]['attributes']
                    );
                    $data['variations'][$key]['attributes'] = $formatted_attrs;
                    $primary_color = $this->get_primary_color_from_attributes($formatted_attrs);
                    if ($primary_color) {
                        $data['variations'][$key]['swatch_color'] = $primary_color;
                    }
                }
            }
            
            // Ensure has_variations is set
            $data['has_variations'] = !empty($data['variations']);
        }

        if (isset($data['prices'])) {
            $data['prices'] = $this->normalize_store_api_prices($data['prices']);
        }

        $response->set_data($data);
        return $response;
    }

    /**
     * Modify Store API response after dispatch (final fallback)
     * This runs after the response is fully prepared
     */
    public function modify_store_api_response($response, $server, $request) {
        // Only handle Store API product requests
        if (!$request instanceof WP_REST_Request) {
            return $response;
        }

        // Check if response is a WP_REST_Response object
        if (!($response instanceof WP_REST_Response)) {
            return $response;
        }

        // Safely get route
        try {
            $route = $request->get_route();
        } catch (Exception $e) {
            return $response;
        }
        
        if (empty($route) || !is_string($route) || strpos($route, '/wc/store/v1/products') === false) {
            return $response;
        }

        // Only handle single product requests (with ID in route)
        if (!preg_match('#/wc/store/v1/products/(\d+)#', $route, $matches)) {
            return $response;
        }

        $product_id = (int) $matches[1];
        
        if (!function_exists('wc_get_product')) {
            return $response;
        }
        
        $product = wc_get_product($product_id);

        if (!$product) {
            return $response;
        }

        // Get response data safely
        try {
            $data = $response->get_data();
        } catch (Exception $e) {
            return $response;
        } catch (Error $e) {
            return $response;
        }
        
        if (is_object($data)) {
            $data = (array) $data;
        }
        
        if (!is_array($data)) {
            return $response;
        }
        
        // Check if this is a single product response (has 'id' field)
        if (!isset($data['id']) || $data['id'] != $product_id) {
            return $response;
        }
        
        // Add all extended fields (same as extend_product_detail_response)
        // This ensures all data from the screenshot is included
        try {
            // Get product attributes (color, size, etc.)
            $data['attributes'] = $this->get_product_attributes($product);
            
            // Get product specifications/attributes table
            $data['specifications'] = $this->get_product_specifications($product);

            // Get shipping details
            $data['shipping_details'] = $this->get_shipping_details($product);

            // Get return and refund information
            $data['return_refund'] = $this->get_return_refund_info($product);

            // Get reviews
            $data['reviews'] = $this->get_product_reviews($product->get_id());

            // Swatch + tab data
            $data['attribute_swatches'] = $this->get_attribute_swatches($product);
            $data['tabs'] = $this->build_product_tabs($product, $data);
            $data['features'] = $this->get_product_features();
            $data['wishlist'] = $this->get_wishlist_payload($product->get_id());
            $data['delivery_return'] = $this->get_delivery_return_entry();

            // Get related products + meta again for fallback path
            $data['related_products_meta'] = $this->get_related_products_meta();
            $data['related_products'] = $this->get_related_products($product->get_id());

            // Enhanced image data
            $image_id = $product->get_image_id();
            if ($image_id && isset($data['images'][0])) {
                $data['image'] = [
                    'id' => $image_id,
                    'url' => $data['images'][0]['src'] ?? wp_get_attachment_image_url($image_id, 'full'),
                    'thumbnail' => wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail'),
                    'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true) ?: $product->get_name(),
                ];
            }

            // Enhanced gallery data
            $gallery_ids = $product->get_gallery_image_ids();
            $gallery = [];
            foreach ($gallery_ids as $gallery_id) {
                $gallery[] = [
                    'id' => $gallery_id,
                    'url' => wp_get_attachment_image_url($gallery_id, 'full'),
                    'thumbnail' => wp_get_attachment_image_url($gallery_id, 'woocommerce_thumbnail'),
                    'alt' => get_post_meta($gallery_id, '_wp_attachment_image_alt', true) ?: '',
                ];
            }
            $data['gallery'] = $gallery;

            // All images (featured + gallery)
            $all_images = array_merge(
                $image_id ? [[
                    'id' => $image_id,
                    'url' => wp_get_attachment_image_url($image_id, 'full'),
                    'thumbnail' => wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail'),
                ]] : [],
                $gallery
            );
            $data['all_images'] = $all_images;

            // Enhanced rating data
            $data['rating'] = [
                'average' => (float) $product->get_average_rating(),
                'count' => (int) $product->get_rating_count(),
                'stars' => wc_get_rating_html($product->get_average_rating()),
            ];

            // Enhanced stock information
            $data['stock'] = [
                'status' => $product->get_stock_status(),
                'quantity' => $product->get_stock_quantity(),
                'text' => $this->get_stock_status_text($product->get_stock_status(), $product->is_in_stock()),
            ];

            // Tax information
            $data['tax'] = [
                'status' => $product->is_taxable() ? 'taxable' : 'none',
                'class' => $product->get_tax_class(),
            ];
            $data['tax_notice'] = $this->get_tax_notice_text();

            // Additional product data
            $data['weight'] = $product->get_weight();
            $data['dimensions'] = [
                'length' => $product->get_length(),
                'width' => $product->get_width(),
                'height' => $product->get_height(),
            ];
            $data['featured'] = $product->is_featured();
            $data['purchasable'] = $product->is_purchasable();
            $data['sold_individually'] = $product->is_sold_individually();
            $data['virtual'] = $product->is_virtual();
            $data['downloadable'] = $product->is_downloadable();
            $data['permalink'] = $product->get_permalink();
            $data['add_to_cart_url'] = $product->add_to_cart_url();
            $data['buy_now_button'] = $this->get_buy_now_button_data($product);

            // Enhanced categories
            $categories = $product->get_category_ids();
            $formatted_categories = [];
            foreach ($categories as $cat_id) {
                $cat = get_term($cat_id, 'product_cat');
                if ($cat && !is_wp_error($cat)) {
                    $formatted_categories[] = [
                        'id' => $cat->term_id,
                        'name' => $cat->name,
                        'slug' => $cat->slug,
                    ];
                }
            }
            $data['categories'] = $formatted_categories;

            // Enhanced tags
            $tags = $product->get_tag_ids();
            $formatted_tags = [];
            foreach ($tags as $tag_id) {
                $tag = get_term($tag_id, 'product_tag');
                if ($tag && !is_wp_error($tag)) {
                    $formatted_tags[] = [
                        'id' => $tag->term_id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                    ];
                }
            }
            $data['tags'] = $formatted_tags;

            $data['layout'] = $this->build_product_layout($product, $data);

        $primary_add_to_cart_url = $this->get_popup_add_to_cart_url($product);
        $data['add_to_cart_url'] = $primary_add_to_cart_url;
        $data['add_to_cart'] = $this->normalize_add_to_cart_payload($data['add_to_cart'] ?? [], $primary_add_to_cart_url, $product);
            $primary_add_to_cart_url = $this->get_popup_add_to_cart_url($product);
            $data['add_to_cart_url'] = $primary_add_to_cart_url;
            $data['add_to_cart'] = $this->normalize_add_to_cart_payload($data['add_to_cart'] ?? [], $primary_add_to_cart_url, $product);

            if (isset($data['prices'])) {
                $data['prices'] = $this->normalize_store_api_prices($data['prices']);
            }
        } catch (Exception $e) {
            // If adding extended fields fails, continue without them
        } catch (Error $e) {
            // Catch fatal errors too
        }
        
        // Always rebuild variations with prices (for variable products)
        // Store API returns variations as an array (but items might be objects)
        if ($product->is_type('variable') && isset($data['variations'])) {
            try {
                // Ensure variations is an array we can iterate over
                $variations_array = is_array($data['variations']) ? $data['variations'] : [];
                
                $variation_ids = $product->get_children();
                $variations_with_prices = [];
                
                if (!empty($variation_ids)) {
                    foreach ($variation_ids as $variation_id) {
                        $variation_obj = wc_get_product($variation_id);
                        if (!$variation_obj || !$variation_obj->exists()) {
                            continue;
                        }

                        // Find matching variation in response to preserve ALL its structure
                        $matching_variation = null;
                        foreach ($variations_array as $existing_var) {
                            // Handle both array and object formats
                            $var_id = is_object($existing_var) ? (isset($existing_var->id) ? $existing_var->id : null) : (isset($existing_var['id']) ? $existing_var['id'] : null);
                            if ($var_id == $variation_id) {
                                $matching_variation = $existing_var;
                                break;
                            }
                        }

                        // Start with existing variation data to preserve everything
                        // Convert object to array if needed
                        if (is_object($matching_variation)) {
                            $variation_data = (array) $matching_variation;
                        } else {
                            $variation_data = $matching_variation ? $matching_variation : [];
                        }
                        
                        // Ensure we have the ID
                        $variation_data['id'] = $variation_id;

                        // Get prices from meta
                        $regular_price = get_post_meta($variation_id, '_regular_price', true);
                        $sale_price = get_post_meta($variation_id, '_sale_price', true);
                        $price_meta = get_post_meta($variation_id, '_price', true);
                        
                        if (empty($price_meta)) {
                            $price_meta = $variation_obj->get_price();
                        }
                        if (empty($regular_price)) {
                            $regular_price = $variation_obj->get_regular_price();
                        }
                        if (empty($sale_price)) {
                            $sale_price = $variation_obj->get_sale_price();
                        }
                        
                        $price = !empty($sale_price) && $variation_obj->is_on_sale() ? $sale_price : $regular_price;
                        if (empty($price)) {
                            $price = $price_meta;
                        }
                        
                        $price_html = '';
                        if (!empty($price)) {
                            if (!empty($sale_price) && $variation_obj->is_on_sale()) {
                                $price_html = wc_format_sale_price($regular_price, $sale_price);
                            } else {
                                $price_html = wc_price($price);
                            }
                        }
                        
                        $regular_price = $regular_price !== '' ? (float) $regular_price : null;
                        $sale_price = $sale_price !== '' ? (float) $sale_price : null;
                        $price = $price !== '' ? (float) $price : null;
                        
                        // Add/update price information (preserve all other data)
                        $variation_data['price'] = [
                            'raw' => $price !== null ? (string) $price : null,
                            'formatted' => $price_html ?: '',
                            'regular' => $regular_price,
                            'sale' => $sale_price,
                            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
                            'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '',
                            'on_sale' => $variation_obj->is_on_sale(),
                        ];

                        $variation_add_to_cart_url = $variation_obj->add_to_cart_url();
                        $variation_data['add_to_cart_url'] = $variation_add_to_cart_url;
                        $variation_data['add_to_cart'] = $this->normalize_add_to_cart_payload(
                            $variation_data['add_to_cart'] ?? [],
                            $variation_add_to_cart_url,
                            $variation_obj
                        );
                        
                        $variations_with_prices[] = $variation_data;
                    }
                }
                
                // Replace variations array
                $data['variations'] = $variations_with_prices;
            } catch (Exception $e) {
                // If variations processing fails, keep original variations
            } catch (Error $e) {
                // Catch fatal errors too
            }
        }
        
        if (isset($data['prices'])) {
            $data['prices'] = $this->normalize_store_api_prices($data['prices']);
        }

        // Always set the updated data back to response
        try {
            $response->set_data($data);
        } catch (Exception $e) {
            // If setting data fails, return original response
            return $response;
        } catch (Error $e) {
            return $response;
        }

        return $response;
    }

    /**
     * Check if this is a Store API request
     */
    private function is_store_api_request($request) {
        if (!$request instanceof WP_REST_Request) {
            return false;
        }

        $route = $request->get_route();
        if (!is_string($route)) {
            return false;
        }

        // Check if it's a Store API product route (list or single)
        return strpos($route, '/wc/store/v1/products') !== false;
    }

    /**
     * Get product attributes with options
     */
    private function get_product_attributes($product) {
        $attributes = [];
        $product_attributes = $product->get_attributes();

        foreach ($product_attributes as $attribute_name => $attribute) {
            $taxonomy = wc_attribute_taxonomy_name(str_replace('pa_', '', $attribute_name));
            $is_taxonomy = taxonomy_exists($taxonomy);

            if ($is_taxonomy) {
                // Get terms for this taxonomy
                $terms = wc_get_product_terms($product->get_id(), $taxonomy, ['fields' => 'all']);
                
                $attribute_options = [];
                foreach ($terms as $term) {
                    $attribute_options[] = [
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'description' => $term->description,
                    ];
                }

                $attributes[] = [
                    'id' => wc_attribute_taxonomy_id_by_name($taxonomy),
                    'name' => wc_attribute_label($attribute_name, $product),
                    'slug' => $attribute_name,
                    'type' => 'select',
                    'options' => $attribute_options,
                    'visible' => $attribute->get_visible(),
                    'variation' => $attribute->get_variation(),
                ];
            } else {
                // Custom attribute (not a taxonomy)
                $options = $attribute->get_options();
                $attribute_options = [];
                foreach ($options as $option) {
                    $attribute_options[] = [
                        'name' => $option,
                        'slug' => sanitize_title($option),
                    ];
                }

                $attributes[] = [
                    'name' => wc_attribute_label($attribute_name, $product),
                    'slug' => $attribute_name,
                    'type' => 'text',
                    'options' => $attribute_options,
                    'visible' => $attribute->get_visible(),
                    'variation' => $attribute->get_variation(),
                ];
            }
        }

        return $attributes;
    }

    /**
     * Enhance existing Store API variations with price information
     */
    private function enhance_variations_with_prices($variations, $product) {
        if (empty($variations) || !is_array($variations)) {
            return $variations;
        }

        $enhanced_variations = [];
        
        foreach ($variations as $variation_data) {
            // Get variation ID from the variation data
            $variation_id = isset($variation_data['id']) ? $variation_data['id'] : null;
            
            if (!$variation_id) {
                // If no ID, keep the variation as is
                $enhanced_variations[] = $variation_data;
                continue;
            }

            // Get the variation product object
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->exists()) {
                // If variation doesn't exist, keep original data
                $enhanced_variations[] = $variation_data;
                continue;
            }

            // Format price information
            $regular_price = $variation->get_regular_price();
            $sale_price = $variation->get_sale_price();
            $price = $variation->get_price();
            $price_html = $variation->get_price_html();

            // Ensure we have a valid price (use regular price if price is empty)
            if (empty($price) && !empty($regular_price)) {
                $price = $regular_price;
            }

            // Add price information to the variation (merge to preserve existing data)
            $variation_add_to_cart_url = $variation->add_to_cart_url();
            $variation_data = array_merge($variation_data, [
                'price' => [
                    'raw' => $price ? (string) $price : null,
                    'formatted' => $price_html ?: '',
                    'regular' => $regular_price ? (float) $regular_price : null,
                    'sale' => $sale_price ? (float) $sale_price : null,
                    'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
                    'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '',
                    'on_sale' => $variation->is_on_sale(),
                ],
                'add_to_cart_url' => $variation_add_to_cart_url,
                'add_to_cart' => $this->normalize_add_to_cart_payload(
                    $variation_data['add_to_cart'] ?? [],
                    $variation_add_to_cart_url,
                    $variation
                ),
            ]);

            // Ensure attributes are formatted correctly if not already
            if (isset($variation_data['attributes']) && is_array($variation_data['attributes'])) {
                $formatted_attributes = [];
                foreach ($variation_data['attributes'] as $attr) {
                    // If already formatted with name/value, keep it
                    if (isset($attr['name']) && isset($attr['value'])) {
                        $formatted_attributes[] = $attr;
                    } else {
                        // Format it properly
                        $attr_name = isset($attr['name']) ? $attr['name'] : (isset($attr['attribute']) ? $attr['attribute'] : '');
                        $attr_value = isset($attr['value']) ? $attr['value'] : '';
                        $formatted_attributes[] = [
                            'name' => $attr_name,
                            'value' => $attr_value,
                            'slug' => isset($attr['slug']) ? $attr['slug'] : sanitize_title($attr_name),
                        ];
                    }
                }
                $variation_data['attributes'] = $formatted_attributes;
            }

            $enhanced_variations[] = $variation_data;
        }

        return $enhanced_variations;
    }

    private function normalize_store_api_prices($prices) {
        if (is_object($prices)) {
            $prices = (array) $prices;
        }

        if (!is_array($prices) || empty($prices)) {
            return $prices;
        }

        $minor_unit = isset($prices['currency_minor_unit'])
            ? (int) $prices['currency_minor_unit']
            : (function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2);

        foreach (['price', 'regular_price', 'sale_price'] as $key) {
            if (array_key_exists($key, $prices)) {
                $prices[$key] = $this->convert_minor_to_display_price($prices[$key], $minor_unit);
            }
        }

        if (isset($prices['price_range'])) {
            $price_range = is_object($prices['price_range']) ? (array) $prices['price_range'] : $prices['price_range'];

            if (is_array($price_range)) {
                foreach (['min_amount', 'max_amount'] as $range_key) {
                    if (array_key_exists($range_key, $price_range)) {
                        $price_range[$range_key] = $this->convert_minor_to_display_price(
                            $price_range[$range_key],
                            $minor_unit
                        );
                    }
                }
                $prices['price_range'] = $price_range;
            }
        }

        return $prices;
    }

    private function convert_minor_to_display_price($value, $minor_unit = 2) {
        if ($value === '' || $value === null) {
            return $value;
        }

        // Avoid double conversion if the value already has decimals
        $string_value = (string) $value;
        if (strpos($string_value, '.') !== false) {
            return $string_value;
        }

        if (!is_numeric($string_value)) {
            return $value;
        }

        $decimals = is_numeric($minor_unit) ? (int) $minor_unit : 2;
        if ($decimals < 0) {
            $decimals = 2;
        }

        $divisor = pow(10, $decimals);
        $normalized = $divisor > 0 ? ((float) $string_value) / $divisor : (float) $string_value;

        return number_format($normalized, $decimals, '.', '');
    }

    /**
     * Get product variations
     */
    private function get_product_variations($product) {
        if (!$product->is_type('variable')) {
            return [];
        }

        $variations = [];
        
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->exists()) {
                continue;
            }

            $variation_image_id = $variation->get_image_id();
            $variation_image = null;
            if ($variation_image_id) {
                $variation_image = [
                    'id' => $variation_image_id,
                    'url' => wp_get_attachment_image_url($variation_image_id, 'full'),
                    'thumbnail' => wp_get_attachment_image_url($variation_image_id, 'woocommerce_thumbnail'),
                ];
            }

            $variation_attributes = [];
            foreach ($variation->get_variation_attributes() as $attr_name => $attr_value) {
                // Format attributes with name and value
                $attribute_label = wc_attribute_label($attr_name, $product);
                $variation_attributes[] = [
                    'name' => $attribute_label,
                    'value' => $attr_value,
                    'slug' => sanitize_title($attr_name),
                ];
            }
            $formatted_variation_attributes = $this->format_variation_attributes_output($product, $variation_attributes);
            $primary_color = $this->get_primary_color_from_attributes($formatted_variation_attributes);

            // Format price information
            $regular_price = $variation->get_regular_price();
            $sale_price = $variation->get_sale_price();
            $price = $variation->get_price();
            $price_html = $variation->get_price_html();

            $variation_add_to_cart_url = $variation->add_to_cart_url();
            $variations[] = [
                'id' => $variation_id,
                'sku' => $variation->get_sku(),
                'price' => [
                    'raw' => $price,
                    'formatted' => $price_html,
                    'regular' => $regular_price ? (float) $regular_price : null,
                    'sale' => $sale_price ? (float) $sale_price : null,
                    'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
                    'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '',
                    'on_sale' => $variation->is_on_sale(),
                ],
                'stock_status' => $variation->get_stock_status(),
                'stock_quantity' => $variation->get_stock_quantity(),
                'in_stock' => $variation->is_in_stock(),
                'manage_stock' => $variation->get_manage_stock(),
                'attributes' => $formatted_variation_attributes,
                'swatch_color' => $primary_color,
                'add_to_cart_url' => $variation_add_to_cart_url,
                'add_to_cart' => $this->normalize_add_to_cart_payload(
                    [],
                    $variation_add_to_cart_url,
                    $variation
                ),
                'image' => $variation_image,
                'weight' => $variation->get_weight(),
                'dimensions' => [
                    'length' => $variation->get_length(),
                    'width' => $variation->get_width(),
                    'height' => $variation->get_height(),
                ],
            ];
        }

        return $variations;
    }

    /**
     * Normalize variation attributes (adds slugs and color metadata)
     */
    private function format_variation_attributes_output($product, $attributes) {
        if (empty($attributes) || !is_array($attributes)) {
            return [];
        }

        $formatted = [];
        foreach ($attributes as $key => $attribute) {
            $entry = $this->normalize_attribute_entry($product, $key, $attribute);
            if ($entry) {
                $formatted[] = $entry;
            }
        }

        return $formatted;
    }

    private function normalize_attribute_entry($product, $key, $attribute) {
        $raw_label = '';
        $raw_value = '';
        $attribute_slug = '';
        $option_slug = '';

        if (is_array($attribute)) {
            $raw_label = isset($attribute['name']) ? (string) $attribute['name'] : '';
            $raw_value = isset($attribute['value']) ? (string) $attribute['value'] : '';
            $option_slug = isset($attribute['slug']) ? (string) $attribute['slug'] : sanitize_title($raw_value);
            $attribute_slug = isset($attribute['attribute']) ? (string) $attribute['attribute'] : (is_string($key) ? $key : '');
        } else {
            $raw_value = (string) $attribute;
            $option_slug = sanitize_title($raw_value);
            $attribute_slug = is_string($key) ? $key : '';
        }

        if (empty($attribute_slug) && $raw_label) {
            $attribute_slug = $this->guess_attribute_slug($product, $raw_label);
        }

        $attribute_slug = $this->normalize_attribute_key($attribute_slug);
        $label = $raw_label ?: ($attribute_slug ? wc_attribute_label($attribute_slug, $product) : '');
        $value = $raw_value;

        $color = null;
        if ($this->is_color_attribute($attribute_slug, $label)) {
            $color = $this->resolve_attribute_color($attribute_slug, $option_slug, $value);
        }

        return [
            'attribute' => $attribute_slug ?: sanitize_title($label),
            'name' => $label ?: $attribute_slug,
            'value' => $value,
            'slug' => $option_slug,
            'color' => $color,
        ];
    }

    private function guess_attribute_slug($product, $label) {
        if (!$label) {
            return '';
        }

        $label = strtolower($label);
        foreach ($product->get_attributes() as $name => $attribute) {
            $attribute_label = strtolower(wc_attribute_label($attribute->get_name(), $product));
            if ($attribute_label === $label || strtolower($attribute->get_name()) === $label) {
                return $attribute->get_name();
            }
        }

        return sanitize_title($label);
    }

    private function normalize_attribute_key($key) {
        if (!$key) {
            return '';
        }

        $key = strtolower($key);
        $key = str_replace('attribute_', '', $key);
        $key = ltrim($key, '_');

        if (strpos($key, 'pa_') === 0) {
            return $key;
        }

        if (taxonomy_exists($key)) {
            return $key;
        }

        if (taxonomy_exists('pa_' . $key)) {
            return 'pa_' . $key;
        }

        return $key;
    }

    private function normalize_attribute_taxonomy($attribute_slug) {
        if (!$attribute_slug) {
            return '';
        }

        $attribute_slug = strtolower($attribute_slug);
        if (taxonomy_exists($attribute_slug)) {
            return $attribute_slug;
        }

        if (strpos($attribute_slug, 'pa_') !== 0 && taxonomy_exists('pa_' . $attribute_slug)) {
            return 'pa_' . $attribute_slug;
        }

        return $attribute_slug;
    }

    private function resolve_attribute_color($attribute_slug, $option_slug, $option_label) {
        $taxonomy = $this->normalize_attribute_taxonomy($attribute_slug);

        if ($taxonomy && taxonomy_exists($taxonomy)) {
            $term = null;
            if ($option_slug) {
                $term = get_term_by('slug', $option_slug, $taxonomy);
            }
            if (!$term && $option_label) {
                $term = get_term_by('name', $option_label, $taxonomy);
            }
            if ($term && !is_wp_error($term)) {
                return $this->extract_color_from_term($term);
            }
        }

        return $this->resolve_color_from_name($option_label ?: $option_slug);
    }

    private function get_primary_color_from_attributes($attributes) {
        if (empty($attributes)) {
            return null;
        }

        foreach ($attributes as $attribute) {
            if (!empty($attribute['color'])) {
                return [
                    'attribute' => $attribute['attribute'],
                    'value' => $attribute['value'],
                    'color' => $attribute['color'],
                ];
            }
        }

        return null;
    }

    public function get_popup_payload($product) {
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product) {
            return [];
        }

        $images = $this->format_product_images($product);

        $average_rating = number_format((float) $product->get_average_rating(), 2, '.', '');
        $review_count = (int) $product->get_review_count();

        $data = [
            'images' => $images['images'],
            'gallery' => $images['gallery'],
            'all_images' => $images['all_images'],
            'short_description' => wpautop($product->get_short_description()),
            'description' => wpautop($product->get_description()),
            'price_html' => $product->get_price_html(),
            'prices' => [
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'currency' => get_woocommerce_currency(),
                'currency_symbol' => get_woocommerce_currency_symbol(),
            ],
            'stock' => [
                'status' => $product->get_stock_status(),
                'quantity' => $product->get_stock_quantity(),
                'manage_stock' => $product->get_manage_stock(),
                'in_stock' => $product->is_in_stock(),
                'backorders_allowed' => $product->backorders_allowed(),
                'stock_status_text' => $this->get_stock_status_text($product->get_stock_status(), $product->is_in_stock()),
            ],
            'attribute_swatches' => $this->get_attribute_swatches($product),
            'features' => $this->get_product_features(),
            'specifications' => $this->get_product_specifications($product),
            'shipping_details' => $this->get_shipping_details($product),
            'return_refund' => $this->get_return_refund_info($product),
            'reviews' => [],
            'related_products_meta' => $this->get_related_products_meta(),
            'related_products' => $this->get_related_products($product->get_id()),
            'tax_notice' => $this->get_tax_notice_text(),
            'buy_now_button' => $this->get_buy_now_button_data($product),
            'permalink' => $product->get_permalink(),
            'wishlist' => $this->get_wishlist_payload($product->get_id()),
            'average_rating' => $average_rating,
            'review_count' => $review_count,
        ];

        if ($product->is_type('variable')) {
            $data['variations'] = $this->get_product_variations($product);
        } else {
            $data['variations'] = [];
        }

        $data['delivery_return'] = $this->get_delivery_return_entry();
        $data['tabs'] = $this->build_product_tabs($product, $data);

        $layout = $this->build_product_layout($product, $data);

        // Trim popup payload to only the sections used in the modal UI
        if (isset($layout['hero']['gallery'])) {
            unset($layout['hero']['gallery']);
        }
        if (isset($layout['hero']['all_images'])) {
            unset($layout['hero']['all_images']);
        }
        if (isset($layout['tabs'])) {
            unset($layout['tabs']);
        }
        if (isset($layout['related'])) {
            unset($layout['related']);
        }

        $average_rating = number_format((float) $product->get_average_rating(), 2, '.', '');
        $review_count = (int) $product->get_review_count();

        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'permalink' => $product->get_permalink(),
            'average_rating' => $average_rating,
            'review_count' => $review_count,
            'layout' => $layout,
        ];
    }

    /**
     * Build UI-friendly layout structure to match the design order
     */
    private function build_product_layout($product, $data) {
        $hero = [
            'images' => $data['images'] ?? [],
            'gallery' => $data['gallery'] ?? [],
            'all_images' => $data['all_images'] ?? [],
        ];

        $delivery_return = $data['delivery_return'] ?? $this->get_delivery_return_entry();
        $summary = [
            'title' => $data['name'] ?? $product->get_name(),
            'rating' => $data['rating'] ?? [
                'average' => (float) $product->get_average_rating(),
                'count' => (int) $product->get_review_count(),
            ],
            'price_html' => $data['price_html'] ?? $product->get_price_html(),
            'prices' => [
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'currency' => get_woocommerce_currency(),
                'currency_symbol' => get_woocommerce_currency_symbol(),
            ],
            'short_description' => $data['short_description'] ?? wpautop($product->get_short_description()),
            'sku' => $product->get_sku(),
            'stock' => $data['stock'] ?? [
                'status' => $product->get_stock_status(),
                'quantity' => $product->get_stock_quantity(),
                'in_stock' => $product->is_in_stock(),
            ],
            'tax_notice' => $data['tax_notice'] ?? $this->get_tax_notice_text(),
        ];

        if (!empty($delivery_return)) {
            $summary['delivery_return'] = $delivery_return;
        }

        $swatches = $data['attribute_swatches'] ?? $this->get_attribute_swatches($product);

        $actions = [
            'buy_now' => $data['buy_now_button'] ?? $this->get_buy_now_button_data($product),
            'add_to_cart' => [
                'url' => $this->get_popup_add_to_cart_url($product, $data['variations'] ?? null),
                'text' => $product->add_to_cart_text(),
                'single_text' => $product->single_add_to_cart_text(),
            ],
            'wishlist' => $data['wishlist'] ?? $this->get_wishlist_payload($product->get_id()),
        ];

        $features = $data['features'] ?? $this->get_product_features();
        $tabs = $data['tabs'] ?? $this->build_product_tabs($product, $data);

        $related = [
            'meta' => $data['related_products_meta'] ?? $this->get_related_products_meta(),
            'products' => $data['related_products'] ?? $this->get_related_products($product->get_id()),
        ];

        $variations = [];
        if ($product->is_type('variable')) {
            if (!empty($data['variations']) && is_array($data['variations'])) {
                $variations = $data['variations'];
            } else {
                $variations = $this->get_product_variations($product);
            }
        }

        return [
            'hero' => $hero,
            'summary' => $summary,
            'swatches' => $swatches,
            'actions' => $actions,
            'features' => [
                'summary' => $features['summary'] ?? [],
            ],
            'tabs' => $tabs,
            'related' => $related,
            'variations' => $variations,
        ];
    }

    /**
     * Get product specifications
     */
    private function get_product_specifications($product) {
        $specifications = [];
        $product_id = $product->get_id();

        // Common product attribute fields
        $spec_fields = [
            'Top Style' => 'pa_top_style',
            'Neck/Neckline' => 'pa_neckline',
            'Top Pattern' => 'pa_top_pattern',
            'Sleeve Detail' => 'pa_sleeve_detail',
            'Bottom Fabric' => 'pa_bottom_fabric',
            'Fabric Dupatta/Stole' => 'pa_fabric_dupatta',
            'Fabric' => 'pa_fabric',
        ];

        foreach ($spec_fields as $label => $attribute_slug) {
            $value = $product->get_attribute($attribute_slug);
            if ($value) {
                $specifications[] = [
                    'label' => $label,
                    'value' => $value,
                    'attribute' => $attribute_slug,
                ];
            }
        }

        // Also include all other attributes
        $all_attributes = $product->get_attributes();
        foreach ($all_attributes as $attribute_name => $attribute) {
            $label = wc_attribute_label($attribute_name, $product);
            $value = $product->get_attribute($attribute_name);
            
            // Skip if already added
            $already_added = false;
            foreach ($specifications as $spec) {
                if ($spec['attribute'] === $attribute_name) {
                    $already_added = true;
                    break;
                }
            }

            if (!$already_added && $value) {
                $specifications[] = [
                    'label' => $label,
                    'value' => $value,
                    'attribute' => $attribute_name,
                ];
            }
        }

        return $specifications;
    }

    /**
     * Build data payload for custom product tabs (mirrors storefront tabs)
     */
    private function build_product_tabs($product, $data) {
        $tabs = [];

        $description = isset($data['description']) ? $data['description'] : wpautop($product->get_description());
        $specifications = isset($data['specifications']) ? $data['specifications'] : $this->get_product_specifications($product);
        $shipping_details = isset($data['shipping_details']) ? $data['shipping_details'] : $this->get_shipping_details($product);
        $return_refund = isset($data['return_refund']) ? $data['return_refund'] : $this->get_return_refund_info($product);
        $reviews = isset($data['reviews']) ? $data['reviews'] : $this->get_product_reviews($product->get_id());

        $shipping_theme_text = get_theme_mod('styluza_product_shipping_text', '');
        if (!empty($shipping_theme_text)) {
            $shipping_details['theme_text'] = wp_kses_post(wpautop($shipping_theme_text));
        }

        $return_theme_text = get_theme_mod('styluza_product_return_text', '');
        if (!empty($return_theme_text)) {
            $return_refund['theme_text'] = wp_kses_post(wpautop($return_theme_text));
        }

        $tabs[] = [
            'key' => 'product_details',
            'title' => __('Product Details', 'headlessplugin'),
            'priority' => 10,
            'description' => $description,
            'specifications' => $specifications,
            'has_content' => !empty($description) || !empty($specifications),
        ];

        $tabs[] = [
            'key' => 'shipping_details',
            'title' => __('Shipping Details', 'headlessplugin'),
            'priority' => 20,
            'details' => $shipping_details,
            'has_content' => !empty(array_filter($shipping_details)),
        ];

        $tabs[] = [
            'key' => 'return_refund',
            'title' => __('Return & Refund', 'headlessplugin'),
            'priority' => 30,
            'details' => $return_refund,
            'has_content' => !empty(array_filter($return_refund)),
        ];

        $tabs[] = [
            'key' => 'reviews',
            'title' => __('Reviews', 'headlessplugin'),
            'priority' => 40,
            'summary' => [
                'average' => (float) $product->get_average_rating(),
                'count' => (int) $product->get_review_count(),
            ],
            'items' => $reviews,
            'has_content' => !empty($reviews),
        ];

        return $tabs;
    }

    /**
     * Build attribute swatch payload for headless front-end
     */
    private function get_attribute_swatches($product) {
        $swatches = [];
        $attributes = $product->get_attributes();

        if (empty($attributes)) {
            return $swatches;
        }

        foreach ($attributes as $attribute_name => $attribute) {
            $label = wc_attribute_label($attribute_name, $product);
            $type = $this->is_color_attribute($attribute_name, $label) ? 'color' : 'text';
            $options = [];

            if ($attribute->is_taxonomy()) {
                $taxonomy = $attribute->get_name();
                $terms = wc_get_product_terms($product->get_id(), $taxonomy, ['fields' => 'all']);
                foreach ($terms as $term) {
                    $option = [
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    ];

                    if ($type === 'color') {
                        $option['color'] = $this->extract_color_from_term($term);
                    }

                    $options[] = $option;
                }
            } else {
                $attribute_options = $attribute->get_options();
                if (is_array($attribute_options)) {
                    foreach ($attribute_options as $index => $option_value) {
                        $option = [
                            'id' => is_numeric($index) ? (int) $index : $index,
                            'name' => $option_value,
                            'slug' => sanitize_title($option_value),
                        ];
                        if ($type === 'color') {
                            $option['color'] = $this->resolve_color_from_name($option_value);
                        }
                        $options[] = $option;
                    }
                }
            }

            if (!empty($options)) {
                $heading = $label ? sprintf(__('Select %s', 'headlessplugin'), $label) : __('Select option', 'headlessplugin');
                $swatches[] = [
                    'attribute' => $attribute->get_name(),
                    'label' => $label,
                    'heading' => $heading,
                    'type' => $type,
                    'options' => $options,
                ];
            }
        }

        return $swatches;
    }

    /**
     * Determine if attribute looks like a color attribute
     */
    private function is_color_attribute($attribute_name, $label = '') {
        $haystack = strtolower($attribute_name . ' ' . $label);
        return strpos($haystack, 'color') !== false || strpos($haystack, 'colour') !== false;
    }

    /**
     * Resolve color value for taxonomy term
     */
    private function extract_color_from_term($term) {
        if (!$term) {
            return $this->resolve_color_from_name('');
        }

        $meta_keys = [
            'product_attribute_color',
            'color',
            'pa_color',
            'styluza_color_value',
            '_color',
        ];

        foreach ($meta_keys as $key) {
            $value = get_term_meta($term->term_id, $key, true);
            if ($this->is_valid_hex_color($value)) {
                return strtoupper($value);
            }
        }

        // Check option level custom map
        $custom_map = $this->get_custom_color_map();
        $term_name = strtolower($term->name);
        if (isset($custom_map[$term_name])) {
            return strtoupper($custom_map[$term_name]);
        }

        return $this->resolve_color_from_name($term->name);
    }

    /**
     * Resolve color from arbitrary name (fallbacks to deterministic hash)
     */
    private function resolve_color_from_name($name) {
        $name = trim((string) $name);
        if ($name === '') {
            return '#CCCCCC';
        }

        if ($this->is_valid_hex_color($name)) {
            return strtoupper($name);
        }

        $custom_map = $this->get_custom_color_map();
        $key = strtolower($name);
        if (isset($custom_map[$key])) {
            return strtoupper($custom_map[$key]);
        }

        return $this->generate_color_from_name($name);
    }

    /**
     * Retrieve custom color map option (shared with theme/front-end)
     */
    private function get_custom_color_map() {
        $map = get_option('styluza_custom_color_map', []);
        if (!is_array($map)) {
            $map = [];
        }

        // Normalize keys to lowercase for consistent lookups
        $normalized = [];
        foreach ($map as $key => $value) {
            if (!$this->is_valid_hex_color($value)) {
                continue;
            }
            $normalized[strtolower($key)] = $value;
        }

        return $normalized;
    }

    /**
     * Check if string is a valid hex color
     */
    private function is_valid_hex_color($value) {
        return is_string($value) && preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', trim($value));
    }

    /**
     * Generate deterministic color hex based on name
     */
    private function generate_color_from_name($name) {
        $hash = md5(strtolower($name));
        $color = '#' . substr($hash, 0, 6);
        return strtoupper($color);
    }

    private function format_product_images($product) {
        $main_id = $product->get_image_id();
        $gallery_ids = $product->get_gallery_image_ids();
        $image_ids = array_values(array_filter(array_merge([$main_id], $gallery_ids)));

        $format = function($image_id, $fallback_alt = '') {
            return [
                'id' => $image_id,
                'url' => wp_get_attachment_image_url($image_id, 'full'),
                'thumbnail' => wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail'),
                'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true) ?: $fallback_alt,
            ];
        };

        $images = [];
        foreach ($image_ids as $id) {
            $images[] = $format($id, $product->get_name());
        }

        return [
            'images' => $images,
            'gallery' => $images,
            'all_images' => $images,
        ];
    }

    /**
     * Get shipping details
     */
    private function get_shipping_details($product) {
        $shipping_details = [
            'weight' => $product->get_weight(),
            'dimensions' => [
                'length' => $product->get_length(),
                'width' => $product->get_width(),
                'height' => $product->get_height(),
            ],
            'shipping_class' => $product->get_shipping_class(),
            'shipping_class_id' => $product->get_shipping_class_id(),
        ];

        // Get shipping class details if available
        if ($product->get_shipping_class_id()) {
            $shipping_class = get_term($product->get_shipping_class_id(), 'product_shipping_class');
            if ($shipping_class && !is_wp_error($shipping_class)) {
                $shipping_details['shipping_class_name'] = $shipping_class->name;
                $shipping_details['shipping_class_slug'] = $shipping_class->slug;
            }
        }

        // Get custom shipping meta if available
        $shipping_time = get_post_meta($product->get_id(), '_shipping_time', true);
        $shipping_charges = get_post_meta($product->get_id(), '_shipping_charges', true);
        $cod_available = get_post_meta($product->get_id(), '_cod_available', true);

        if ($shipping_time) {
            $shipping_details['shipping_time'] = $shipping_time;
        }
        if ($shipping_charges) {
            $shipping_details['shipping_charges'] = $shipping_charges;
        }
        if ($cod_available) {
            $shipping_details['cod_available'] = $cod_available;
        }

        return $shipping_details;
    }

    /**
     * Get return and refund information
     */
    private function get_return_refund_info($product) {
        $return_refund = [];

        // Get custom return/refund meta
        $return_window = get_post_meta($product->get_id(), '_return_window', true);
        $refund_policy = get_post_meta($product->get_id(), '_refund_policy', true);
        $return_condition = get_post_meta($product->get_id(), '_return_condition', true);

        if ($return_window) {
            $return_refund['return_window'] = $return_window;
        }
        if ($refund_policy) {
            $return_refund['refund_policy'] = $refund_policy;
        }
        if ($return_condition) {
            $return_refund['return_condition'] = $return_condition;
        }

        return $return_refund;
    }

    /**
     * Get product reviews
     */
    private function get_product_reviews($product_id, $per_page = 10) {
        $args = [
            'post_id' => $product_id,
            'status' => 'approve',
            'number' => $per_page,
            'orderby' => 'comment_date',
            'order' => 'DESC',
        ];

        $comments = get_comments($args);
        $reviews = [];

        foreach ($comments as $comment) {
            $rating = get_comment_meta($comment->comment_ID, 'rating', true);
            
            $reviews[] = [
                'id' => $comment->comment_ID,
                'author' => [
                    'name' => $comment->comment_author,
                ],
                'date' => $comment->comment_date,
                'date_gmt' => $comment->comment_date_gmt,
                'content' => $comment->comment_content,
                'rating' => $rating ? (int) $rating : null,
                'verified' => function_exists('wc_review_is_from_verified_owner') ? wc_review_is_from_verified_owner($comment->comment_ID) : false,
            ];
        }

        return $reviews;
    }

    /**
     * Get related products
     */
    private function get_related_products($product_id, $limit = 4) {
        $related_ids = wc_get_related_products($product_id, $limit);
        $related_products = [];

        foreach ($related_ids as $related_id) {
            $related_product = wc_get_product($related_id);
            if (!$related_product) {
                continue;
            }

            $average_rating = (float) $related_product->get_average_rating();
            $review_count = (int) $related_product->get_review_count();

            $related_products[] = [
                'id' => $related_product->get_id(),
                'name' => $related_product->get_name(),
                'slug' => $related_product->get_slug(),
                'permalink' => $related_product->get_permalink(),
                'add_to_cart_url' => $this->get_popup_add_to_cart_url($related_product),
                'price' => $related_product->get_price(),
                'regular_price' => $related_product->get_regular_price(),
                'sale_price' => $related_product->get_sale_price(),
                'on_sale' => $related_product->is_on_sale(),
                'price_html' => $related_product->get_price_html(),
                'image' => [
                    'id' => $related_product->get_image_id(),
                    'url' => wp_get_attachment_image_url($related_product->get_image_id(), 'full'),
                    'thumbnail' => wp_get_attachment_image_url($related_product->get_image_id(), 'woocommerce_thumbnail'),
                    'alt' => get_post_meta($related_product->get_image_id(), '_wp_attachment_image_alt', true) ?: $related_product->get_name(),
                ],
                'rating' => [
                    'average' => $average_rating > 0 ? $average_rating : null,
                    'count' => $review_count,
                ],
                'stock_status' => $related_product->get_stock_status(),
                'in_stock' => $related_product->is_in_stock(),
            ];
        }

        return $related_products;
    }

    /**
     * Related products heading/description from theme settings
     */
    private function get_related_products_meta() {
        $template_content = $this->get_related_block_content_from_template();

        $title = $template_content['title'] ?? get_theme_mod('styluza_related_products_title', '');
        if ($title === '') {
            $title = __('Related Products', 'headlessplugin');
        }

        $description = $template_content['description'] ?? get_theme_mod('styluza_related_products_description', '');
        if ($description !== '') {
            $description = wp_kses_post(wpautop($description));
        }

        return [
            'title' => $title,
            'description' => $description,
        ];
    }

    private function get_related_block_content_from_template() {
        if (!function_exists('get_block_template') || !function_exists('parse_blocks')) {
            return [];
        }

        $template = $this->get_active_single_product_template();
        if (!$template || empty($template->content)) {
            return [];
        }

        $blocks = parse_blocks($template->content);
        if (empty($blocks)) {
            return [];
        }

        return $this->find_related_block_text($blocks) ?: [];
    }

    private function get_active_single_product_template() {
        $themes = array_filter([
            get_option('stylesheet'),
            get_option('template'),
        ]);

        foreach ($themes as $theme_slug) {
            $template = get_block_template($theme_slug . '//single-product', 'wp_template');
            if ($template) {
                return $template;
            }
        }

        return get_block_template('woocommerce//single-product', 'wp_template');
    }

    private function find_related_block_text($blocks) {
        foreach ($blocks as $block) {
            if ($this->is_related_product_collection_block($block)) {
                return $this->extract_heading_paragraph_from_block($block);
            }

            if (!empty($block['innerBlocks'])) {
                $found = $this->find_related_block_text($block['innerBlocks']);
                if ($found) {
                    return $found;
                }
            }
        }

        return [];
    }

    private function is_related_product_collection_block($block) {
        if (!isset($block['blockName']) || $block['blockName'] !== 'woocommerce/product-collection') {
            return false;
        }

        if (isset($block['attrs']['collection']) && $block['attrs']['collection'] === 'woocommerce/product-collection/related') {
            return true;
        }

        if (!empty($block['attrs']['query']['relatedBy'])) {
            return (bool) (!empty($block['attrs']['query']['relatedBy']['categories']) || !empty($block['attrs']['query']['relatedBy']['tags']));
        }

        return false;
    }

    private function extract_heading_paragraph_from_block($block) {
        if (empty($block['innerBlocks'])) {
            return [];
        }

        $title = null;
        $description = null;

        foreach ($block['innerBlocks'] as $inner) {
            if (!$title && $inner['blockName'] === 'core/heading') {
                $title = $this->render_block_plain_text($inner);
            } elseif (!$description && $inner['blockName'] === 'core/paragraph') {
                $description = trim($inner['innerHTML'] ?? '');
            }

            if ($title && $description) {
                break;
            }
        }

        return array_filter([
            'title' => $title,
            'description' => $description,
        ]);
    }

    private function render_block_plain_text($block) {
        if (!empty($block['innerHTML'])) {
            return trim(wp_strip_all_tags($block['innerHTML']));
        }

        if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
            return trim(wp_strip_all_tags(implode('', $block['innerContent'])));
        }

        if (function_exists('render_block')) {
            return trim(wp_strip_all_tags(render_block($block)));
        }

        return '';
    }

    private function get_delivery_return_entry() {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        if (!function_exists('get_block_template') || !function_exists('parse_blocks')) {
            $cache = [];
            return $cache;
        }

        $template = $this->get_active_single_product_template();
        if (!$template || empty($template->content)) {
            $cache = [];
            return $cache;
        }

        $blocks = parse_blocks($template->content);
        if (empty($blocks)) {
            $cache = [];
            return $cache;
        }

        $heading_block = $this->find_delivery_return_heading($blocks);
        if (!$heading_block) {
            $cache = [];
            return $cache;
        }

        $label = $this->render_block_plain_text($heading_block);
        $url = $this->extract_first_anchor_href($heading_block);

        $cache = array_filter([
            'label' => $label,
            'url' => $url,
        ]);

        return $cache;
    }

    private function find_delivery_return_heading($blocks) {
        if (empty($blocks)) {
            return null;
        }

        $flat_blocks = $this->flatten_blocks($blocks);
        $after_add_to_cart = false;

        foreach ($flat_blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $block_name = $block['blockName'] ?? '';

            if ($block_name === 'woocommerce/add-to-cart-form') {
                $after_add_to_cart = true;
                continue;
            }

            if ($after_add_to_cart && $block_name === 'core/heading') {
                return $block;
            }
        }

        return null;
    }

    private function flatten_blocks($blocks) {
        $flat = [];

        if (!is_array($blocks)) {
            return $flat;
        }

        foreach ($blocks as $block) {
            $flat[] = $block;
            if (!empty($block['innerBlocks'])) {
                $flat = array_merge($flat, $this->flatten_blocks($block['innerBlocks']));
            }
        }

        return $flat;
    }

    private function extract_first_anchor_href($block) {
        $html = '';

        if (!empty($block['innerHTML'])) {
            $html = $block['innerHTML'];
        } elseif (!empty($block['innerContent']) && is_array($block['innerContent'])) {
            $html = implode('', $block['innerContent']);
        }

        if ($html && preg_match('/href="([^"]+)"/i', $html, $matches)) {
            return esc_url_raw($matches[1]);
        }

        return null;
    }

    /**
     * Map theme-based feature settings to API response
     */
    private function get_product_features() {
        $summary = [
            'free_shipping' => $this->build_feature_entry(
                get_theme_mod('styluza_product_free_shipping_text', __('Free Shipping', 'twentytwentyfive-child')),
                '',
                get_theme_mod('styluza_product_free_shipping_icon', '')
            ),
            'authentic_products' => $this->build_feature_entry(
                get_theme_mod('styluza_product_authentic_text', __('Authentic Products', 'twentytwentyfive-child')),
                '',
                get_theme_mod('styluza_product_authentic_icon', '')
            ),
            'easy_returns' => $this->build_feature_entry(
                get_theme_mod('styluza_product_easy_returns_text', __('Easy Returns', 'twentytwentyfive-child')),
                '',
                get_theme_mod('styluza_product_easy_returns_icon', '')
            ),
        ];

        $banner = [
            'free_shipping' => $this->build_feature_entry(
                get_theme_mod('styluza_features_free_shipping_title', __('Free Shipping', 'twentytwentyfive-child')),
                get_theme_mod('styluza_features_free_shipping_desc', __('Discover our exquisite range of garments that.', 'twentytwentyfive-child')),
                get_theme_mod('styluza_features_free_shipping_icon', '')
            ),
            'return_available' => $this->build_feature_entry(
                get_theme_mod('styluza_features_return_title', __('Return Available', 'twentytwentyfive-child')),
                get_theme_mod('styluza_features_return_desc', __('Discover our exquisite range of garments that.', 'twentytwentyfive-child')),
                get_theme_mod('styluza_features_return_icon', '')
            ),
            'secure_payment' => $this->build_feature_entry(
                get_theme_mod('styluza_features_secure_payment_title', __('Secure Payment', 'twentytwentyfive-child')),
                get_theme_mod('styluza_features_secure_payment_desc', __('Discover our exquisite range of garments that.', 'twentytwentyfive-child')),
                get_theme_mod('styluza_features_secure_payment_icon', '')
            ),
        ];

        $summary = array_filter($summary);
        $banner = array_filter($banner);

        return [
            'summary' => $summary,
            'banner' => $banner,
        ];
    }

    private function build_feature_entry($title, $description = '', $icon_setting = '') {
        $icon = $this->resolve_feature_icon($icon_setting);

        if ($title === '' && $description === '' && empty($icon)) {
            return null;
        }

        return [
            'title' => $title,
            'description' => $description,
            'icon' => $icon,
        ];
    }

    private function resolve_feature_icon($value) {
        if (empty($value)) {
            return '';
        }

        if (is_numeric($value)) {
            $url = wp_get_attachment_image_url((int) $value, 'full');
            if ($url) {
                return $url;
            }
        }

        if (is_array($value)) {
            if (!empty($value['id'])) {
                $url = wp_get_attachment_image_url((int) $value['id'], 'full');
                if ($url) {
                    return $url;
                }
            }

            if (!empty($value['url'])) {
                return esc_url_raw($value['url']);
            }
        }

        return esc_url_raw($value);
    }

    /**
     * Provide metadata for theme's custom Buy Now button so headless UI can render it
     */
    public function register_store_api_extensions() {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data([
            'endpoint' => ProductSchema::IDENTIFIER,
            'namespace' => 'headlessplugin',
            'schema_callback' => [$this, 'get_store_api_extension_schema'],
            'data_callback' => [$this, 'build_store_api_extension_data'],
            'schema_type' => ARRAY_A,
        ]);
    }

    public function get_store_api_extension_schema() {
        return [
            'links' => [
                'description' => __('Direct product links.', 'headlessplugin'),
                'type' => 'object',
                'context' => ['view'],
                'readonly' => true,
                'properties' => [
                    'permalink' => [
                        'type' => 'string',
                        'format' => 'uri',
                    ],
                    'add_to_cart_url' => [
                        'type' => 'string',
                        'format' => 'uri',
                    ],
                ],
            ],
            'buy_now_button' => [
                'description' => __('Metadata for the custom Buy Now button.', 'headlessplugin'),
                'type' => 'object',
                'context' => ['view'],
                'readonly' => true,
            ],
            'tax_notice' => [
                'description' => __('Additional text to display alongside prices when taxes are included.', 'headlessplugin'),
                'type' => 'string',
                'context' => ['view'],
                'readonly' => true,
            ],
            'wishlist' => [
                'description' => __('Wishlist metadata for the current shopper.', 'headlessplugin'),
                'type' => 'object',
                'context' => ['view'],
                'readonly' => true,
                'properties' => [
                    'in_wishlist' => [
                        'type' => 'boolean',
                    ],
                    'count' => [
                        'type' => 'integer',
                    ],
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'integer',
                        ],
                    ],
                    'user_id' => [
                        'type' => ['integer', 'null'],
                    ],
                    'source' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ];
    }

    public function build_store_api_extension_data($product) {
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product) {
            return [];
        }

        return [
            'links' => [
                'permalink' => $product->get_permalink(),
                'add_to_cart_url' => $this->get_popup_add_to_cart_url($product),
            ],
            'buy_now_button' => $this->get_buy_now_button_data($product),
            'tax_notice' => $this->get_tax_notice_text(),
            'wishlist' => $this->get_wishlist_payload($product->get_id()),
        ];
    }

    private function get_buy_now_button_data($product) {
        $enabled = has_action('woocommerce_after_add_to_cart_button', 'styluza_add_buy_now_button');
        $label = apply_filters('styluza_buy_now_button_label', __('BUY NOW', 'twentytwentyfive-child'), $product);

        $style = [
            'background_color' => '#4CAF50',
            'text_color' => '#FFFFFF',
            'padding' => '1rem',
            'border_radius' => '4px',
            'width' => '100%',
            'font_weight' => '600',
        ];

        $ajax = [
            'action' => 'styluza_buy_now',
            'endpoint' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('styluza-buy-now'),
            'requires_variation' => $product->is_type('variable'),
        ];

        return [
            'enabled' => (bool) $enabled,
            'label' => $label,
            'style' => $style,
            'ajax' => $ajax,
        ];
    }

    private function get_wishlist_payload($product_id) {
        $wishlist = $this->get_current_wishlist_items();
        $product_id = (int) $product_id;
        return [
            'in_wishlist' => $product_id > 0 ? in_array($product_id, $wishlist, true) : false,
            'count' => count($wishlist),
            'items' => $wishlist,
            'user_id' => get_current_user_id() ?: null,
            'source' => is_user_logged_in() ? 'user_meta' : 'session',
        ];
    }

    private function get_current_wishlist_items() {
        if (function_exists('styluza_get_wishlist')) {
            return $this->normalize_wishlist_ids(styluza_get_wishlist());
        }

        if (is_user_logged_in()) {
            $user_wishlist = get_user_meta(get_current_user_id(), 'styluza_wishlist_products', true);
            $user_wishlist = $this->normalize_wishlist_ids($user_wishlist);
            if (!empty($user_wishlist)) {
                return $user_wishlist;
            }
        }

        return $this->get_session_wishlist_items();
    }

    private function get_session_wishlist_items() {
        if (function_exists('styluza_get_session_wishlist')) {
            return $this->normalize_wishlist_ids(styluza_get_session_wishlist());
        }

        $this->ensure_wc_session_initialized();
        if (function_exists('WC') && WC() && WC()->session) {
            $session_wishlist = WC()->session->get('styluza_wishlist', []);
            return $this->normalize_wishlist_ids($session_wishlist);
        }

        return [];
    }

    private function ensure_wc_session_initialized() {
        if (function_exists('styluza_ensure_wc_session')) {
            styluza_ensure_wc_session();
            return;
        }

        if (function_exists('WC') && WC() && is_null(WC()->session) && method_exists(WC(), 'initialize_session')) {
            WC()->initialize_session();
        }
    }

    private function normalize_wishlist_ids($wishlist) {
        if (!is_array($wishlist)) {
            $wishlist = [];
        }

        $wishlist = array_filter(
            array_map('intval', $wishlist),
            function ($id) {
                return $id > 0;
            }
        );

        return array_values(array_unique($wishlist));
    }

    private function get_popup_add_to_cart_url($product, $provided_variations = null) {
        if (!$product) {
            return '';
        }

        if ($product->is_type('variable')) {
            $variations = $this->normalize_variations_collection($provided_variations);
            $url = $this->extract_variation_add_to_cart_url($variations);
            if ($url) {
                return $url;
            }

            $variations = $this->get_product_variations($product);
            $url = $this->extract_variation_add_to_cart_url($variations);
            if ($url) {
                return $url;
            }

            return $product->get_permalink();
        }

        if ($product->is_type('grouped')) {
            return $product->get_permalink();
        }

        return add_query_arg('add-to-cart', $product->get_id(), home_url('/'));
    }

    private function normalize_variations_collection($variations) {
        if ($variations instanceof WP_REST_Request) {
            $variations = (array) $variations;
        }

        if (is_object($variations)) {
            $variations = (array) $variations;
        }

        return is_array($variations) ? $variations : [];
    }

    private function extract_variation_add_to_cart_url($variations) {
        if (empty($variations) || !is_array($variations)) {
            return '';
        }

        foreach ($variations as $variation) {
            if (is_object($variation)) {
                $variation = (array) $variation;
            }

            if (!is_array($variation)) {
                continue;
            }

            if (!empty($variation['add_to_cart_url'])) {
                return $variation['add_to_cart_url'];
            }

            if (!empty($variation['add_to_cart']) && is_array($variation['add_to_cart']) && !empty($variation['add_to_cart']['url'])) {
                return $variation['add_to_cart']['url'];
            }
        }

        return '';
    }

    private function normalize_add_to_cart_payload($payload, $url, $product) {
        if (is_object($payload)) {
            $payload = (array) $payload;
        }

        if (!is_array($payload)) {
            $payload = [];
        }

        $payload['text'] = $payload['text'] ?? $product->add_to_cart_text();
        $payload['description'] = $payload['description'] ?? $product->add_to_cart_description();
        $payload['single_text'] = $payload['single_text'] ?? $product->single_add_to_cart_text();
        $payload['minimum'] = $payload['minimum'] ?? 1;
        $payload['maximum'] = $payload['maximum'] ?? 9999;
        $payload['multiple_of'] = $payload['multiple_of'] ?? 1;
        $payload['url'] = $url;

        return $payload;
    }

    public function filter_product_add_to_cart_url($url, $product) {
        $is_rest = function_exists('wp_doing_rest') ? wp_doing_rest() : (defined('REST_REQUEST') && REST_REQUEST);
        if (!$is_rest) {
            return $url;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($request_uri, '/wc/store/v1/products') === false) {
            return $url;
        }

        if (!$product || !$product->is_type('variable')) {
            return $url;
        }

        $replacement = $this->get_popup_add_to_cart_url($product);
        return $replacement ?: $url;
    }

    private function get_tax_notice_text() {
        if (wc_tax_enabled()) {
            return __('Inclusive of all taxes', 'headlessplugin');
        }
        return '';
    }

    /**
     * Get stock status text
     */
    private function get_stock_status_text($stock_status, $in_stock) {
        switch ($stock_status) {
            case 'instock':
                return 'In Stock';
            case 'outofstock':
                return 'Out of Stock';
            case 'onbackorder':
                return 'On Backorder';
            default:
                return $in_stock ? 'In Stock' : 'Out of Stock';
        }
    }
}

