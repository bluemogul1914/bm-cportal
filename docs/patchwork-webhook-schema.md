# PATCHWORK Webhook Payload Schema

> **Agent:** PATCHWORK (Patch Management & RMM Engineer)  
> **Webhook URL:** `https://automation.bluemogul.us/webhook/patchwork-rmm`  
> **Method:** `POST`  
> **Content-Type:** `application/json`

## Authentication

All requests are signed with the shared secret token:

```
x-webhook-token: <SESSION_SECRET>
```

The `SESSION_SECRET` value is stored in the N8N environment variable `SESSION_SECRET` and in the PATCHWORK agent's adapter config secret field.

## Request Payload

When Paperclip triggers a PATCHWORK heartbeat (task assignment, scheduled wake, or comment mention), it sends the following JSON body:

```json
{
  "agentId": "e3345fab-c19a-4777-b0c2-2e0f807d4042",
  "companyId": "dc80d344-7c9c-4ad3-b4d1-35f2b72c260a",
  "apiUrl": "https://paperclip.ing",
  "runId": "<uuid>",
  "apiKey": "<short-lived-jwt>",

  // Optional fields — present only when this wake was triggered by a specific task or event
  "taskId": "<issue-id>",
  "wakeReason": "task_assigned | issue_comment_mentioned | scheduled | approval_resolved",
  "wakeCommentId": "<comment-uuid>",
  "approvalId": "<approval-uuid>",
  "approvalStatus": "approved | rejected",
  "linkedIssueIds": "BLU-1,BLU-2"
}
```

### Field Descriptions

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `agentId` | string (UUID) | ✅ | PATCHWORK agent UUID in Paperclip |
| `companyId` | string (UUID) | ✅ | Blue Mogul company UUID |
| `apiUrl` | string (URL) | ✅ | Base URL of the Paperclip API |
| `runId` | string (UUID) | ✅ | Unique ID for this heartbeat run — include in all `X-Paperclip-Run-Id` headers |
| `apiKey` | string (JWT) | ✅ | Short-lived JWT for Paperclip API auth; use as `Authorization: Bearer <apiKey>` |
| `taskId` | string | ❌ | Paperclip issue ID that triggered this wake (e.g. `BLU-45`) |
| `wakeReason` | string | ❌ | Why the agent was triggered |
| `wakeCommentId` | string (UUID) | ❌ | Specific comment that triggered this wake |
| `approvalId` | string (UUID) | ❌ | Approval request ID if wake was triggered by approval |
| `approvalStatus` | string | ❌ | `approved` or `rejected` |
| `linkedIssueIds` | string | ❌ | Comma-separated list of linked issue identifiers |

## Wake Reasons

| Value | Trigger |
|-------|---------|
| `task_assigned` | A Paperclip task was assigned to PATCHWORK |
| `issue_comment_mentioned` | PATCHWORK was @-mentioned in a comment |
| `scheduled` | Periodic heartbeat (every 6 hours per schedule config) |
| `approval_resolved` | A pending approval was approved or rejected |

## How PATCHWORK Uses This Payload

The N8N webhook node at `https://automation.bluemogul.us/webhook/patchwork-rmm` receives this payload and:

1. Validates `x-webhook-token` against `$env.SESSION_SECRET`
2. Checks for `taskId` in the payload to determine if a specific task triggered the wake
3. Routes to the appropriate sub-flow:
   - Sub-flow A: Offline endpoint detection (runs every 15 min via separate schedule trigger)
   - Sub-flow B: Daily patch compliance report (8 AM CT via schedule trigger)
   - Sub-flow C: Weekly vulnerability report (Monday 9 AM CT via schedule trigger)
   - Webhook sub-flow: Handles on-demand triggers from Paperclip task assignments

## Example Payload (Task Assignment)

```json
{
  "agentId": "e3345fab-c19a-4777-b0c2-2e0f807d4042",
  "companyId": "dc80d344-7c9c-4ad3-b4d1-35f2b72c260a",
  "apiUrl": "https://paperclip.ing",
  "runId": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
  "apiKey": "eyJhbGciOiJIUzI1NiJ9...",
  "taskId": "BLU-45",
  "wakeReason": "task_assigned"
}
```

## Environment Variable Reference

PATCHWORK N8N workflow requires the following env vars in N8N:

| Variable | Description |
|----------|-------------|
| `SESSION_SECRET` | Shared secret for `x-webhook-token` header validation |
| `ACTION1_ORG_ID` | Action1 organization UUID (`c71462ee-81b4-4d6b-95cd-146475ce276a`) |
| `ACTION1_API_TOKEN` | Action1 Bearer token (obtained via OAuth2 client credentials flow) |
| `MATRIX_ACCESS_TOKEN` | Matrix homeserver user access token for posting alerts |
| `MATRIX_ALERTS_ROOM_ID` | Matrix room ID for the `#alerts` channel |
| `MATRIX_HOMESERVER_URL` | Matrix homeserver base URL (e.g. `https://matrix.bluemogul.us`) |

## Action1 OAuth2 Token Retrieval

To generate `ACTION1_API_TOKEN`, use the client credentials flow:

```bash
curl -X POST https://app.action1.com/api/3.0/oauth2/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "grant_type=client_credentials" \
  --data-urlencode "client_id=<ACTION1_CLIENT_ID>" \
  --data-urlencode "client_secret=<ACTION1_API_KEY>"
```

Returns a JWT Bearer token valid for 1 hour.

- **Action1 Org ID (Blue Mogul):** `c71462ee-81b4-4d6b-95cd-146475ce276a`
- **API Endpoint:** `https://app.action1.com/api/3.0/{orgId}/endpoints`
