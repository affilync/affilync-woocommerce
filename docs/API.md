# Affilync for WooCommerce - API Reference

This document provides a complete reference for the REST API endpoints exposed by the Affilync WooCommerce plugin.

## Table of Contents

- [Overview](#overview)
- [Authentication](#authentication)
- [Rate Limiting](#rate-limiting)
- [Endpoints](#endpoints)
  - [Health Check](#health-check)
  - [Status](#status)
  - [OAuth](#oauth)
  - [Settings](#settings)
  - [Conversions](#conversions)
  - [Products](#products)
  - [Webhooks](#webhooks)
- [Error Handling](#error-handling)
- [Webhook Events](#webhook-events)

---

## Overview

The Affilync WooCommerce plugin registers REST API endpoints under the `affilync/v1` namespace.

**Base URL**:
```
https://your-site.com/wp-json/affilync/v1/
```

**API Version**: 1.0

**Content Type**: All requests and responses use `application/json`

---

## Authentication

### Public Endpoints

Some endpoints are publicly accessible without authentication:
- `GET /health` - Health check
- `POST /webhooks/receive` - Webhook receiver (uses HMAC signature verification)

### Authenticated Endpoints

Most endpoints require WordPress authentication with the `manage_woocommerce` capability.

**Authentication Methods**:

1. **Cookie Authentication** (for admin AJAX requests):
   - WordPress login session
   - Include `X-WP-Nonce` header with valid nonce

2. **Application Passwords** (WordPress 5.6+):
   - Generate at Users > Your Profile > Application Passwords
   - Use HTTP Basic Auth: `Authorization: Basic base64(username:app_password)`

**Example with Application Password**:
```bash
curl -X GET \
  https://your-site.com/wp-json/affilync/v1/status \
  -H "Authorization: Basic dXNlcm5hbWU6YXBwX3Bhc3N3b3Jk"
```

**Example with Cookie/Nonce**:
```javascript
fetch('/wp-json/affilync/v1/settings', {
  headers: {
    'X-WP-Nonce': wpApiSettings.nonce
  }
});
```

---

## Rate Limiting

The plugin implements rate limiting to prevent abuse.

**Default Limits**:

| Action | Limit | Window |
|--------|-------|--------|
| API calls | 100 requests | Per minute |
| OAuth attempts | 5 attempts | Per 15 minutes |
| Webhook receive | 60 requests | Per minute |
| Settings write | 10 requests | Per minute |
| Sync operations | 5 requests | Per 5 minutes |

**Rate Limit Response**:
```json
{
  "code": "rate_limited",
  "message": "Too many requests. Please try again later.",
  "data": {
    "status": 429
  }
}
```

---

## Endpoints

### Health Check

Check if the plugin API is operational.

```
GET /wp-json/affilync/v1/health
```

**Authentication**: None required

**Response** `200 OK`:
```json
{
  "status": "ok",
  "version": "1.0.0",
  "timestamp": "2024-01-15T10:30:00+00:00"
}
```

---

### Status

Get the current plugin status including connection state.

```
GET /wp-json/affilync/v1/status
```

**Authentication**: Required (`manage_woocommerce`)

**Response** `200 OK`:
```json
{
  "connected": true,
  "api_healthy": true,
  "brand_id": "brd_abc123xyz",
  "version": "1.0.0",
  "wc_version": "8.5.1",
  "php_version": "8.1.12"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `connected` | boolean | Whether connected to Affilync |
| `api_healthy` | boolean | Whether Affilync API is reachable |
| `brand_id` | string | Your Affilync brand ID |
| `version` | string | Plugin version |
| `wc_version` | string | WooCommerce version |
| `php_version` | string | PHP version |

---

### OAuth

#### Initiate OAuth Flow

Start the OAuth authorization process.

```
POST /wp-json/affilync/v1/oauth/initiate
```

**Authentication**: Required (`manage_woocommerce`)

**Response** `200 OK`:
```json
{
  "success": true,
  "auth_url": "https://api.affilync.com/oauth/authorize?client_id=...&state=..."
}
```

**Error Response** `400`:
```json
{
  "code": "client_id_missing",
  "message": "Client ID not configured. Please activate your license or define AFFILYNC_CLIENT_ID.",
  "data": {
    "status": 400
  }
}
```

---

#### Disconnect OAuth

Disconnect from Affilync and clear credentials.

```
POST /wp-json/affilync/v1/oauth/disconnect
```

**Authentication**: Required (`manage_woocommerce`)

**Response** `200 OK`:
```json
{
  "success": true,
  "message": "Disconnected from Affilync."
}
```

---

### Settings

#### Get Settings

Retrieve current plugin settings.

```
GET /wp-json/affilync/v1/settings
```

**Authentication**: Required (`manage_woocommerce`)

**Response** `200 OK`:
```json
{
  "tracking_enabled": true,
  "cookie_duration": 30,
  "conversion_statuses": ["completed", "processing"],
  "product_sync_enabled": true,
  "product_sync_interval": "hourly",
  "attribution_model": "last_click",
  "track_url_params": ["ref", "aff", "campaign"]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `tracking_enabled` | boolean | Whether conversion tracking is active |
| `cookie_duration` | integer | Days to keep tracking cookies |
| `conversion_statuses` | array | Order statuses that trigger conversions |
| `product_sync_enabled` | boolean | Whether product sync is active |
| `product_sync_interval` | string | Sync frequency (hourly, twicedaily, daily) |
| `attribution_model` | string | Attribution model (last_click, first_click) |
| `track_url_params` | array | URL parameters to track |

---

#### Update Settings

Update plugin settings.

```
POST /wp-json/affilync/v1/settings
```

**Authentication**: Required (`manage_woocommerce`)

**Request Body**:
```json
{
  "tracking_enabled": true,
  "cookie_duration": 45,
  "attribution_model": "first_click"
}
```

**Response** `200 OK`:
```json
{
  "success": true,
  "settings": {
    "tracking_enabled": true,
    "cookie_duration": 45,
    "conversion_statuses": ["completed", "processing"],
    "product_sync_enabled": true,
    "product_sync_interval": "hourly",
    "attribution_model": "first_click",
    "track_url_params": ["ref", "aff", "campaign"]
  }
}
```

**Validation Rules**:
- `cookie_duration`: Min 1, Max 365
- `product_sync_interval`: Must be "hourly", "twicedaily", or "daily"
- `attribution_model`: Must be "last_click" or "first_click"

---

### Conversions

#### List Conversions

Retrieve tracked conversions.

```
GET /wp-json/affilync/v1/conversions
```

**Authentication**: Required (`manage_woocommerce`)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | integer | 1 | Page number |
| `limit` | integer | 20 | Results per page (max 100) |
| `status` | string | - | Filter by status (pending, approved, rejected) |

**Example Request**:
```
GET /wp-json/affilync/v1/conversions?page=1&limit=20&status=pending
```

**Response** `200 OK`:
```json
{
  "conversions": [
    {
      "id": 123,
      "order_id": 456,
      "affiliate_id": "aff_abc123",
      "campaign_id": "camp_xyz789",
      "link_id": "link_qrs456",
      "click_id": "clk_uvw123",
      "order_total": "99.99",
      "commission_amount": "9.99",
      "currency": "USD",
      "status": "pending",
      "attribution_data": {
        "affiliate_id": "aff_abc123",
        "campaign_id": "camp_xyz789",
        "landed_at": "2024-01-15T10:00:00Z"
      },
      "synced_to_api": true,
      "api_conversion_id": "conv_abc123",
      "created_at": "2024-01-15T10:30:00",
      "updated_at": "2024-01-15T10:30:00"
    }
  ],
  "total": 150,
  "page": 1,
  "limit": 20,
  "pages": 8
}
```

| Conversion Field | Type | Description |
|------------------|------|-------------|
| `id` | integer | Local conversion ID |
| `order_id` | integer | WooCommerce order ID |
| `affiliate_id` | string | Affiliate identifier |
| `campaign_id` | string | Campaign identifier |
| `link_id` | string | Tracking link identifier |
| `click_id` | string | Click event identifier |
| `order_total` | string | Order total amount |
| `commission_amount` | string | Calculated commission |
| `currency` | string | Currency code (ISO 4217) |
| `status` | string | Conversion status |
| `synced_to_api` | boolean | Whether synced to Affilync |
| `api_conversion_id` | string | Affilync conversion ID |
| `created_at` | string | Creation timestamp |

---

#### Sync Conversions

Manually trigger sync of pending conversions.

```
POST /wp-json/affilync/v1/conversions/sync
```

**Authentication**: Required (`manage_woocommerce`)

**Response** `200 OK`:
```json
{
  "success": true,
  "synced": 5,
  "failed": 1
}
```

---

### Products

#### Get Product Sync Status

Retrieve product synchronization statistics.

```
GET /wp-json/affilync/v1/products/sync-status
```

**Authentication**: Required (`manage_woocommerce`)

**Response** `200 OK`:
```json
{
  "total": 250,
  "synced": 240,
  "pending": 8,
  "failed": 2,
  "last_sync": "2024-01-15T09:00:00"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `total` | integer | Total products tracked |
| `synced` | integer | Successfully synced products |
| `pending` | integer | Products awaiting sync |
| `failed` | integer | Products with sync errors |
| `last_sync` | string | Last successful sync timestamp |

---

#### Trigger Product Sync

Manually trigger product synchronization.

```
POST /wp-json/affilync/v1/products/sync
```

**Authentication**: Required (`manage_woocommerce`)

**Request Body** (optional):
```json
{
  "full": true
}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `full` | boolean | false | Force sync of all products |

**Response** `200 OK`:
```json
{
  "success": true,
  "synced": 25,
  "failed": 2,
  "message": "Synced 25 products, 2 failed."
}
```

---

### Webhooks

#### Receive Webhook

Endpoint for receiving webhooks from Affilync.

```
POST /wp-json/affilync/v1/webhooks/receive
```

**Authentication**: HMAC Signature (not WordPress auth)

**Required Headers**:

| Header | Description |
|--------|-------------|
| `X-Affilync-Signature` | HMAC-SHA256 signature |
| `X-Affilync-Timestamp` | Request timestamp |
| `Content-Type` | application/json |

**Request Body**:
```json
{
  "webhook_id": "wh_abc123xyz",
  "event": "conversion.approved",
  "data": {
    "conversion_id": "conv_abc123",
    "order_id": 456,
    "affiliate_id": "aff_abc123",
    "amount": "99.99"
  },
  "timestamp": "2024-01-15T10:30:00Z"
}
```

**Response** `200 OK`:
```json
{
  "status": "accepted",
  "webhook_id": "wh_abc123xyz"
}
```

**Duplicate Response** `200 OK`:
```json
{
  "status": "duplicate",
  "message": "Webhook already processed"
}
```

**Invalid Signature** `401`:
```json
{
  "code": "invalid_signature",
  "message": "Signature verification failed",
  "data": {
    "status": 401
  }
}
```

---

## Error Handling

All errors follow a consistent format:

```json
{
  "code": "error_code",
  "message": "Human-readable error message",
  "data": {
    "status": 400
  }
}
```

### Common Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `rest_forbidden` | 403 | User lacks required capability |
| `rate_limited` | 429 | Too many requests |
| `not_connected` | 401 | Not connected to Affilync |
| `invalid_signature` | 401 | Webhook signature invalid |
| `invalid_payload` | 400 | Malformed request body |
| `api_error` | 4xx/5xx | Affilync API error |
| `client_id_missing` | 400 | No client ID configured |
| `invalid_plan` | 400 | Invalid subscription plan |

### HTTP Status Codes

| Status | Meaning |
|--------|---------|
| 200 | Success |
| 400 | Bad Request (validation error) |
| 401 | Unauthorized (authentication required) |
| 403 | Forbidden (insufficient permissions) |
| 404 | Not Found |
| 429 | Too Many Requests (rate limited) |
| 500 | Internal Server Error |

---

## Webhook Events

Webhooks sent from Affilync to your site follow this format:

### Event Types

| Event | Description |
|-------|-------------|
| `conversion.approved` | Conversion approved by brand |
| `conversion.rejected` | Conversion rejected |
| `conversion.pending` | Conversion moved to pending review |
| `payout.processed` | Affiliate payout completed |
| `campaign.updated` | Campaign settings modified |
| `campaign.paused` | Campaign paused |
| `affiliate.joined` | New affiliate joined campaign |
| `link.clicked` | Tracking link clicked |

### conversion.approved

Fired when a conversion is approved.

```json
{
  "webhook_id": "wh_abc123",
  "event": "conversion.approved",
  "data": {
    "conversion_id": "conv_abc123",
    "order_id": 456,
    "affiliate_id": "aff_xyz789",
    "amount": "99.99",
    "commission": "9.99",
    "approved_at": "2024-01-15T10:30:00Z"
  }
}
```

### conversion.rejected

Fired when a conversion is rejected.

```json
{
  "webhook_id": "wh_def456",
  "event": "conversion.rejected",
  "data": {
    "conversion_id": "conv_abc123",
    "order_id": 456,
    "reason": "Order refunded",
    "rejected_at": "2024-01-16T14:00:00Z"
  }
}
```

### payout.processed

Fired when an affiliate payout is processed.

```json
{
  "webhook_id": "wh_ghi789",
  "event": "payout.processed",
  "data": {
    "payout_id": "pay_abc123",
    "affiliate_id": "aff_xyz789",
    "amount": "150.00",
    "currency": "USD",
    "processed_at": "2024-01-20T12:00:00Z"
  }
}
```

### campaign.updated

Fired when campaign settings change.

```json
{
  "webhook_id": "wh_jkl012",
  "event": "campaign.updated",
  "data": {
    "campaign_id": "camp_abc123",
    "changes": ["commission_rate", "description"],
    "updated_at": "2024-01-18T16:30:00Z"
  }
}
```

---

## Code Examples

### PHP (WordPress)

```php
// Using WordPress HTTP API
$response = wp_remote_get(
    rest_url( 'affilync/v1/conversions' ),
    array(
        'headers' => array(
            'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ),
        ),
    )
);

$data = json_decode( wp_remote_retrieve_body( $response ), true );
```

### JavaScript (Admin)

```javascript
// Using wp.apiFetch (recommended in admin)
wp.apiFetch({
    path: '/affilync/v1/conversions',
}).then(data => {
    console.log(data.conversions);
});
```

### cURL

```bash
# Get conversions (with Application Password)
curl -X GET \
  "https://your-site.com/wp-json/affilync/v1/conversions?limit=10" \
  -H "Authorization: Basic dXNlcm5hbWU6YXBwX3Bhc3N3b3Jk" \
  -H "Content-Type: application/json"

# Update settings
curl -X POST \
  "https://your-site.com/wp-json/affilync/v1/settings" \
  -H "Authorization: Basic dXNlcm5hbWU6YXBwX3Bhc3N3b3Jk" \
  -H "Content-Type: application/json" \
  -d '{"cookie_duration": 60}'
```

---

## Testing the API

### Health Check Test

```bash
curl -s https://your-site.com/wp-json/affilync/v1/health | jq
```

### Authenticated Request Test

```bash
# Replace with your credentials
AUTH=$(echo -n "admin:your_app_password" | base64)

curl -s -H "Authorization: Basic $AUTH" \
  https://your-site.com/wp-json/affilync/v1/status | jq
```

---

## Support

For API support:
- **Documentation**: https://docs.affilync.com/woocommerce/api
- **Email**: support@affilync.com
