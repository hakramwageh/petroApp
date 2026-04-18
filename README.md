# Station Transfer Events API

Senior backend take-home assignment implemented with Laravel 11 and PostgreSQL 16.

## Tech stack

- PHP 8.3
- Laravel 11
- PostgreSQL 16
- PHPUnit
- Docker Compose with PHP CLI + PostgreSQL

## Requirements

- Docker
- Docker Compose
- GNU Make

## Run locally without Docker

```bash
make local-run
```

This installs dependencies, runs migrations, and starts the API locally without Docker.

The API will be available at `http://localhost:8000/api/v0`.

## Run with Docker

```bash
make docker-run
```

This starts the Docker stack, waits for PostgreSQL, runs migrations, and serves the API.

The API will be available at `http://localhost:8000/api/v0`.

## Run tests

Local:

- must have postgres running before run test locally

```bash
make local-test
```

Docker:

```bash
make docker-test
```

The Docker setup creates both `petroapp` and `petroapp_test` databases. PHPUnit is configured to use `petroapp_test`.

## API examples

```bash
curl -X POST http://localhost:8000/api/v0/transfers \
  -H "Content-Type: application/json" \
  -d '{
    "events": [
      {
        "event_id": "evt-1",
        "station_id": "S1",
        "amount": 100.50,
        "status": "approved",
        "created_at": "2026-04-17T10:00:00Z"
      },
      {
        "event_id": "evt-2",
        "station_id": "S1",
        "amount": 12.00,
        "status": "pending_review",
        "created_at": "2026-04-17T10:02:00Z"
      }
    ]
  }'
```

```json
{
  "inserted": 2,
  "duplicates": 0,
  "invalid": 0
}
```

```bash
curl http://localhost:8000/api/v0/stations/S1/summary
```

```json
{
  "station_id": "S1",
  "total_approved_amount": 100.5,
  "approved_events_count": 1,
  "events_count": 2
}
```

## Assumptions and design decisions


| Decision                    | Choice                                         | Rationale                                                                               |
| --------------------------- | ---------------------------------------------- | --------------------------------------------------------------------------------------- |
| Batch validation strategy   | Partial acceptance                             | External producers can resend only failed records instead of re-sending the whole batch |
| Top-level malformed payload | `400 Bad Request`                              | Missing `events` or non-array `events` means the request cannot be processed at all     |
| `events_count` definition   | All stored statuses                            | Reconciliation needs the total number of received events, not only approved ones        |
| `approved_events_count`     | Included as a bonus field                      | Makes the summary more useful without changing the required totals                      |
| Concurrency mechanism       | PostgreSQL `UNIQUE` + `ON CONFLICT DO NOTHING` | Atomic and race-safe without app-level locking                                          |
| Idempotency mechanism       | Bulk insert with `RETURNING`                   | Counts inserted rows directly from PostgreSQL and avoids TOCTOU races                   |
| Within-batch deduplication  | Service-layer dedup by `event_id`              | Business rule belongs in application logic, while cross-request dedup stays in the DB   |
| Insert chunking             | 100 rows per insert                            | Keeps parameter counts bounded and remains configurable                                 |
| Max batch size              | 500 events                                     | Protects memory and request processing time; configurable through env vars              |
| Route versioning            | `/api/v0`                                      | Leaves room for future non-breaking API evolution                                       |
| Logging                     | Request-scoped UUID + start/end/invalid logs   | Correlated operational visibility without excessive log noise                           |
| Unknown statuses            | Stored but excluded from approved totals       | Preserves the full event trail while keeping the approved aggregate precise             |


## Architecture

Services depend on `TransferStoreInterface`, not a concrete repository. `AppServiceProvider` is the single wiring point that binds the interface to `PostgresTransferRepository`, which means the storage adapter can be swapped later without changing controllers or business logic.

Request flow:

1. `TransferController` validates the top-level request shape.
2. `StationService` validates individual events, partially accepts the batch, deduplicates within the request, delegates persistence through the port, and also retrieves station summaries.
3. `PostgresTransferRepository` performs chunked `INSERT ... ON CONFLICT (event_id) DO NOTHING RETURNING event_id`.

## Concurrency and idempotency notes

- Cross-request idempotency is enforced by the `transfer_events.event_id` unique constraint.
- PostgreSQL handles concurrent inserts atomically. If two requests race on the same `event_id`, one insert wins and the other sees a conflict with zero rows returned from `RETURNING`.
- No application-level mutex is used because that would be redundant and more fragile than the database guarantee.

## Validation behavior

- Tier 1: missing `events` or non-array `events` returns `400`.
- Tier 2: malformed individual events are skipped and reported in `validation_errors`, while valid events are still inserted with HTTP `200`.
- Per-event rules cover required fields, `amount >= 0`, and ISO8601 timestamps.

Example partial acceptance response:

```json
{
  "inserted": 2,
  "duplicates": 0,
  "invalid": 1,
  "validation_errors": [
    {
      "index": 1,
      "errors": {
        "amount": [
          "The amount field must be at least 0."
        ]
      }
    }
  ]
}
```

## Scalability notes

- Add a message broker such as RabbitMQ or SQS so producers can hand off ingestion asynchronously and workers can scale independently.
- Add Redis caching for `/stations/{station_id}/summary` with invalidation on successful inserts.

## Verification checklist

1. `make docker-run`
2. `curl -X POST http://localhost:8000/api/v0/transfers ...`
3. Repeat the same payload and confirm `inserted` becomes `0` while `duplicates` increments.
4. `curl http://localhost:8000/api/v0/stations/S1/summary`
5. `make docker-test`
6. Confirm the concurrent ingestion test passes without double inserts.
7. Confirm `GET /api/v0/stations/UNKNOWN/summary` returns `404`.
