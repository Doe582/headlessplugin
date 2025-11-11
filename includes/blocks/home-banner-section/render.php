<?php
/**
 * Home Banner Section Block - Server-side render
 */

$image_id = $attributes['imageId'] ?? 0;
$image_url = $attributes['imageUrl'] ?? '';
$discount_percent = $attributes['discountPercent'] ?? '70';
$show_discount = $attributes['showDiscountBadge'] ?? true;
$background_color = $attributes['backgroundColor'] ?? '#f5f5f0';

if ($image_id > 0 && empty($image_url)) {
    $image_url = wp_get_attachment_image_url($image_id, 'full');
}

$background_color = sanitize_hex_color($background_color) ?: '#f5f5f0';

$inner_content = '';
if (!empty($block->inner_blocks)) {
    foreach ($block->inner_blocks as $inner_block) {
        if (method_exists($inner_block, 'render')) {
            $inner_content .= $inner_block->render();
        } elseif (isset($inner_block->parsed_block)) {
            $inner_content .= render_block($inner_block->parsed_block);
        }
    }
}

if (!$inner_content) {
    $legacy_title = $attributes['title'] ?? '';
    $legacy_description = $attributes['description'] ?? '';
    $legacy_button_text = $attributes['buttonText'] ?? '';
    $legacy_button_url = $attributes['buttonUrl'] ?? '';

    if ($legacy_title || $legacy_description || $legacy_button_text) {
        if ($legacy_title) {
            $inner_content .= '<h1 class="wp-block-heading">' . esc_html($legacy_title) . '</h1>'; }
        if ($legacy_description) {
            $inner_content .= '<p>' . esc_html($legacy_description) . '</p>'; }
        if ($legacy_button_text) {
            $inner_content .= '<div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link" href="' . esc_url($legacy_button_url ?: '#') . '">' . esc_html($legacy_button_text) . '</a></div></div>';
        }
    } else {
        $inner_content = $content;
    }
}

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
                <?php echo $inner_content; ?>
            </div>
        </div>

        <div class="restbridge-home-banner-image-wrapper">
            <?php if (!empty($image_url)): ?>
                <div class="restbridge-home-banner-image-frame">
                    <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr__('Banner image', 'restbridge'); ?>" class="restbridge-home-banner-image" />
                    <?php if ($show_discount && !empty($discount_percent)): ?>
                        <div class="restbridge-home-banner-badge">
                            <span class="restbridge-home-banner-badge-percent"><?php echo esc_html($discount_percent); ?>%</span>
                            <span class="restbridge-home-banner-badge-text"><?php esc_html_e('OFF', 'restbridge'); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="restbridge-home-banner-placeholder">
                    <p><?php esc_html_e('Select an image in the block settings', 'restbridge'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
