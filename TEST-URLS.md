# REST Bridge Plugin - Test URLs

Replace `your-domain.com` with your actual WordPress domain.

## WordPress Core Endpoints

### Posts
- **List posts:** `GET /wp-json/restbridge/v1/posts`
- **Single post:** `GET /wp-json/restbridge/v1/posts/{id}`
- **Create post:** `POST /wp-json/restbridge/v1/posts` (requires auth)
- **Update post:** `PUT /wp-json/restbridge/v1/posts/{id}` (requires auth)
- **Delete post:** `DELETE /wp-json/restbridge/v1/posts/{id}` (requires auth)

**Query parameters:**
- `per_page` - Number of items per page (default: 10)
- `page` - Page number (default: 1)
- `search` - Search term
- `category` - Category ID
- `orderby` - Order by field (date, title, etc.)
- `order` - ASC or DESC

### Pages
- **List pages:** `GET /wp-json/restbridge/v1/pages`
- **Single page:** `GET /wp-json/restbridge/v1/pages/{id}`
- **Create page:** `POST /wp-json/restbridge/v1/pages` (requires auth)
- **Update page:** `PUT /wp-json/restbridge/v1/pages/{id}` (requires auth)
- **Delete page:** `DELETE /wp-json/restbridge/v1/pages/{id}` (requires auth)

### Comments
- **List comments:** `GET /wp-json/restbridge/v1/comments`
- **Single comment:** `GET /wp-json/restbridge/v1/comments/{id}`
- **Create comment:** `POST /wp-json/restbridge/v1/comments` (public)
- **Update comment:** `PUT /wp-json/restbridge/v1/comments/{id}` (requires auth)
- **Delete comment:** `DELETE /wp-json/restbridge/v1/comments/{id}` (requires auth)

**Query parameters:**
- `post` - Filter by post ID
- `parent` - Filter by parent comment ID
- `status` - Comment status (approve, hold, spam, trash)

### Categories
- **List categories:** `GET /wp-json/restbridge/v1/categories`
- **Single category:** `GET /wp-json/restbridge/v1/categories/{id}`
- **Create category:** `POST /wp-json/restbridge/v1/categories` (requires auth)
- **Update category:** `PUT /wp-json/restbridge/v1/categories/{id}` (requires auth)
- **Delete category:** `DELETE /wp-json/restbridge/v1/categories/{id}` (requires auth)

### Tags
- **List tags:** `GET /wp-json/restbridge/v1/tags`
- **Single tag:** `GET /wp-json/restbridge/v1/tags/{id}`
- **Create tag:** `POST /wp-json/restbridge/v1/tags` (requires auth)
- **Update tag:** `PUT /wp-json/restbridge/v1/tags/{id}` (requires auth)
- **Delete tag:** `DELETE /wp-json/restbridge/v1/tags/{id}` (requires auth)

### Users
- **List users:** `GET /wp-json/restbridge/v1/users` (requires auth)
- **Single user:** `GET /wp-json/restbridge/v1/users/{id}` (requires auth)
- **Current user:** `GET /wp-json/restbridge/v1/users/me`
- **Create user:** `POST /wp-json/restbridge/v1/users` (requires auth)
- **Update user:** `PUT /wp-json/restbridge/v1/users/{id}` (requires auth)
- **Delete user:** `DELETE /wp-json/restbridge/v1/users/{id}` (requires auth)

### Media
- **List media:** `GET /wp-json/restbridge/v1/media`
- **Single media:** `GET /wp-json/restbridge/v1/media/{id}`
- **Upload media:** `POST /wp-json/restbridge/v1/media` (requires auth, multipart/form-data)
- **Update media:** `PUT /wp-json/restbridge/v1/media/{id}` (requires auth)
- **Delete media:** `DELETE /wp-json/restbridge/v1/media/{id}` (requires auth)

**Query parameters:**
- `mime_type` - Filter by MIME type (e.g., image/jpeg)
- `author` - Filter by author ID

### Taxonomies
- **List taxonomies:** `GET /wp-json/restbridge/v1/taxonomies`
- **Single taxonomy:** `GET /wp-json/restbridge/v1/taxonomies/{taxonomy_name}`
- **List taxonomy terms:** `GET /wp-json/restbridge/v1/taxonomies/{taxonomy_name}/terms`
- **Single term:** `GET /wp-json/restbridge/v1/taxonomies/{taxonomy_name}/terms/{id}`
- **Create term:** `POST /wp-json/restbridge/v1/taxonomies/{taxonomy_name}/terms` (requires auth)
- **Update term:** `PUT /wp-json/restbridge/v1/taxonomies/{taxonomy_name}/terms/{id}` (requires auth)
- **Delete term:** `DELETE /wp-json/restbridge/v1/taxonomies/{taxonomy_name}/terms/{id}` (requires auth)

### Custom Post Types (Dynamic)
Automatically registers routes for all custom post types with `show_in_rest` enabled.

- **List:** `GET /wp-json/restbridge/v1/{post_type_name}`
- **Single:** `GET /wp-json/restbridge/v1/{post_type_name}/{id}`
- **Create:** `POST /wp-json/restbridge/v1/{post_type_name}` (requires auth)
- **Update:** `PUT /wp-json/restbridge/v1/{post_type_name}/{id}` (requires auth)
- **Delete:** `DELETE /wp-json/restbridge/v1/{post_type_name}/{id}` (requires auth)

**Example:** If you have a custom post type `event`:
- `GET /wp-json/restbridge/v1/event`
- `GET /wp-json/restbridge/v1/event/123`

## WooCommerce Endpoints

### Products
- **List products:** `GET /wp-json/restbridge/v1/products`
- **Single product:** `GET /wp-json/restbridge/v1/products/{id}`
- **Create product:** `POST /wp-json/restbridge/v1/products` (requires auth)
- **Update product:** `PUT /wp-json/restbridge/v1/products/{id}` (requires auth)
- **Delete product:** `DELETE /wp-json/restbridge/v1/products/{id}` (requires auth)

**Query parameters:**
- `per_page` - Number of items per page
- `page` - Page number
- `search` - Search term
- `category` - Product category slug
- `status` - Product status

### Cart
- **Get cart:** `GET /wp-json/restbridge/v1/cart`
- **Add to cart:** `POST /wp-json/restbridge/v1/cart/add`
  ```json
  {
    "product_id": 123,
    "quantity": 2
  }
  ```
- **Update cart item:** `PUT /wp-json/restbridge/v1/cart/update`
  ```json
  {
    "key": "cart_item_key",
    "quantity": 3
  }
  ```
- **Remove from cart:** `POST /wp-json/restbridge/v1/cart/remove`
  ```json
  {
    "key": "cart_item_key"
  }
  ```
  OR
  ```json
  {
    "product_id": 123
  }
  ```
- **Clear cart:** `POST /wp-json/restbridge/v1/cart/clear`

## Example cURL Commands

```bash
# Get all posts
curl https://your-domain.com/wp-json/restbridge/v1/posts

# Get posts with pagination
curl "https://your-domain.com/wp-json/restbridge/v1/posts?per_page=5&page=2"

# Get single post
curl https://your-domain.com/wp-json/restbridge/v1/posts/1

# Get all products
curl https://your-domain.com/wp-json/restbridge/v1/products

# Get cart (requires WooCommerce session)
curl https://your-domain.com/wp-json/restbridge/v1/cart

# Add to cart
curl -X POST https://your-domain.com/wp-json/restbridge/v1/cart/add \
  -H "Content-Type: application/json" \
  -d '{"product_id": 123, "quantity": 2}'

# Get categories
curl https://your-domain.com/wp-json/restbridge/v1/categories

# Get taxonomies
curl https://your-domain.com/wp-json/restbridge/v1/taxonomies

# Get taxonomy terms
curl https://your-domain.com/wp-json/restbridge/v1/taxonomies/category/terms

# Get current user (if authenticated)
curl https://your-domain.com/wp-json/restbridge/v1/users/me \
  --cookie "wordpress_logged_in_xxx=your_cookie_value"
```

## Testing in Browser

Simply visit these URLs in your browser (GET requests only):

- `https://your-domain.com/wp-json/restbridge/v1/posts`
- `https://your-domain.com/wp-json/restbridge/v1/pages`
- `https://your-domain.com/wp-json/restbridge/v1/products`
- `https://your-domain.com/wp-json/restbridge/v1/categories`
- `https://your-domain.com/wp-json/restbridge/v1/tags`
- `https://your-domain.com/wp-json/restbridge/v1/media`
- `https://your-domain.com/wp-json/restbridge/v1/taxonomies`

## Authentication

For POST, PUT, and DELETE requests, you'll need to authenticate. WordPress uses:
- **Cookie authentication** (for browser users)
- **Application Passwords** (WordPress 5.6+)
- **OAuth plugins** (if installed)

Example with Application Password:
```bash
curl -X POST https://your-domain.com/wp-json/restbridge/v1/posts \
  -u username:application_password \
  -H "Content-Type: application/json" \
  -d '{"title": "New Post", "content": "Post content"}'
```

