# Cart API Documentation

All cart endpoints are available under `/wp-json/restbridge/v1/cart`

## Endpoints Overview

| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| GET | `/cart` | Get current cart contents | No |
| POST | `/cart/add` | Add product to cart | No |
| PUT | `/cart/update` | Update cart item quantity | No |
| POST | `/cart/remove` | Remove item from cart | No |
| POST | `/cart/clear` | Clear entire cart | No |

---

## 1. Get Cart

**GET** `/wp-json/restbridge/v1/cart`

Returns the current cart contents with totals.

### Parameters
None

### Response Example
```json
{
  "items": [
    {
      "key": "a1b2c3d4e5f6",
      "product_id": 123,
      "name": "Product Name",
      "quantity": 2,
      "price": "29.99",
      "line_total": "59.98",
      "line_subtotal": "59.98"
    }
  ],
  "cart_total": "59.98",
  "cart_subtotal": "59.98",
  "cart_count": 2
}
```

### cURL Example
```bash
curl "http://localhost/wooautopublix/wp-json/restbridge/v1/cart"
```

---

## 2. Add to Cart

**POST** `/wp-json/restbridge/v1/cart/add`

Adds a product to the cart.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `product_id` | integer | Yes | The product ID to add |
| `quantity` | integer | No | Quantity to add (default: 1) |
| `variation_id` | integer | No | Variation ID if adding a variable product |
| `variation` | object | No | Variation attributes (for variable products) |

### Request Body Example
```json
{
  "product_id": 123,
  "quantity": 2
}
```

### Variable Product Example
```json
{
  "product_id": 123,
  "variation_id": 456,
  "quantity": 1,
  "variation": {
    "color": "red",
    "size": "large"
  }
}
```

### Response Example
```json
{
  "success": true,
  "message": "Product added to cart",
  "cart": {
    "items": [...],
    "cart_total": "59.98",
    "cart_subtotal": "59.98",
    "cart_count": 2
  }
}
```

### cURL Examples
```bash
# Add simple product
curl -X POST "http://localhost/wooautopublix/wp-json/restbridge/v1/cart/add" \
  -H "Content-Type: application/json" \
  -d '{
    "product_id": 123,
    "quantity": 2
  }'

# Add variable product
curl -X POST "http://localhost/wooautopublix/wp-json/restbridge/v1/cart/add" \
  -H "Content-Type: application/json" \
  -d '{
    "product_id": 123,
    "variation_id": 456,
    "quantity": 1,
    "variation": {
      "color": "red",
      "size": "large"
    }
  }'
```

### Error Responses
```json
{
  "code": "missing_product_id",
  "message": "Product ID is required",
  "data": {
    "status": 400
  }
}
```

```json
{
  "code": "add_failed",
  "message": "Failed to add product to cart",
  "data": {
    "status": 400
  }
}
```

---

## 3. Update Cart Item

**PUT** `/wp-json/restbridge/v1/cart/update`

Updates the quantity of a cart item.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `key` | string | Yes | Cart item key (from get_cart response) |
| `quantity` | integer | Yes | New quantity (use 0 to remove) |

### Request Body Example
```json
{
  "key": "a1b2c3d4e5f6",
  "quantity": 3
}
```

### Response Example
```json
{
  "success": true,
  "message": "Cart updated",
  "cart": {
    "items": [...],
    "cart_total": "89.97",
    "cart_subtotal": "89.97",
    "cart_count": 3
  }
}
```

### cURL Example
```bash
curl -X PUT "http://localhost/wooautopublix/wp-json/restbridge/v1/cart/update" \
  -H "Content-Type: application/json" \
  -d '{
    "key": "a1b2c3d4e5f6",
    "quantity": 3
  }'
```

### Error Responses
```json
{
  "code": "missing_key",
  "message": "Cart item key is required",
  "data": {
    "status": 400
  }
}
```

---

## 4. Remove from Cart

**POST** `/wp-json/restbridge/v1/cart/remove`

Removes an item from the cart.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `key` | string | Yes* | Cart item key (use this OR product_id) |
| `product_id` | integer | Yes* | Product ID (use this OR key) |

*Either `key` or `product_id` is required

### Request Body Examples

**Using cart item key:**
```json
{
  "key": "a1b2c3d4e5f6"
}
```

**Using product ID:**
```json
{
  "product_id": 123
}
```

### Response Example
```json
{
  "success": true,
  "message": "Item removed from cart",
  "cart": {
    "items": [...],
    "cart_total": "29.99",
    "cart_subtotal": "29.99",
    "cart_count": 1
  }
}
```

### cURL Examples
```bash
# Remove by key
curl -X POST "http://localhost/wooautopublix/wp-json/restbridge/v1/cart/remove" \
  -H "Content-Type: application/json" \
  -d '{
    "key": "a1b2c3d4e5f6"
  }'

# Remove by product ID
curl -X POST "http://localhost/wooautopublix/wp-json/restbridge/v1/cart/remove" \
  -H "Content-Type: application/json" \
  -d '{
    "product_id": 123
  }'
```

### Error Responses
```json
{
  "code": "missing_parameter",
  "message": "Either key or product_id is required",
  "data": {
    "status": 400
  }
}
```

---

## 5. Clear Cart

**POST** `/wp-json/restbridge/v1/cart/clear`

Removes all items from the cart.

### Parameters
None

### Response Example
```json
{
  "success": true,
  "message": "Cart cleared",
  "cart": {
    "items": [],
    "cart_total": "0.00",
    "cart_subtotal": "0.00",
    "cart_count": 0
  }
}
```

### cURL Example
```bash
curl -X POST "http://localhost/wooautopublix/wp-json/restbridge/v1/cart/clear"
```

---

## Response Fields Explained

### Cart Item Object
```json
{
  "key": "unique_cart_item_key",      // Unique identifier for this cart item
  "product_id": 123,                   // Product ID
  "name": "Product Name",              // Product name
  "quantity": 2,                       // Quantity in cart
  "price": "29.99",                    // Unit price
  "line_total": "59.98",              // Total for this line (quantity × price)
  "line_subtotal": "59.98"            // Subtotal before discounts
}
```

### Cart Totals
```json
{
  "items": [...],                      // Array of cart items
  "cart_total": "59.98",              // Grand total (including tax, shipping, discounts)
  "cart_subtotal": "59.98",          // Subtotal of all items
  "cart_count": 2                      // Total number of items in cart
}
```

---

## JavaScript/Fetch Examples

### Get Cart
```javascript
fetch('http://localhost/wooautopublix/wp-json/restbridge/v1/cart')
  .then(response => response.json())
  .then(data => console.log(data));
```

### Add to Cart
```javascript
fetch('http://localhost/wooautopublix/wp-json/restbridge/v1/cart/add', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    product_id: 123,
    quantity: 2
  })
})
.then(response => response.json())
.then(data => console.log(data));
```

### Update Cart
```javascript
fetch('http://localhost/wooautopublix/wp-json/restbridge/v1/cart/update', {
  method: 'PUT',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    key: 'a1b2c3d4e5f6',
    quantity: 3
  })
})
.then(response => response.json())
.then(data => console.log(data));
```

### Remove from Cart
```javascript
fetch('http://localhost/wooautopublix/wp-json/restbridge/v1/cart/remove', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    product_id: 123
  })
})
.then(response => response.json())
.then(data => console.log(data));
```

### Clear Cart
```javascript
fetch('http://localhost/wooautopublix/wp-json/restbridge/v1/cart/clear', {
  method: 'POST'
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## Python Examples

### Get Cart
```python
import requests

response = requests.get('http://localhost/wooautopublix/wp-json/restbridge/v1/cart')
print(response.json())
```

### Add to Cart
```python
import requests

response = requests.post(
    'http://localhost/wooautopublix/wp-json/restbridge/v1/cart/add',
    json={
        'product_id': 123,
        'quantity': 2
    }
)
print(response.json())
```

---

## PHP Examples

### Get Cart
```php
$response = wp_remote_get('http://localhost/wooautopublix/wp-json/restbridge/v1/cart');
$cart = json_decode(wp_remote_retrieve_body($response), true);
print_r($cart);
```

### Add to Cart
```php
$response = wp_remote_post('http://localhost/wooautopublix/wp-json/restbridge/v1/cart/add', [
    'headers' => [
        'Content-Type' => 'application/json',
    ],
    'body' => json_encode([
        'product_id' => 123,
        'quantity' => 2
    ])
]);
$result = json_decode(wp_remote_retrieve_body($response), true);
print_r($result);
```

---

## Notes

1. **Cart Session**: Cart uses WooCommerce session cookies. Ensure cookies are handled in API requests.

2. **No Authentication**: All cart endpoints are public (no authentication required) as they use session-based cart management.

3. **Cart Persistence**: The cart persists via WooCommerce sessions. Make sure cookies are sent with requests.

4. **Error Handling**: Always check for error responses:
   ```json
   {
     "code": "error_code",
     "message": "Error message",
     "data": {"status": 400}
   }
   ```

5. **Quantity Validation**: 
   - Quantity must be positive integer
   - Setting quantity to 0 will remove the item
   - Cannot exceed product stock quantity

6. **Variable Products**: When adding variable products, always include `variation_id` and optionally `variation` attributes.

---

## Quick Reference

**Base URL**: `http://localhost/wooautopublix/wp-json/restbridge/v1`

```
GET    /cart              - Get cart
POST   /cart/add          - Add product
PUT    /cart/update       - Update quantity
POST   /cart/remove       - Remove item
POST   /cart/clear        - Clear cart
```

