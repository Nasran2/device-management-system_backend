# DeviceGuard Laravel Backend

## Shared-hosting command delivery

Set `QUEUE_CONNECTION=sync` in production on shared hosting. Device commands then attempt FCM delivery inside the command request and do not depend on a long-running queue worker. Keep the existing 15-minute cron/queue runner as a fallback for non-critical background work; Android also polls while its locked screen is visible.

Laravel 13 dashboard and `/api/v1` device API. Core logic lives in policies and services, Admin ownership is enforced by query scopes and authorization policies, device tokens are stored as hashes, activation/access codes are hashed, commands are device-specifically signed, and sensitive actions are audited.

See [`../docs/COMPLETE_SETUP.md`](../docs/COMPLETE_SETUP.md) and [`../docs/API_DOCUMENTATION.md`](../docs/API_DOCUMENTATION.md).
