# Frontend Handoff: Marketer Pilot

Implement this in `F:/react_projects/dropship_web_frontend`. Use the Laravel [Marketer Pilot API](./marketer-pilot-api.md) as the contract. This is a frontend task: inspect the current code first and report any missing backend capability instead of inventing endpoints or changing backend rules.

## Product Rules

- A marketer is an approved user with `user_type === "marketer"`. A marketer's client remains a normal dropshipper who uses the existing `/app` panel.
- The marketer sees active clients and attributed order counts, not shopper records, wallet balances, withdrawals, or client impersonation controls. One delivered order earns one performance point; a returned order no longer earns it. Use the API's point total without recomputing it.
- Points are not money. No wallet, earnings, commission, payout, agreement editor, advertising integration, or marketplace in this release. CRM notes, tags, reminders, and campaigns are a later phase with no API today; do not ship placeholders as working features.

## 1. Inspect and Set Up Routing

1. Inspect `src/routes/router.jsx`, the shared login flow, current panel layouts and route guards, the Redux Toolkit Query base API, endpoint definitions, and existing table, pagination, error, and confirmation components.
2. Add a dedicated Marketer Panel layout at `/marketer-panel` with **Dashboard**, **Clients**, and **Invitations**. Use the existing panel's density, styling, and Bangla/English conventions. Suggested routes: `/marketer-panel`, `/marketer-panel/clients`, and `/marketer-panel/invitations`.
3. Reuse `POST /api/auth/login` and `POST /api/auth/logout`. Route marketers to their panel after login, with an authentication and role guard on direct navigation and refresh. Preserve all existing role redirects. Do not mount shared reseller/admin widgets that automatically call endpoints a marketer token cannot access.
4. Use the existing base API and bearer-token handling. Do not hardcode a host or store invitation codes in the URL, analytics, or persistent state. Treat 401 as session expiry and 403 on marketer endpoints as an access-denied state; do not retry forbidden requests in a loop.

## 2. Marketer Dashboard and Performance

- **API:** `GET /api/marketer/performance?from=YYYY-MM-DD&to=YYYY-MM-DD&client_id=...`. All filters are optional; omit unset ones. Dates filter main-order creation dates, not delivery dates. Default to all time unless the existing dashboard pattern dictates otherwise.
- Display **Placed**, **Delivered**, **Cancelled**, **Returned**, and **Performance Points** from `data.placed`, `data.delivered`, `data.cancelled`, `data.returned`, and `data.points`. Display zero as zero. No currency symbol, projected revenue, or order detail links.
- Validate date ranges before requesting. Reuse this endpoint for client-level performance; pass the relationship's `id` as `client_id`, never the dropshipper's user ID. The unfiltered totals may include orders credited under ended relationships. Do not invent a historical client selector or show historical client names.

## 3. Marketer Clients

- **API:** `GET /api/marketer/clients?page=1&per_page=20`. Render `dropshipper.name` and `accepted_at`, with a **View performance** action that opens the dashboard with the relationship filter. Keep results paginated; do not make one performance request for every table row.
- This endpoint returns active clients only. Do not add edit, remove, or transfer controls to the marketer view; ending a relationship is an admin or dropshipper action. No buyer details, contacts, or financial data should be inferred from other APIs.

## 4. Marketer Invitations

| API | Frontend task |
| --- | --- |
| `GET /api/marketer/invitations?page=1&per_page=20` | Paginated list: recipient email, sent date, expiry, and state. Derive the state in this order: Accepted if `marketer_client_id` is set, Revoked if `revoked_at` is set, Expired if `expires_at` is past, otherwise Pending. |
| `POST /api/marketer/invitations` | Submit `{ "email": "client@example.com" }` from an email form. On 201, refresh the list and confirm that the code was emailed. The API does not return the code; do not offer a copy-link action. Handle 422, 429, and 503. |
| `DELETE /api/marketer/invitations/{id}` | Confirm before revoking a pending invite. Refresh the list afterward. If a concurrent acceptance makes the action fail, refresh the row instead of claiming success. |

## 5. Dropshipper Consent in the Existing Panel

Add a small **Marketer Partnership** section to the existing dropshipper panel. Do not create a second dropshipper account or replace the current panel. An invited person can register normally with the invited email, then sign in directly and enter the code they received.

1. **Current relationship:** `GET /api/marketer-client/relationship`. If `data` is null, show the invitation code form. Otherwise show the marketer's name, business name when present, and acceptance date.
2. **Preview:** `POST /api/marketer-client/invitation/preview` with `{ "code": "..." }`. Show the returned marketer and `consent_text`. The consent checkbox starts unchecked; enable acceptance only after the client checks it.
3. **Accept:** `POST /api/marketer-client/invitation/accept` with `{ "code": "...", "consent": true, "consent_version": "<from preview>" }`. On 201, clear the code and refetch the relationship. Handle 404 as unavailable and 409 as already linked. For 403, explain that a direct dropshipper sign-in may be required; old sessions and admin impersonation tokens cannot consent.
4. **Disconnect:** `PATCH /api/marketer-client/{id}/end`, using the relationship ID. Confirm first, then refetch. Make clear that their account and orders remain theirs.

## 6. Admin Marketers

Add **Marketers** to the existing admin navigation. Follow the existing admin authorization pattern; the backend requires an active admin or superadmin, not a product manager.

| API | Frontend task |
| --- | --- |
| `GET /api/admin/marketers?page=1&per_page=20` | List marketer name, business name, email, and active/suspended status from `banned`. |
| `POST /api/admin/marketers` | Create an approved marketer with `name`, `email`, and optional `business_name`. Explain that the marketer sets their password via the existing email password-reset flow. Do not ask the admin to enter a password or claim that account creation sends a setup email. |
| `PATCH /api/admin/marketers/{id}/suspension` | Confirm and send `{ "suspended": true }` or `false`. Refetch marketer status on success. |
| `GET /api/admin/marketers/{id}/clients?page=1&per_page=20` | Show active and ended links, client names, and dates. |
| `PATCH /api/admin/marketers/clients/{id}/end` | End an active link after confirmation, using the relationship ID. Reassignment requires a new invite and client acceptance; there is no direct transfer API. |

## 7. Integration and Acceptance Checks

- The success envelope is `{ status, message, data }`. For paginated lists, records are at `response.data.data` in the decoded JSON, with Laravel pagination fields in `response.data`. Handle Laravel validation field errors and the API's message-only errors. Show loading, empty, error/retry, and mutation-in-progress states; refresh only affected queries after successful writes.
- Verify in an authorized staging or local test environment: admin creates marketer, marketer signs in and invites client, client previews and accepts, client appears in the marketer's list, attributed counts show correctly, and admin or client can end the link.
- Test expired/revoked codes, a mismatched account, existing active links, direct URL refresh, a suspended marketer, and isolation between two marketer accounts. Check responsive tables and keyboard access, then run the frontend build. Do not test by creating production accounts or sending live invitations without permission.
- Report any blockers separately. End-to-end invitation testing requires the deployed migration and working email delivery. The existing public landing-order details endpoint needs a separate privacy review before the pilot opens; do not use it to build marketer reports.
