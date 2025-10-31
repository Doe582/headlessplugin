<?php
/**
 * Authentication Examples for REST Bridge API
 * 
 * This file contains example code snippets for authenticating REST API requests.
 * DO NOT use this file directly - copy the code snippets into your own application.
 */

// ============================================================================
// 1. PHP Example using wp_remote_get/wp_remote_post
// ============================================================================

function rest_bridge_api_request_example() {
    $username = 'your_username';
    $password = 'your_application_password'; // Get from Users → Profile → Application Passwords
    $base_url = 'http://localhost/wooautopublix/wp-json/restbridge/v1';
    
    // Create basic auth header
    $auth_string = base64_encode($username . ':' . $password);
    
    // Get posts (no auth required)
    $response = wp_remote_get($base_url . '/posts');
    $posts = json_decode(wp_remote_retrieve_body($response), true);
    
    // Create post (requires auth)
    $response = wp_remote_post($base_url . '/posts', [
        'headers' => [
            'Authorization' => 'Basic ' . $auth_string,
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'title' => 'New Post Title',
            'content' => 'Post content here',
            'status' => 'publish',
        ]),
    ]);
    
    return json_decode(wp_remote_retrieve_body($response), true);
}

// ============================================================================
// 2. JavaScript/Fetch Example
// ============================================================================

/*

// Get posts (public - no auth)
fetch('http://localhost/wooautopublix/wp-json/restbridge/v1/posts')
  .then(response => response.json())
  .then(data => console.log(data));

// Create post (requires auth)
const username = 'your_username';
const password = 'your_application_password';

fetch('http://localhost/wooautopublix/wp-json/restbridge/v1/posts', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Basic ' + btoa(username + ':' + password)
  },
  body: JSON.stringify({
    title: 'New Post',
    content: 'Post content',
    status: 'publish'
  })
})
.then(response => response.json())
.then(data => console.log(data));

// Upload media (requires auth + multipart)
const formData = new FormData();
formData.append('file', fileInput.files[0]);
formData.append('title', 'My Image');

fetch('http://localhost/wooautopublix/wp-json/restbridge/v1/media', {
  method: 'POST',
  headers: {
    'Authorization': 'Basic ' + btoa(username + ':' + password)
  },
  body: formData
})
.then(response => response.json())
.then(data => console.log(data));

*/

// ============================================================================
// 3. cURL Examples
// ============================================================================

/*

# Get posts (public)
curl "http://localhost/wooautopublix/wp-json/restbridge/v1/posts"

# Create post (with auth)
curl -X POST "http://localhost/wooautopublix/wp-json/restbridge/v1/posts" \
  -u "username:application_password" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "New Post",
    "content": "Content here",
    "status": "publish"
  }'

# Update post
curl -X PUT "http://localhost/wooautopublix/wp-json/restbridge/v1/posts/123" \
  -u "username:application_password" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Updated Title"
  }'

# Delete post
curl -X DELETE "http://localhost/wooautopublix/wp-json/restbridge/v1/posts/123" \
  -u "username:application_password"

# Upload media
curl -X POST "http://localhost/wooautopublix/wp-json/restbridge/v1/media" \
  -u "username:application_password" \
  -F "file=@/path/to/image.jpg" \
  -F "title=My Image"

*/

// ============================================================================
// 4. Python Example
// ============================================================================

/*

import requests
from requests.auth import HTTPBasicAuth

base_url = 'http://localhost/wooautopublix/wp-json/restbridge/v1'
username = 'your_username'
password = 'your_application_password'

# Get posts (public)
response = requests.get(f'{base_url}/posts')
print(response.json())

# Create post (with auth)
response = requests.post(
    f'{base_url}/posts',
    auth=HTTPBasicAuth(username, password),
    json={
        'title': 'New Post',
        'content': 'Post content',
        'status': 'publish'
    }
)
print(response.json())

# Upload media
with open('image.jpg', 'rb') as f:
    files = {'file': f}
    data = {'title': 'My Image'}
    response = requests.post(
        f'{base_url}/media',
        auth=HTTPBasicAuth(username, password),
        files=files,
        data=data
    )
    print(response.json())

*/

// ============================================================================
// 5. Node.js Example
// ============================================================================

/*

const axios = require('axios');
const FormData = require('form-data');
const fs = require('fs');

const baseURL = 'http://localhost/wooautopublix/wp-json/restbridge/v1';
const username = 'your_username';
const password = 'your_application_password';

// Create basic auth
const auth = Buffer.from(`${username}:${password}`).toString('base64');

// Get posts
axios.get(`${baseURL}/posts`)
  .then(response => console.log(response.data));

// Create post
axios.post(`${baseURL}/posts`, {
  title: 'New Post',
  content: 'Post content',
  status: 'publish'
}, {
  headers: {
    'Authorization': `Basic ${auth}`,
    'Content-Type': 'application/json'
  }
})
.then(response => console.log(response.data));

// Upload media
const form = new FormData();
form.append('file', fs.createReadStream('image.jpg'));
form.append('title', 'My Image');

axios.post(`${baseURL}/media`, form, {
  headers: {
    'Authorization': `Basic ${auth}`,
    ...form.getHeaders()
  }
})
.then(response => console.log(response.data));

*/

