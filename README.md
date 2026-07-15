# DeviceGuard Laravel Backend

Laravel 13 dashboard and `/api/v1` device API. Core logic lives in policies and services, Admin ownership is enforced by query scopes and authorization policies, device tokens are stored as hashes, activation/access codes are hashed, commands are device-specifically signed, and sensitive actions are audited.

See [`../docs/COMPLETE_SETUP.md`](../docs/COMPLETE_SETUP.md) and [`../docs/API_DOCUMENTATION.md`](../docs/API_DOCUMENTATION.md).
