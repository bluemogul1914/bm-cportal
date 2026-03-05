# PHP Playground / Blue Mogul Portal

An online PHP code editor and execution environment built with React + Express, plus a full-featured Blue Mogul Client Portal with admin panel.

## Architecture

- **Frontend**: React with Tailwind CSS, shadcn/ui components, wouter routing
- **Backend**: Express.js with PHP code execution via child_process
- **Database**: PostgreSQL with Drizzle ORM
- **Tables**: users, clients, products, subscriptions, invoices, tickets, ticket_comments, ticket_time_entries, documents, payments, activity_log, agent_logs, agent_config, agent_metrics, system_settings, network_devices, network_credentials, knowledge_articles, notifications, projects, project_tasks, project_notes, vultr_instances, itarian_endpoints, itarian_alerts, snippets + Stripe schema
- **Runtime**: PHP 8.2 installed via Nix for server-side code execution
- **Payments**: Stripe integration via Replit connector (OAuth-managed)

## Blue Mogul Portal

### Client Portal Pages
- `dashboard.php` - Client dashboard with open tickets, invoices, services overview, real notifications
- `tickets.php` - Ticket list via ITarian Service Desk API (create, filter, search)
- `ticket-detail.php` - Ticket detail via ITarian SD API (view conversation, reply)
- `billing.php` - Invoice list with filters, payment history, outstanding balance
- `pay-invoice.php` - Stripe Checkout payment flow for invoices
- `payment-success.php` - Post-payment confirmation (supports demo mode)
- `services.php` - Active subscriptions with costs and details, cloud services (Vultr instances) section
- `products.php` - Product catalog browsing with category filters and "Request Service" flow
- `documents.php` - Document management with categories, upload, delete
- `profile.php` - Profile editing and password change
- `settings.php` - Account settings (notification prefs, 2FA, theme, communication prefs)
- `client-voip.php` - Client voice services (VoIP.ms white-label portal: My Services, CallerID Filtering, Call Forwarding, Callback, Voicemail, Order DIDs)
- `projects.php` - Client project tracking with progress bars, task lists, status cards
- `help.php` - Client-facing Help Center / Knowledge Base with search, categories, article view

### Admin Portal Pages
- `admin-dashboard.php` - Business metrics, MRR, churn, activity
- `admin-clients.php` - Client list with search/filter
- `admin-client-detail.php` - Full CRM-style client profile: overview with 4 financial cards, services with status bars, location map (Leaflet/OpenStreetMap), activity logs with timestamps/ID#/tags, client profile card with avatar/tags, invoices mini-table, next invoice preview, credits management, active tickets, projects; tabs for Invoices (with billing summary), Payments, Documents, Tickets, Network (devices), Cloud (Vultr instances assigned to client with specs/bandwidth/cost, unassign action), Projects; master/sub-account hierarchy display
- `admin-client-add.php` - Create new client with full form (info, parent account, location, credits, notes)
- `admin-client-edit.php` - Edit client: info, parent account (master/sub-account), lat/lng location, credit balance, notes
- `admin-tickets.php` - Ticket management (list, search, filter, close, time logged per ticket with billable amounts)
- `admin-ticket-detail.php` - Ticket detail (view, reply, close, ticket metadata, billable time tracker with live timer + manual entry, time log with per-entry rates and delete)
- `admin-invoices.php` - Invoice management
- `admin-invoice-add.php` - Create invoices with ITFlow-style line items (Item/Description/Qty/Unit Price/Tax/Amount), product autocomplete, subtotal/tax/total, customer footer; invoice_number regex `'^INV-[0-9]{3,5}$'` excludes Stripe-synced 8-digit numbers
- `admin-invoice-detail.php` - Invoice detail with line items display, payment history, mark paid/unpaid, Stripe payment link, email invoice, customer footer
- `admin-products.php` - Product catalog management (CRUD, toggle active/inactive)
- `admin-services.php` - Subscription management (assign products to clients, suspend/cancel)
- `admin-network.php` - Network Documentation: per-client device inventory, credentials vault
- `admin-knowledge.php` - Knowledge Base admin: create/edit/publish/delete articles
- `admin-ai-agents.php` - AI Agent Army command center (10 agents with codenames, blueprints, workflows, ROI, integration panels)
- `admin-automation.php` - 30-day deployment roadmap, integration status, getting started guide
- `admin-reports.php` - Revenue trends, charts, analytics
- `admin-settings.php` - Company info, API keys, SMTP, system info
- `admin-itflow.php` - ITFlow PSA integration dashboard (connection status, sync capabilities)
- `admin-uisp.php` - UISP network management integration (connection status, device stats with online/offline/warning counts, recent devices table, device type breakdown, capabilities, setup instructions)
- `admin-voip.php` - VoIP.ms phone integration (CDR lookup, DID management)
- `admin-nextcloud.php` - Nextcloud file sharing integration (connection status, document stats, storage usage, recent documents table, document type breakdown, capabilities, setup instructions)
- `admin-stripe.php` - Stripe payment integration (connection status for keys + webhook, billing summary with revenue/paid/unpaid stats, recent payments table, recent invoices table, monthly revenue chart, webhook config, payment methods)
- `admin-audit.php` - Activity audit trail (summary stat cards, action breakdown chart, recently active users, filterable log table with color-coded action badges, search, date range, pagination 25/page)
- `admin-messages.php` - Messaging center (list sent/draft messages, filters by category/status)
- `admin-message-compose.php` - Compose message (rich editor, categories, recipient selection, placeholders, template load/save, preview, draft save)
- `admin-message-templates.php` - Message templates CRUD (create/edit/delete reusable templates)
- `admin-vultr.php` - Vultr Cloud integration (live API: account info, instance management, bandwidth monitoring, client assignment for billing, cost breakdown per client, sync button, deploy new server wizard with type/region/plan/OS selection)
- `admin-itarian.php` - ITarian RMM integration (endpoint management, patch management, alert monitoring, device sync, OS breakdown, client assignment)
- `admin-roles.php` - Roles & Access Control (RBAC: super-admin, admin, sales, IT support, billing, wholesaler, dealer — staff/partner only; client 'user' role excluded, managed under Clients)
- `admin-projects.php` - Project management list (create, filter, status/type/priority, progress tracking)
- `admin-project-detail.php` - Individual project view with task board, notes, timeline, status management

### Shared Components
- `includes/client-sidebar.php` - Client portal sidebar (dark navy #0d1b3e)
- `includes/admin-sidebar.php` - Admin panel sidebar
- `includes/email.php` - SMTP email helper (send_email, email_template, notification functions)
- `config.php` - Database connection, session, API keys, helpers
- `login-handler.php` - Authentication handler
- `setup.php` - Database initializer

### Email Notifications
- SMTP settings stored in `system_settings` table, configured via Admin Settings > Email tab
- `includes/email.php` provides: `send_email()`, `email_template()`, `send_test_email()`
- Auto-notifications wired into: ticket creation (tickets.php), admin ticket replies (admin-ticket-detail.php), invoice creation (admin-invoice-add.php), invoice paid (admin-invoice-detail.php), document uploads (documents.php)
- Notification helpers: `notify_ticket_created()`, `notify_ticket_reply()`, `notify_invoice_created()`, `notify_invoice_paid()`, `notify_document_uploaded()`
- Test email feature available in Admin Settings > Email tab
- Uses raw SMTP sockets (stream_socket_client) with STARTTLS/SSL support — PHP `mail()` is disabled in sandbox
- Admin messaging center: news blasts, alerts, promotions, network outage notifications
- Message templates: reusable templates with placeholders for client data
- Placeholders: `{{ client.name }}`, `{{ client.email }}`, `{{ client.company }}`, `{{ client.id }}`, `{{ company.name }}`, `{{ date.today }}`, etc.
- DB tables: `messages` (sent/draft messages), `message_templates` (reusable templates)

### Branding
- Colors: Primary #1a56db, Secondary #0d1b3e, Accent #3b82f6
- Font: Inter
- Logo: `/assets/img/bluemogul-logo.png`

### Credentials
- Admin: admin@bluemogul.biz / admin123
- Portal URL: /portal

## Structure

- `client/src/pages/` - React frontend pages (playground, snippets)
- `server/routes.ts` - API endpoints (execute PHP, CRUD snippets, Stripe, checkout)
- `server/storage.ts` - Database storage layer
- `server/index.ts` - Express server with PHP execution, session bridge, ALLOWED_PHP_FILES, webhook API
- `shared/schema.ts` - Drizzle schema and types for all tables
- `assets/` - Static assets (CSS, images, logo)
- `uploads/` - User-uploaded documents

## API Endpoints

- `POST /api/execute` - Execute PHP code with sandboxing
- `GET/POST /api/snippets` - CRUD snippets
- `GET /api/stripe/publishable-key` - Get Stripe publishable key
- `GET /api/stripe/products` - List Stripe products
- `POST /api/stripe/checkout` - Stripe checkout for subscriptions
- `POST /api/create-checkout-session` - Stripe checkout for invoice payments
- `POST /api/stripe/webhook` - Stripe webhook handler
- `GET /portal/:file` - Serve PHP portal pages (GET)
- `POST /portal/:file` - Handle PHP form submissions (POST)

### N8N Webhook API (for AI Agent integration)
- `POST /api/webhook/agent-log` - Log agent execution (agent_name, action, status, message, execution_time)
- `POST /api/webhook/create-ticket` - Create ticket from agent (client_id, subject, description, priority, source)
- `POST /api/webhook/update-device` - Update/add network device (hostname, client_id, status, ip_address, etc.)
- `POST /api/webhook/notify` - Send notification to user (user_id, title, message, type, entity_type, entity_id)
- `POST /api/webhook/create-project` - Create project with tasks (name, client_id, project_type, tasks[])
- `POST /api/webhook/update-project` - Update project status/progress (project_id, status, progress, add_tasks[], add_note)
- `GET /api/webhook/health` - Health check and endpoint listing

### AI Agent Army API
- `GET /api/agents` - List all agents with metrics
- `GET /api/agents/dashboard` - Dashboard totals (online, hrs saved, revenue, success rate)
- `GET /api/agents/activity` - Recent agent activity feed
- `GET /api/agents/roi` - ROI calculator data with infra stack
- `GET /api/agents/:key` - Single agent detail with recent logs
- `POST /api/agents/:key/run` - Trigger single agent via N8N webhook
- `POST /api/agents/run-all` - Trigger all agents (Orchestrator)
- `POST /api/agents/update-webhook` - Set agent's N8N webhook URL
- `POST /api/cron/daily-reset` - Reset daily metrics counters

### Project Management API
- `GET /api/projects` - List all projects with task counts

## Critical Notes

- Asset paths must be ABSOLUTE (start with `/`) in all PHP files
- `$_SERVER['REQUEST_METHOD']` must use `($_SERVER['REQUEST_METHOD'] ?? '')`
- PostgreSQL syntax: ILIKE, EXTRACT(), TO_CHAR(), ON CONFLICT
- Always add new PHP files to `ALLOWED_PHP_FILES` in `server/index.ts` AND restart workflow
- Session bridge: Express session mapped to PHP `$_SESSION` via `buildSessionPhpCode()`
- `$_SESSION['is_admin']` is a boolean; admin guard: `if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true)`

## Security

- **CSRF Protection**: All POST forms include `csrf_field()` hidden inputs; validated by `require_csrf()` which dies on failure; tokens persist across Express-PHP bridge via `buildSessionPhpCode()` and `extractCsrfToken()`
- **Login Rate Limiting**: `check_rate_limit()` blocks after 5 failed attempts in 15 minutes; failed logins logged via `record_failed_login()`
- **Session Regeneration**: `session_regenerate_id(true)` called after successful login
- **Security Headers**: X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy, Content-Security-Policy (all set via `set_security_headers()` in config.php)
- **Root Redirect**: `/` redirects to `/portal` (login page)
- PHP execution uses `disable_functions` to block shell/file/network operations
- Rate limiting: 20 requests per minute per IP
- 10-second execution timeout and 64MB memory limit
- All API keys/secrets stored via Replit Secrets
- Production mode: `display_errors = 0` in config.php

## Integrations

- **Stripe**: Connected via Replit integration (OAuth). Schema managed by stripe-replit-sync
- **ITarian RMM**: Remote monitoring via ITarian API. API base auto-resolves from portal URL to correct API endpoint (pitstop-api.itarian.com / msp-api.itarian.com). Endpoints: `/api/v1/device/load`, `/api/v1/alerts`. Headers: `x-auth-token: ITARIAN_API_KEY`, `x-auth-type: 4`
- **Ticketing**: 100% local PostgreSQL — no external API. `tickets` + `ticket_comments` + `ticket_time_entries` tables
- **config.php**: References env vars for Coolify, VoIP.ms, ITarian (RMM), HubSpot, Matrix, ITFlow, UISP, Vultr, Ollama, N8N, Flowise, AnythingLLM
