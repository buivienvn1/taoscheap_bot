# Sandromania Partner API

Base URL: `https://api.sandromania.shop`

This API is for partner stock purchases only. It is separate from the site storefront and from payment-provider accounts.

## Credentials and access

- `pk_...` is the partner API key. It identifies the partner.
- `ps_...` is the partner API secret. It is used only to calculate HMAC and must never be put into frontend code, a browser, screenshots, or a public repository.
- They are **not** CryptoBot, Binance, Bybit, Platega, or storefront credentials.
- Each POST purchase uses the partner's pre-funded virtual balance. It never asks the partner's customer to pay crypto and never creates `supplier_payment`.

The shop owner tops up a partner's balance in Telegram:

```text
/api_partner_balance pk_xxx 100$
```

Restrict a partner key to a server IP:

```text
/api_partner_ip pk_xxx 191.44.112.1
```

To remove an IP restriction: `/api_partner_ip pk_xxx clear`.

The IP restriction protects a key after a request reaches the API. It does not solve DNS, routing, or firewall timeouts. The partner server must be able to reach TCP port 443 at `api.sandromania.shop`.

## Endpoints

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/health` | none | Partner API health check |
| GET | `/api/v1/products` | `products` | Product catalogue, current price and availability |
| GET | `/api/v1/balance` | `balance` | Partner virtual balance |
| POST | `/api/v1/orders` | `orders` | Buy auto-delivery stock with partner balance |
| GET | `/api/v1/orders/{order_id}` | `orders` | Order status and delivery |

All paths below are relative to `https://api.sandromania.shop`.

## HMAC authentication

Every endpoint except `GET /api/v1/health` requires these headers:

```http
X-Api-Key: pk_...
X-Timestamp: 178...
X-Nonce: a-new-unique-random-value
X-Signature: lowercase-hmac-sha256-hex
Content-Type: application/json
```

For `POST /api/v1/orders`, add one more mandatory header:

```http
Idempotency-Key: a-stable-unique-key-for-this-purchase
```

### Exact signature formula

`X-Timestamp` is Unix time in **seconds**, not milliseconds. It is accepted only within 300 seconds of the API clock.

`X-Nonce` must be unique for every HTTP request and contain 8-120 characters. A nonce cannot be reused.

Build the exact UTF-8 byte sequence:

```text
timestamp + "." + nonce + "." + UPPERCASE_METHOD + "." + path_with_query + "." + raw_request_body
```

Then calculate:

```text
X-Signature = HMAC-SHA256(api_secret, signed_bytes).hexdigest().lower()
```

It is a lowercase **hex** string, not base64. `path_with_query` is only the path and query string, not the full URL. The POST body in the signature must be byte-for-byte identical to the body sent over HTTP.

Examples of signed input:

```text
1784139161.a8f91c2e.GET./api/v1/products.
1784139161.a8f91c2e.POST./api/v1/orders.{"product_id":1021,"quantity":1}
```

## Catalogue

```http
GET /api/v1/products
```

Returns only public product data, for example:

```json
{"ok":true,"products":[{"id":1021,"title":"ChatGPT plus 1m CDK","type":"standard","stock":3,"available":true,"price_rub":430,"price_usd":5.5,"bulk_prices":"5 4.9$"}]}
```

## Partner balance

```http
GET /api/v1/balance
```

```json
{"ok":true,"balance_rub":5000,"balance_usd":64.14}
```

## Buy with partner balance and auto-delivery

```http
POST /api/v1/orders
Idempotency-Key: order-from-your-system-000123
Content-Type: application/json

{"product_id":1021,"quantity":1}
```

Only standard auto-delivery products with enough stock can be purchased. The operation is transactional: stock is reserved, partner balance is charged, and the order is created together. If any step fails, nothing is charged and no code is issued.

Successful first purchase returns HTTP `201`:

```json
{"ok":true,"order":{"id":123,"status":"approved","payment_status":"paid","payment_source":"partner_balance","product_id":1021,"title":"ChatGPT plus 1m CDK","quantity":1,"total_rub":430,"total_usd":5.5,"delivery":["login@example.com password"],"delivery_text":"login@example.com password","idempotent_replay":false}}
```

Save `delivery_text` exactly as returned. It contains one issued code/account per line.

### Required retry behavior

Create one new `Idempotency-Key` for one logical purchase and store it before sending the request. If a connection times out, retry with the **same body and same Idempotency-Key**. The API returns HTTP `200`, the original order and its original `delivery_text`; it will not charge or issue again.

Never reuse that key for another product, quantity, or customer. The API returns `409 idempotency_key_conflict` if a key is reused with a different body.

## Order status

```http
GET /api/v1/orders/123
```

The response contains the same order fields, including `delivery` and `delivery_text`, but only for orders belonging to the authenticated partner key.

## Python example

```python
import hashlib, hmac, json, secrets, time, uuid
import requests

BASE_URL = "https://api.sandromania.shop"
API_KEY = "pk_..."
API_SECRET = "ps_..."

def partner_request(method, path, payload=None, idempotency_key=None):
    body = b"" if payload is None else json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    timestamp, nonce = str(int(time.time())), secrets.token_urlsafe(18)
    signed = b".".join([timestamp.encode(), nonce.encode(), method.upper().encode(), path.encode(), body])
    signature = hmac.new(API_SECRET.encode(), signed, hashlib.sha256).hexdigest()
    headers = {"X-Api-Key": API_KEY, "X-Timestamp": timestamp, "X-Nonce": nonce, "X-Signature": signature, "Content-Type": "application/json"}
    if idempotency_key:
        headers["Idempotency-Key"] = idempotency_key
    response = requests.request(method, BASE_URL + path, headers=headers, data=body, timeout=30)
    response.raise_for_status()
    return response.json()

print(partner_request("GET", "/api/v1/products"))
print(partner_request("GET", "/api/v1/balance"))
result = partner_request("POST", "/api/v1/orders", {"product_id": 1021, "quantity": 1}, "my-shop-" + str(uuid.uuid4()))
print(result["order"]["delivery_text"])
```

## Node.js example

```js
import crypto from "node:crypto";
const BASE_URL = "https://api.sandromania.shop";
const API_KEY = process.env.SANDROMANIA_API_KEY;
const API_SECRET = process.env.SANDROMANIA_API_SECRET;

async function partnerRequest(method, path, payload, idempotencyKey) {
  const body = payload ? JSON.stringify(payload) : "";
  const timestamp = String(Math.floor(Date.now() / 1000));
  const nonce = crypto.randomBytes(18).toString("hex");
  const signed = `${timestamp}.${nonce}.${method.toUpperCase()}.${path}.${body}`;
  const signature = crypto.createHmac("sha256", API_SECRET).update(signed, "utf8").digest("hex");
  const headers = {"X-Api-Key": API_KEY, "X-Timestamp": timestamp, "X-Nonce": nonce, "X-Signature": signature, "Content-Type": "application/json"};
  if (idempotencyKey) headers["Idempotency-Key"] = idempotencyKey;
  const response = await fetch(BASE_URL + path, {method, headers, body: body || undefined});
  const data = await response.json();
  if (!response.ok) throw new Error(`${response.status}: ${JSON.stringify(data)}`);
  return data;
}

const order = await partnerRequest("POST", "/api/v1/orders", {product_id: 1021, quantity: 1}, crypto.randomUUID());
console.log(order.order.delivery_text);
```

## Error handling

| Status | Code | Meaning |
| --- | --- | --- |
| 401 | `auth_headers_missing`, `bad_signature`, `timestamp_expired`, `nonce_reused` | Fix signing; do not retry with the same nonce |
| 403 | `ip_not_allowed`, `permission_denied` | Owner must allowlist the server IP or grant permission |
| 404 | `product_not_found`, `order_not_found` | Invalid or inaccessible resource |
| 409 | `idempotency_key_conflict`, `insufficient_balance`, `out_of_stock` | Do not blindly retry; replenish balance or stock / change request |
| 429 | `rate_limited` | Back off and retry later |

## Security rules

1. Keep `ps_...` in server environment variables only.
2. Do not expose it in a browser, mobile app, client-side JavaScript, or logs.
3. Use HTTPS only; do not call port `8091` from the public internet.
4. The partner endpoint is rate-limited and audited. The owner can disable a key immediately with `/api_partner_disable pk_xxx`.
5. If a secret was pasted into chat, source control, a ticket, or a screenshot, rotate it: create a new key and disable the old key.
