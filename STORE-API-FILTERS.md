# WooCommerce Store API Filters Documentation

This plugin extends the WooCommerce Store API (`wp-json/wc/store/v1/products`) with custom filtering capabilities for product listings.

## Supported Filters

### Taxonomy Filters

All taxonomy filters accept comma-separated term IDs, slugs, or names (case-insensitive). The filter will try to match in this order: term ID → slug → name.

#### Color Filter
Filter products by color taxonomy.

**Parameter:** `color`

**Accepts:** Term IDs (numeric), term slugs, or term names (case-insensitive)

**Example:**
```
GET /wp-json/wc/store/v1/products?color=Red,Blue
GET /wp-json/wc/store/v1/products?color=red,blue
GET /wp-json/wc/store/v1/products?color=1,2,3
```

#### Size Filter
Filter products by size taxonomy.

**Parameter:** `size`

**Accepts:** Term IDs (numeric), term slugs, or term names (case-insensitive)

**Example:**
```
GET /wp-json/wc/store/v1/products?size=Small,Medium,Large
GET /wp-json/wc/store/v1/products?size=small,medium,large
GET /wp-json/wc/store/v1/products?size=5,6,7
```

#### Region Filter
Filter products by region taxonomy.

**Parameter:** `region`

**Accepts:** Term IDs (numeric), term slugs, or term names (case-insensitive)

**Example:**
```
GET /wp-json/wc/store/v1/products?region=Rajasthan,Gujarat
GET /wp-json/wc/store/v1/products?region=rajasthan,gujarat
GET /wp-json/wc/store/v1/products?region=10,11
```

#### Fabric Filter
Filter products by fabric taxonomy.

**Parameter:** `fabric`

**Accepts:** Term IDs (numeric), term slugs, or term names (case-insensitive)

**Example:**
```
GET /wp-json/wc/store/v1/products?fabric=Silk,Cotton
GET /wp-json/wc/store/v1/products?fabric=silk,cotton
GET /wp-json/wc/store/v1/products?fabric=20,21
```

### Stock Status Filter

Filter products by stock availability.

**Parameter:** `stock_status`

**Values:** `instock`, `outofstock`, `onbackorder`

**Example:**
```
GET /wp-json/wc/store/v1/products?stock_status=instock
```

### On Sale Filter

Filter products that are currently on sale.

**Parameter:** `on_sale`

**Values:** `true`, `1`, `false`, `0`

**Example:**
```
GET /wp-json/wc/store/v1/products?on_sale=true
```

### Price Range Filters

Filter products by price range.

**Parameters:** `min_price`, `max_price`

**Example:**
```
GET /wp-json/wc/store/v1/products?min_price=1000&max_price=50000
GET /wp-json/wc/store/v1/products?min_price=0
GET /wp-json/wc/store/v1/products?max_price=10000
```

### Category Filter

Filter products by category (if not already handled by Store API).

**Parameter:** `category`

**Example:**
```
GET /wp-json/wc/store/v1/products?category=sarees,lehengas
GET /wp-json/wc/store/v1/products?category=1,2,3
```

## Combining Multiple Filters

You can combine multiple filters in a single request. All filters are combined with AND logic.

**Example:**
```
GET /wp-json/wc/store/v1/products?color=red&size=M&fabric=silk&min_price=1000&max_price=50000&stock_status=instock&on_sale=true
```

## Taxonomy Naming

The plugin automatically detects taxonomy names using the following conventions (in order of priority):

1. `pa_{name}` - WooCommerce product attribute format (e.g., `pa_color`, `pa_size`)
2. `product_{name}` - Custom taxonomy format (e.g., `product_color`, `product_size`)
3. `{name}` - Direct name (e.g., `color`, `size`)

## Complete Example

Here's a complete example URL with all filters:

```
GET /wp-json/wc/store/v1/products?color=red,blue&size=M,L&region=rajasthan&fabric=silk,cotton&min_price=1000&max_price=50000&stock_status=instock&on_sale=true&per_page=12&page=1
```

## Sort Options

The API response includes all available sort options that can be used for product listings. These options are returned in the `sort_options` field of the response.

**Response Field:** `sort_options`

**Format:** Array of objects with `value` and `label` properties

**Available Sort Options:**
- `menu_order` - Default sorting (as set in admin)
- `popularity` - Sort by popularity
- `rating` - Sort by average rating
- `date` - Sort by latest (newest first)
- `price` - Sort by price: low to high
- `price-desc` - Sort by price: high to low
- `featured` - Sort by featured products (custom option)

**Example Response:**
```json
{
  "sort_options": [
    {
      "value": "menu_order",
      "label": "Default sorting"
    },
    {
      "value": "popularity",
      "label": "Sort by popularity"
    },
    {
      "value": "rating",
      "label": "Sort by average rating"
    },
    {
      "value": "date",
      "label": "Sort by latest"
    },
    {
      "value": "price",
      "label": "Sort by price: low to high"
    },
    {
      "value": "price-desc",
      "label": "Sort by price: high to low"
    },
    {
      "value": "featured",
      "label": "Sort by Featured"
    }
  ]
}
```

**Note:** The `featured` sort option is a custom addition that shows featured products first, sorted by date (newest first) within the featured group.

## Response Format

The response follows the standard WooCommerce Store API format with additional fields:

```json
{
  "products": [
    {
      "id": 123,
      "name": "Product Name",
      "price": "1999.00",
      ...
    }
  ],
  "sort_options": [
    {
      "value": "menu_order",
      "label": "Default sorting"
    },
    ...
  ]
}
```

**Note:** For product listing endpoints, the response includes both the `products` array and the `sort_options` array. The `sort_options` field contains all available sorting options including the custom "featured" option.

## Notes

- All taxonomy filters support both term IDs (numeric) and slugs (text)
- Multiple values in taxonomy filters are comma-separated
- Price filters use the product's `_price` meta field
- Stock status filter checks the `_stock_status` meta field
- On sale filter uses WooCommerce's built-in `wc_get_product_ids_on_sale()` function
- All filters are optional and can be used independently or in combination
- Sort options are automatically included in all product listing responses
- The `featured` sort option is a custom addition that filters and sorts featured products

