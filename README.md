# XFunding Hub — Loan Application API

Laravel 13 API with SQLite persistence, Spatie Laravel Model States and Pest tests.
Implements the supplied design without authentication or a frontend.

## Run locally

Requires PHP 8.4+, Composer 2, and PHP extensions including PDO SQLite, mbstring,
XML, ctype, fileinfo, curl and openssl. Node.js is not needed.

From this `loan-api` directory:

```sh
composer install
```

Copy `.env.example` to `.env` (PowerShell: `Copy-Item .env.example .env`;
macOS/Linux: `cp .env.example .env`), then:

```sh
php artisan key:generate
php artisan migrate
php artisan serve
```

Accept the SQLite database creation prompt if asked. Alternatively, `composer setup`
installs dependencies, creates `.env` and the SQLite file, generates the key and migrates.
The API runs at `http://localhost:8000`. Data persists in `database/database.sqlite`.
The source design document is in the parent folder.

## API

### Postman

Import `postman/XFunding-Hub.postman_collection.json` using Postman's **Import** button.
Start the API with `php artisan serve`, then run the collection in its listed order.
The collection includes approval/disbursement, rejection, and validation/error cases.
Its `baseUrl` variable defaults to `http://localhost:8000`; change it for another host.
Create requests automatically save application IDs for subsequent requests.
No separate environment file is needed. Each run creates three new applications.

Send `Content-Type: application/json` and `Accept: application/json`.
Responses are plain JSON objects with camelCase fields, without a `data` wrapper.
On Windows PowerShell, use `curl.exe` for these curl examples.

### Create — POST /api/v1/loan-applications

```sh
curl -i http://localhost:8000/api/v1/loan-applications \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"customerId":"C1001","amount":5000.00,"currency":"GBP","termMonths":12}'
```

Returns **201 Created**, with a `Location` header and:

```json
{
  "applicationId": "LA1001",
  "customerId": "C1001",
  "amount": 5000.00,
  "currency": "GBP",
  "termMonths": 12,
  "status": "SUBMITTED"
}
```

Application IDs use `LA` followed by the database ID plus 1000 (`LA1001`, `LA1002`, ...).
The server assigns both the ID and initial state; clients cannot set these on creation.

### Retrieve — GET /api/v1/loan-applications/{applicationId}

```sh
curl http://localhost:8000/api/v1/loan-applications/LA1001 -H 'Accept: application/json'
```

Returns **200 OK** with the same fields, or **404 Not Found**.

### Change status — PATCH /api/v1/loan-applications/{applicationId}/status

```sh
curl -X PATCH http://localhost:8000/api/v1/loan-applications/LA1001/status \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"status":"UNDER_REVIEW"}'
```

Returns **200 OK** with the updated application. Only these transitions are allowed:

```text
SUBMITTED → UNDER_REVIEW → APPROVED → DISBURSED
                         ↘ REJECTED
```

`REJECTED` and `DISBURSED` are terminal. Repeating the current status is invalid.
An invalid transition returns **409 Conflict**, for example:

```json
{"message":"Cannot transition from SUBMITTED to APPROVED."}
```

Unknown or malformed IDs return **404**. Missing or invalid inputs, including an unknown
status name, return **422**, with Laravel's `message` and field-specific `errors`.

## Validation assumptions

The design leaves input limits unspecified. This implementation uses:

- `customerId`: required, nonblank string, at most 100 characters; no customer lookup.
- `amount`: 0.01–9,999,999,999.99, with at most two decimal places.
- `currency`: exactly three uppercase ASCII letters; no ISO currency lookup or FX conversion.
- `termMonths`: positive integer up to 2,147,483,647; no additional lending-policy limit.
- `status`: one of the five exact uppercase values.

Amounts are stored as DECIMAL(12,2) and returned as JSON numbers to match the design.
JSON does not preserve trailing zero formatting. SQLite uses numeric affinity rather
than fixed decimal storage; exact fractional precision at every magnitude would need
integer minor units or a database with native fixed-precision decimals.

## Implementation

- Form Requests validate input; the resource defines the response contract.
- `app/States/LoanApplicationState.php` defines the four allowed transitions.
- Each concrete Spatie state has a stable uppercase name used in storage and responses.
- `ChangeLoanApplicationStatus` reloads the application inside a transaction before
  calling Spatie's `transitionTo`; invalid transitions become HTTP 409.
- Row locking is used where the database supports it. SQLite ignores row locks and
  serializes writes through database locks; transactions retry concurrency failures.
  A heavily concurrent service would need a database-specific concurrency strategy.
- Updating the raw status column directly bypasses Spatie validation; API writes go
  through the action. There are no notifications or external side effects.

## Tests and formatting

```sh
php artisan test
php vendor/bin/pest
php vendor/bin/pint --test
```

Pest unit tests cover all 25 state pairs, including the 21 forbidden pairs.
API/integration tests cover creation and persistence, retrieval, both workflow branches,
invalid transitions without mutation, input validation, missing IDs and distinct IDs.
Feature tests use an isolated in-memory SQLite database and do not modify local data.
