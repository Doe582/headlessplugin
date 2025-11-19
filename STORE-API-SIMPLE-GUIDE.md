# WooCommerce Store API - Simple Guide

## Overview

WooCommerce Store API works out of the box. No custom code needed! Just use the default endpoints.

---

## API Endpoints

All endpoints are under `/wp-json/wc/store/v1/`:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/wp-json/wc/store/v1/cart` | GET | Get cart contents |
| `/wp-json/wc/store/v1/cart/add-item` | POST | Add product to cart |
| `/wp-json/wc/store/v1/cart/update-item` | POST | Update cart item quantity |
| `/wp-json/wc/store/v1/cart/remove-item` | POST | Remove item from cart |
| `/wp-json/wc/store/v1/cart/apply-coupon` | POST | Apply coupon code |
| `/wp-json/wc/store/v1/cart/remove-coupon` | POST | Remove coupon |

---

## How It Works

### Guest Users (Non-Logged-In)
- ✅ **No authentication needed**
- ✅ **Cookies handled automatically** - WooCommerce creates `woocommerce_session_*` cookie
- ✅ **Session stored in database** - `wp_woocommerce_sessions` table
- ✅ **Just send cookies** - Use `credentials: 'include'` in fetch

### Logged-In Users
- ✅ **WordPress cookies** - Standard WordPress authentication cookies
- ✅ **Persistent cart** - Automatically saved in user meta
- ✅ **No Bearer token needed** - Just use WordPress login cookies

---

## Quick Examples

### Get Cart

```javascript
fetch('/wp-json/wc/store/v1/cart', {
  credentials: 'include' // CRITICAL: Must send cookies
})
.then(response => response.json())
.then(cart => {
  console.log('Cart items:', cart.items);
  console.log('Total:', cart.totals.total_price);
});
```

### Add to Cart (Simple Product)

```javascript
fetch('/wp-json/wc/store/v1/cart/add-item', {
  method: 'POST',
  credentials: 'include', // CRITICAL: Must send cookies
  headers: {
    'Content-Type': 'application/json',
    'X-WC-Store-API-Nonce': nonce // Get nonce from WordPress
  },
  body: JSON.stringify({
    id: 123,
    quantity: 1
  })
})
.then(response => response.json())
.then(data => console.log('Added:', data));
```

### Add to Cart (Variable Product)

```javascript
fetch('/wp-json/wc/store/v1/cart/add-item', {
  method: 'POST',
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json',
    'X-WC-Store-API-Nonce': nonce
  },
  body: JSON.stringify({
    id: 123,
    quantity: 1,
    variation: [
      { attribute: 'pa_color', value: 'blue' },
      { attribute: 'pa_size', value: 'medium' }
    ]
  })
})
.then(response => response.json())
.then(data => console.log('Added:', data));
```

### Update Cart Item

```javascript
fetch('/wp-json/wc/store/v1/cart/update-item', {
  method: 'POST',
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json',
    'X-WC-Store-API-Nonce': nonce
  },
  body: JSON.stringify({
    key: 'cart-item-key-here',
    quantity: 2
  })
})
.then(response => response.json())
.then(data => console.log('Updated:', data));
```

### Remove Cart Item

```javascript
fetch('/wp-json/wc/store/v1/cart/remove-item', {
  method: 'POST',
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json',
    'X-WC-Store-API-Nonce': nonce
  },
  body: JSON.stringify({
    key: 'cart-item-key-here'
  })
})
.then(response => response.json())
.then(data => console.log('Removed:', data));
```

---

## Getting the Nonce

The Store API requires a nonce for security. Get it from WordPress:

### Option 1: From PHP (if you have access)
```php
$nonce = wp_create_nonce('wc_store_api');
```

### Option 2: From JavaScript (if available in your theme)
```javascript
const nonce = window.wcStoreApiNonce;
```

### Option 3: Extract from cart response
```javascript
// Some WordPress setups include nonce in response
const response = await fetch('/wp-json/wc/store/v1/cart', {
  credentials: 'include'
});
// Check response headers or data for nonce
```

---

## Important Points

1. ✅ **Always send cookies** - Use `credentials: 'include'` in fetch or `withCredentials: true` in axios
2. ✅ **Nonce required** - Get from WordPress (see above)
3. ✅ **No custom code needed** - WooCommerce handles everything automatically
4. ✅ **Guest users** - Just use cookies, no authentication
5. ✅ **Logged-in users** - Use WordPress login cookies, no Bearer token needed

---

## Testing with cURL

### Guest User

```bash
# Get cart (creates session and cookie)
curl -X GET "http://localhost/styluzawebtx/wp-json/wc/store/v1/cart" \
  -H "X-WC-Store-API-Nonce: <nonce>" \
  -c cookies.txt

# Add to cart
curl -X POST "http://localhost/styluzawebtx/wp-json/wc/store/v1/cart/add-item" \
  -H "Content-Type: application/json" \
  -H "X-WC-Store-API-Nonce: <nonce>" \
  -b cookies.txt \
  -c cookies.txt \
  -d '{"id": 123, "quantity": 1}'

# Get cart again
curl -X GET "http://localhost/styluzawebtx/wp-json/wc/store/v1/cart" \
  -H "X-WC-Store-API-Nonce: <nonce>" \
  -b cookies.txt
```

### Logged-In User

```bash
# First, login via WordPress to get auth cookies
# Then use those cookies with Store API

curl -X GET "http://localhost/styluzawebtx/wp-json/wc/store/v1/cart" \
  -H "X-WC-Store-API-Nonce: <nonce>" \
  -b "wordpress_logged_in_<hash>=<value>; woocommerce_session_<hash>=<value>"
```

---

## Session Management

### Guest Users
- Session stored in `wp_woocommerce_sessions` table
- Cookie: `woocommerce_session_<hash>`
- Automatically created on first request
- Persists via cookie

### Logged-In Users
- WordPress auth cookie: `wordpress_logged_in_<hash>`
- WooCommerce session cookie: `woocommerce_session_<hash>`
- Persistent cart in user meta: `_woocommerce_persistent_cart_{blog_id}`
- Automatically synced

---

## That's It!

WooCommerce Store API is ready to use. No custom code needed. Just:
1. Send cookies with requests (`credentials: 'include'`)
2. Include nonce in headers (`X-WC-Store-API-Nonce`)
3. Use the endpoints directly

Everything else is handled automatically by WooCommerce!

