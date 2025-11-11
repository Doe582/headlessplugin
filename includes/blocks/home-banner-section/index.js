// Register the Home Banner Section block using an IIFE so we do not rely on build tooling
(function () {
    'use strict';

    function registerHomeBannerBlock() {
        if (typeof wp === 'undefined' || !wp.blocks || !wp.element || !wp.components || !wp.i18n || !(wp.blockEditor || wp.editor)) {
            setTimeout(registerHomeBannerBlock, 100);
            return;
        }

        if (wp.blocks.getBlockType('restbridge/home-banner-section')) {
            return;
        }

        try {
            if (window.recommendedBlocksData && !Array.isArray(window.recommendedBlocksData.plugins)) {
                window.recommendedBlocksData.plugins = [];
            }

            const { registerBlockType } = wp.blocks;
            const { createElement: el } = wp.element;
            const { PanelBody, Button, ToggleControl, TextControl } = wp.components;
            const blockEditor = wp.blockEditor || wp.editor;

            if (!blockEditor) {
                console.warn('RESTBridge Home Banner Section: blockEditor not available yet');
                setTimeout(registerHomeBannerBlock, 100);
                return;
            }

            const { InspectorControls, MediaUpload, MediaUploadCheck, ColorPalette, InnerBlocks } = blockEditor;
            const { __ } = wp.i18n;

            const DEFAULT_COLORS = [
                { name: __('Cream', 'restbridge'), color: '#f5f5f0' },
                { name: __('White', 'restbridge'), color: '#ffffff' },
                { name: __('Light Gray', 'restbridge'), color: '#f0f0f0' },
                { name: __('Mint', 'restbridge'), color: '#d9f2e3' }
            ];

            const TEMPLATE = [
                ['core/heading', {
                    level: 1,
                    content: __('Discover Your Ideal Fusion Of Our Classic and Contemporary Styles', 'restbridge'),
                    placeholder: __('Discover your hero headline…', 'restbridge')
                }],
                ['core/paragraph', {
                    content: __('Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry\'s standard dummy text ever since the 1500s, when an unknown.', 'restbridge'),
                    placeholder: __('Add supporting description…', 'restbridge')
                }],
                ['core/buttons', {}, [
                    ['core/button', {
                        text: __('SHOP NOW', 'restbridge'),
                        url: '#',
                        className: 'restbridge-home-banner-button-inner',
                        placeholder: __('SHOP NOW', 'restbridge')
                    }]
                ]]
            ];

            registerBlockType('restbridge/home-banner-section', {
                title: __('Home Banner Section', 'restbridge'),
                icon: 'cover-image',
                category: 'design',
                description: __('A hero banner section with title, description, button, image, and discount badge.', 'restbridge'),
                keywords: [__('banner', 'restbridge'), __('hero', 'restbridge'), __('homepage', 'restbridge')],
                supports: {
                    align: ['wide', 'full'],
                    html: false,
                },
                attributes: {
                    imageId: { type: 'number', default: 0 },
                    imageUrl: { type: 'string', default: '' },
                    discountPercent: { type: 'string', default: '70' },
                    showDiscountBadge: { type: 'boolean', default: true },
                    backgroundColor: { type: 'string', default: '#f5f5f0' },
                    align: { type: 'string', default: 'full' }
                },
                edit: function (props) {
                    try {
                        const { attributes = {}, setAttributes } = props || {};
                        const { imageId = 0, imageUrl = '', discountPercent = '70', showDiscountBadge = true, backgroundColor = '#f5f5f0' } = attributes;

                        const onSelectImage = (media) => {
                            if (media && setAttributes) {
                                setAttributes({
                                    imageId: media.id || 0,
                                    imageUrl: media.url || '',
                                });
                            }
                        };

                        const onRemoveImage = () => {
                            if (setAttributes) {
                                setAttributes({ imageId: 0, imageUrl: '' });
                            }
                        };

                        return el('div', { className: 'restbridge-home-banner-editor' },
                            el(InspectorControls, {},
                                el(PanelBody, { title: __('Banner Settings', 'restbridge'), initialOpen: true },
                                    el(TextControl, {
                                        label: __('Discount Percentage', 'restbridge'),
                                        value: discountPercent,
                                        onChange: (value) => setAttributes({ discountPercent: value }),
                                        help: __('Example: 70', 'restbridge')
                                    }),
                                    el(ToggleControl, {
                                        label: __('Show Discount Badge', 'restbridge'),
                                        checked: showDiscountBadge,
                                        onChange: (value) => setAttributes({ showDiscountBadge: value })
                                    }),
                                    el('div', { className: 'restbridge-home-banner-image-upload' },
                                        el('label', { className: 'components-base-control__label' }, __('Banner Image', 'restbridge')),
                                        el(MediaUploadCheck, {},
                                            el(MediaUpload, {
                                                onSelect: onSelectImage,
                                                allowedTypes: ['image'],
                                                value: imageId,
                                                render: ({ open }) => el('div', { className: 'restbridge-home-banner-image-controls' },
                                                    imageUrl && el('div', { className: 'restbridge-home-banner-image-preview' },
                                                        el('img', { src: imageUrl, alt: __('Banner image', 'restbridge'), className: 'restbridge-home-banner-image' }),
                                                        el(Button, {
                                                            variant: 'destructive',
                                                            onClick: onRemoveImage,
                                                            className: 'restbridge-home-banner-remove-image'
                                                        }, __('Remove Image', 'restbridge'))
                                                    ),
                                                    !imageUrl && el(Button, {
                                                        onClick: open,
                                                        variant: 'primary',
                                                        className: 'restbridge-home-banner-select-image'
                                                    }, __('Select Image', 'restbridge'))
                                                )
                                            })
                                        )
                                    )
                                ),
                                el(PanelBody, { title: __('Background', 'restbridge'), initialOpen: false },
                                    el(ColorPalette, {
                                        colors: DEFAULT_COLORS,
                                        value: backgroundColor,
                                        onChange: (value) => setAttributes({ backgroundColor: value || '#f5f5f0' })
                                    })
                                )
                            ),
                            el('div', {
                                className: 'restbridge-home-banner-preview',
                                style: { backgroundColor: backgroundColor || '#f5f5f0' }
                            },
                                el('div', { className: 'restbridge-home-banner-container' },
                                    el('div', { className: 'restbridge-home-banner-content' },
                                        el('div', { className: 'restbridge-home-banner-text' },
                                            el(InnerBlocks, {
                                                template: TEMPLATE,
                                                templateLock: 'all'
                                            })
                                        )
                                    ),
                                    el('div', { className: 'restbridge-home-banner-image-wrapper' },
                                        imageUrl ? el('div', { className: 'restbridge-home-banner-image-frame' },
                                            el('img', { src: imageUrl, alt: __('Banner image', 'restbridge'), className: 'restbridge-home-banner-image' }),
                                            showDiscountBadge && discountPercent && el('div', { className: 'restbridge-home-banner-discount-badge' },
                                                el('span', { className: 'restbridge-home-banner-discount-percent' }, discountPercent + '%'),
                                                el('span', { className: 'restbridge-home-banner-discount-text' }, __('OFF', 'restbridge'))
                                            )
                                        ) : el('div', { className: 'restbridge-home-banner-image-placeholder' },
                                            __('Select an image from the sidebar panel', 'restbridge')
                                        )
                                    )
                                )
                            )
                        );
                    } catch (error) {
                        console.error('RESTBridge Home Banner Section: Error in edit function', error);
                        return el('div', { className: 'restbridge-home-banner-error' },
                            el('p', {}, __('Error loading block. Please refresh the page.', 'restbridge'))
                        );
                    }
                },
                save: function () {
                    return null;
                }
            });
        } catch (error) {
            console.error('RESTBridge Home Banner Section: Error registering block', error);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(registerHomeBannerBlock, 50);
        });
    } else {
        setTimeout(registerHomeBannerBlock, 50);
    }
})();
