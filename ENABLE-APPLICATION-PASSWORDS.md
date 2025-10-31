# How to Enable Application Passwords

## Method 1: Enable via wp-config.php (Recommended)

1. Open your WordPress root `wp-config.php` file
2. Add this line **before** the line that says `/* That's all, stop editing! */`:

```php
// Enable Application Passwords for REST API
define('WP_APPLICATION_PASSWORDS', true);
```

3. Save the file
4. Refresh your WordPress admin page
5. Go to **Users → Your Profile** (or edit your user)
6. Scroll down - you should now see **"Application Passwords"** section

## Method 2: Check WordPress Version

Application Passwords require **WordPress 5.6 or higher**.

To check your version:
- Go to **Dashboard → Updates** or look at the bottom of any admin page

If you're on an older version, you need to:
1. Update WordPress to 5.6+
2. Then follow Method 1 above

## Method 3: Alternative - Use Cookie Authentication

If Application Passwords don't work, you can use Cookie Authentication:

1. Log into WordPress in your browser
2. Open browser Developer Tools (F12)
3. Go to Application/Storage → Cookies
4. Copy the `wordpress_logged_in_xxxxx` cookie value
5. Use it in your API requests:

```bash
curl -X GET "http://localhost/wooautopublix/wp-json/restbridge/v1/customers" \
  -H "Cookie: wordpress_logged_in_xxxxx=your_cookie_value_here"
```

## Method 4: Check if HTTPS is Required

Some WordPress installations require HTTPS for Application Passwords. If your site uses HTTPS:
- Make sure your API calls use `https://` not `http://`
- Application Passwords might only work over HTTPS in some configurations

## Quick Test

After enabling Application Passwords, test if it works:

```bash
# Replace username and password with your credentials
curl -X GET "http://localhost/wooautopublix/wp-json/wp/v2/users/me" \
  -u "your_username:your_regular_password"
```

If this returns your user info, then Basic Auth is working and the issue is just the permission check (which we already fixed).

