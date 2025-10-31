# REST Bridge Plugin - Authentication Guide

This guide explains how to authenticate requests to the REST Bridge API endpoints that require authentication.

## Authentication Methods

WordPress REST API supports several authentication methods:

### 1. Application Passwords (Recommended for API Clients)

Application Passwords are built into WordPress 5.6+ and are the recommended method for authenticating API requests.

#### Setup:

1. **Enable Application Passwords** (if not already enabled):
   - Go to WordPress Admin → Users → Your Profile
   - Scroll down to "Application Passwords"
   - If you don't see it, add this to your `wp-config.php`:
     ```php
     define('WP_APPLICATION_PASSWORDS', true);
     ```

2. **Create an Application Password**:
   - Go to WordPress Admin → Users → Your Profile
   - Scroll to "Application Passwords"
   - Enter a name (e.g., "My API Client")
   - Click "Add New Application Password"
   - Copy the generated password (you'll only see it once!)

#### Usage:

**cURL Example:**
```bash
curl -X GET "http://localhost/wooautopublix/wp-json/restbridge/v1/users" \
  -u "your_username:generated_password"
```

**JavaScript/Fetch Example:**
```javascript
fetch('http://localhost/wooautopublix/wp-json/restbridge/v1/users', {
  method: 'GET',
  headers: {
    'Authorization': 'Basic ' + btoa('your_username:generated_password')
  }
})
.then(response => response.json())
.then(data => console.log(data));
```

**PHP Example:**
```php
$username = 'your_username';
$password = 'generated_application_password';

$response = wp_remote_get('http://localhost/wooautopublix/wp-json/restbridge/v1/users', [
    'headers' => [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
    ]
]);

$data = json_decode(wp_remote_retrieve_body($response));
```

**Python Example:**
```python
import requests
from requests.auth import HTTPBasicAuth

response = requests.get(
    'http://localhost/wooautopublix/wp-json/restbridge/v1/users',
    auth=HTTPBasicAuth('your_username', 'generated_password')
)

data = response.json()
print(data)
```

### 2. Cookie Authentication (For Logged-In Browser Users)

This works automatically if you're making requests from the same browser where you're logged into WordPress.

#### Usage:

**JavaScript (Same Domain):**
```javascript
fetch('/wp-json/restbridge/v1/users', {
  method: 'GET',
  credentials: 'include' // This sends cookies with the request
})
.then(response => response.json())
.then(data => console.log(data));
```

**cURL (with Cookie File):**
```bash
# First, login and save cookies
curl -c cookies.txt -b cookies.txt \
  -X POST "http://localhost/wooautopublix/wp-login.php" \
  -d "log=your_username&pwd=your_password&wp-submit=Log+In"

# Then use the cookies for API requests
curl -b cookies.txt \
  "http://localhost/wooautopublix/wp-json/restbridge/v1/users"
```

### 3. OAuth 2.0 / JWT Token (Requires Plugin)

If you need token-based authentication, you can install and use JWT Authentication or OAuth plugins:

**JWT Authentication for WP REST API:**
1. Install plugin: `JWT Authentication for WP REST API`
2. Configure the plugin
3. Get token via: `POST /wp-json/jwt-auth/v1/token`
4. Use token: `Authorization: Bearer {token}`

**Example:**
```bash
# Get token
TOKEN=$(curl -X POST "http://localhost/wooautopublix/wp-json/jwt-auth/v1/token" \
  -H "Content-Type: application/json" \
  -d '{"username":"your_username","password":"your_password"}' \
  | jq -r '.token')

# Use token
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost/wooautopublix/wp-json/restbridge/v1/users"
```

## Complete Examples

### Creating a Post (POST Request)

**cURL with Application Password:**
```bash
curl -X POST "http://localhost/wooautopublix/wp-json/restbridge/v1/posts" \
  -u "username:application_password" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "My New Post",
    "content": "This is the post content",
    "status": "publish"
  }'
```

**JavaScript:**
```javascript
fetch('http://localhost/wooautopublix/wp-json/restbridge/v1/posts', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Basic ' + btoa('username:application_password')
  },
  body: JSON.stringify({
    title: 'My New Post',
    content: 'This is the post content',
    status: 'publish'
  })
})
.then(response => response.json())
.then(data => console.log(data));
```

### Updating a Post (PUT Request)

```bash
curl -X PUT "http://localhost/wooautopublix/wp-json/restbridge/v1/posts/123" \
  -u "username:application_password" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Updated Post Title",
    "content": "Updated content"
  }'
```

### Adding Product to Cart (WooCommerce)

```bash
curl -X POST "http://localhost/wooautopublix/wp-json/restbridge/v1/cart/add" \
  -H "Content-Type: application/json" \
  -d '{
    "product_id": 123,
    "quantity": 2
  }'
```

Note: Cart endpoints don't require authentication but use WooCommerce session cookies.

### Uploading Media (POST Request with File)

```bash
curl -X POST "http://localhost/wooautopublix/wp-json/restbridge/v1/media" \
  -u "username:application_password" \
  -F "file=@/path/to/image.jpg" \
  -F "title=My Image"
```

## Testing Authentication

### Check if Authentication Works:

```bash
# This should return user data if authenticated
curl -u "username:application_password" \
  "http://localhost/wooautopublix/wp-json/restbridge/v1/users/me"
```

### Check Current User:

```bash
# No auth needed - uses current logged-in session
curl "http://localhost/wooautopublix/wp-json/restbridge/v1/users/me" \
  --cookie "wordpress_logged_in_xxx=your_cookie_value"
```

## Permission Requirements

Different endpoints require different permissions:

- **Posts**: `edit_posts` capability
- **Pages**: `edit_pages` capability
- **Comments**: `moderate_comments` capability
- **Categories/Tags**: `manage_categories` capability
- **Users**: `list_users` capability
- **Media**: `upload_files` capability
- **Products**: `manage_woocommerce` capability

Make sure your user account has the required permissions.

## Troubleshooting

### Error: "Sorry, you are not allowed to do that"
- Check if your user has the required capabilities
- Verify you're using the correct username/password
- Make sure you're using Application Passwords, not the account password

### Error: "Invalid username or password"
- Double-check your credentials
- Ensure Application Passwords are enabled
- Try creating a new Application Password

### Error: "Authentication failed"
- Check your Authorization header format
- Verify base64 encoding (format: `Basic base64(username:password)`)
- Test with cURL first to debug

## Security Best Practices

1. **Use Application Passwords** instead of account passwords
2. **Use HTTPS** in production to encrypt credentials
3. **Rotate Application Passwords** regularly
4. **Use least privilege** - only grant necessary permissions
5. **Store credentials securely** - never commit to version control
6. **Use environment variables** for credentials in production

