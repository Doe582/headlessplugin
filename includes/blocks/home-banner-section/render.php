<?php
/**
 * Home Banner Section Block - Server-side render
 *
 * @var array $attributes Block attributes
 * @var string $content Block content
 * @var WP_Block $block Block instance
 */

$title = isset($attributes['title']) ? $attributes['title'] : '';
$description = isset($attributes['description']) ? $attributes['description'] : '';
$button_text = isset($attributes['buttonText']) ? $attributes['buttonText'] : '';
$button_url = isset($attributes['buttonUrl']) ? $attributes['buttonUrl'] : '#';
$image_id = $attributes['imageId'] ?? 0;
$image_url = $attributes['imageUrl'] ?? '';
$discount_percent = $attributes['discountPercent'] ?? '70';
$show_discount = $attributes['showDiscountBadge'] ?? true;
$background_color = $attributes['backgroundColor'] ?? '#f5f5f0';

if (isset($block) && $block instanceof WP_Block) {
    $parsed_inner_blocks = $block->parsed_block['innerBlocks'] ?? [];
    foreach ($parsed_inner_blocks as $inner_block) {
        if (($inner_block['blockName'] ?? '') === 'core/group') {
            $nested_inner = $inner_block['innerBlocks'] ?? [];
            $parsed_inner_blocks = array_merge($parsed_inner_blocks, $nested_inner);
        }
    }

    foreach ($parsed_inner_blocks as $inner_block) {
        $block_name = $inner_block['blockName'] ?? '';
        $attrs = $inner_block['attrs'] ?? [];

        if ($block_name === 'core/heading' && $title === '') {
            $raw_heading = $attrs['content'] ?? ($inner_block['innerHTML'] ?? '');
            if ($raw_heading !== '') {
                $title = wp_strip_all_tags($raw_heading);
            }
        } elseif ($block_name === 'core/paragraph' && $description === '') {
            $raw_paragraph = $attrs['content'] ?? ($inner_block['innerHTML'] ?? '');
            if ($raw_paragraph !== '') {
                $description = wp_strip_all_tags($raw_paragraph);
            }
        } elseif ($block_name === 'core/buttons' && !empty($inner_block['innerBlocks'])) {
            foreach ($inner_block['innerBlocks'] as $button_block) {
                if (($button_block['blockName'] ?? '') === 'core/button') {
                    $button_attrs = $button_block['attrs'] ?? [];
                    if ($button_text === '') {
                        if (!empty($button_attrs['text'])) {
                            $button_text = wp_strip_all_tags($button_attrs['text']);
                        } elseif (!empty($button_attrs['content'])) {
                            $button_text = wp_strip_all_tags($button_attrs['content']);
                        } elseif (!empty($button_block['innerHTML'])) {
                            $button_text = wp_strip_all_tags($button_block['innerHTML']);
                        }
                    }
                    if (!empty($button_attrs['url'])) {
                        $button_url = esc_url_raw($button_attrs['url']);
                    } elseif (!empty($button_attrs['link']['url'])) {
                        $button_url = esc_url_raw($button_attrs['link']['url']);
                    } elseif (!empty($button_block['innerHTML'])) {
                        if (preg_match('/href=["\']([^"\']+)["\']/', $button_block['innerHTML'], $match)) {
                            $button_url = esc_url_raw($match[1]);
                        }
                    }
                    break;
                }
            }
        }
    }
}

if ($title === '') {
    $title = 'Discover Your Ideal Fusion Of Our Classic and Contemporary Styles';
}

if ($description === '') {
    $description = 'Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry\'s standard dummy text ever since the 1500s, when an unknown.';
}

if ($button_text === '') {
    $button_text = 'SHOP NOW';
}

if ($button_url === '') {
    $button_url = '#';
}

// Get image URL if image ID is provided
if ($image_id > 0 && empty($image_url)) {
    $image_url = wp_get_attachment_image_url($image_id, 'full');
}

$background_color = sanitize_hex_color($background_color) ?: '#f5f5f0';

// Get wrapper attributes with Section Wrapper layout classes
$wrapper_attributes = get_block_wrapper_attributes([
    'class' => 'wp-block-group restbridge-home-banner-section',
    'style' => 'background-color: ' . esc_attr($background_color) . ';',
    'data-layout' => wp_json_encode(['type' => 'constrained'])
]);
?>

<div <?php echo $wrapper_attributes; ?>>
    <div class="wp-block-group__inner-container restbridge-home-banner-container">
        <div class="restbridge-home-banner-content">
            <div class="restbridge-home-banner-text">
                <div class="restbridge-home-banner-text-content">
                    <h1 class="restbridge-home-banner-title"><?php echo esc_html($title); ?></h1>
                    <p class="restbridge-home-banner-description"><?php echo esc_html($description); ?></p>
                    <a href="<?php echo esc_url($button_url); ?>" class="restbridge-home-banner-button">
                        <?php echo esc_html($button_text); ?> →
                    </a>
                </div>
            </div>
        </div>
        
        <div class="restbridge-home-banner-image-wrapper">
            <?php if (!empty($image_url)): ?>
                <div class="restbridge-home-banner-image-frame">
                    <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($title); ?>" class="restbridge-home-banner-image" />
                    <?php if ($show_discount && !empty($discount_percent)): ?>
                        <div class="restbridge-home-banner-badge">
                            <span class="restbridge-home-banner-badge-percent"><?php echo esc_html($discount_percent); ?>%</span>
                            <span class="restbridge-home-banner-badge-text">OFF</span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="restbridge-home-banner-placeholder">
                    <p>Select an image in the block settings</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

