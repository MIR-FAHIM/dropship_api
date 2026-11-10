# Marketer Pilot API

All endpoints below are under `/api` and require a bearer token. Responses use the existing success envelope. List endpoints support `per_page` (1 to 100).

## Admin

| Method and path | Purpose |
| --- | --- |
| `POST /admin/marketers` | Approve a new account with `name`, `email`, and optional `business_name`. The marketer sets a password through the existing email password-reset flow, then signs in normally. |
| `GET /admin/marketers` | List marketer accounts. |
| `PATCH /admin/marketers/{id}/suspension` | Set `suspended` to true or false. Suspension blocks panel access, new attribution, and acceptance of this marketer's invitations. |
| `GET /admin/marketers/{id}/clients` | View current and historical relationships. |
| `PATCH /admin/marketers/clients/{id}/end` | End an active relationship. The ID here is the relationship ID, not a user ID. |

## Marketer

| Method and path | Purpose |
| --- | --- |
| `POST /marketer/invitations` | Send a seven-day, single-use invitation code to `email`. The code is sent only to the recipient and is not returned in the response. |
| `GET /marketer/invitations` | List the marketer's own invitations. |
| `DELETE /marketer/invitations/{id}` | Revoke an unused invitation. |
| `GET /marketer/clients` | List active client links with the client's name and acceptance date. |
| `GET /marketer/performance` | Return placed, delivered, cancelled, returned, and points counts. Optional filters: `client_id` (relationship ID), `from`, and `to` (`YYYY-MM-DD`, based on main-order creation date). |

## Dropshipper

The client registers or signs in normally, using the invited email. They must be active and signed in directly; old sessions and admin impersonation tokens cannot consent.

| Method and path | Purpose |
| --- | --- |
| `POST /marketer-client/invitation/preview` | Send `code` to retrieve the marketer name and current consent text/version before acceptance. |
| `POST /marketer-client/invitation/accept` | Send `code`, `consent: true`, and the preview's `consent_version`. Returns 409 if the dropshipper already has an active marketer. |
| `GET /marketer-client/relationship` | Return the current relationship, or null. |
| `PATCH /marketer-client/{id}/end` | Withdraw access by ending the dropshipper's own relationship. |

## Attribution

- A new order keeps its relationship ID even if the client later changes marketers. For landing-page orders, this is captured when the buyer submits the order and copied on conversion.
- Existing orders are not backfilled. Ended relationships retain aggregate historical counts without active client access.
- One point per order in a completed dropshipper status (9 or 10). Returned and cancelled orders do not earn points. No payouts or wallet entries are created.
