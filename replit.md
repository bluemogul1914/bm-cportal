# PHP Playground / Blue Mogul Portal

An online PHP code editor and execution environment built with React + Express, plus a full-featured Blue Mogul Client Portal with admin panel.

## Architecture

- **Frontend**: React with Tailwind CSS, shadcn/ui components, wouter routing
- **Backend**: Express.js with PHP code execution via child_process
- **Database**: PostgreSQL with Drizzle ORM
- **Tables**: users, clients, products, subscriptions, invoices, tickets, ticket_comments, documents, payments, activity_log, agent_logs, agent_config, system_settings, network_devices, network_credentials, knowledge_articles, notifications, snippets + Stripe schema
- **Runtime**: PHP 8.2 installed via Nix for server-side code execution
- **Payments**: Stripe integration via Replit connector (OAuth-managed)

## Blue Mogul Portal

### Client Portal Pages
- `dashboard.php` - Client dashboard with open tickets, invoices, services overview, real notifications
- `tickets.php` - Ticket list with create, filter, search
- `ticket-detail.php` - Individual ticket view with conversation thread and replies
- `billing.php` - Invoice list with filters, payment history, outstanding balance
- `pay-invoice.php` - Stripe Checkout payment flow for invoices
- `payment-success.php` - Post-payment confirmation (supports demo mode)
- `services.php` - Active subscriptions with costs and details
- `products.php` - Product catalog browsing with category filters and "Request Service" flow
- `documents.php` - Document management with categories, upload, delete
- `profile.php` - Profile editing and password change
- `settings.php` - Account settings (notification prefs, 2FA, theme, communication prefs)
- `help.php` - Client-facing Help Center / Knowledge Base with search, categories, article view

### Admin Portal Pages
- `admin-dashboard.php` - Business metrics, MRR, churn, activity
- `admin-clients.php` - Client list with search/filter
- `admin-client-detail.php` - Full client profile with tickets, invoices, services, documents
- `admin-client-edit.php` - Edit client information
- `admin-tickets.php` - Ticket management with triage
- `admin-ticket-detail.php` - Reply (public/internal), status/priority/assignee changes
- `admin-invoices.php` - Invoice management
- `admin-invoice-add.php` - Create new invoices
- `admin-invoice-detail.php` - Invoice detail with payment history, mark paid/unpaid
- `admin-products.php` - Product catalog management (CRUD, toggle active/inactive)
- `admin-services.php` - Subscription management (assign products to clients, suspend/cancel)
- `admin-network.php` - Network Documentation: per-client device inventory, credentials vault
- `admin-knowledge.php` - Knowledge Base admin: create/edit/publish/delete articles
- `admin-ai-agents.php` - AI Agent Army command center (10 agents with codenames, blueprints, workflows, ROI, integration panels)
- `admin-automation.php` - 30-day deployment roadmap, integration status, getting started guide
- `admin-reports.php` - Revenue trends, charts, analytics
- `admin-settings.php` - Company info, API keys, SMTP, system info
- `admin-itflow.php` - ITFlow PSA integration dashboard (connection status, sync capabilities)
- `admin-uisp.php` - UISP network management integration (devices, service plans)
- `admin-voip.php` - VoIP.ms phone integration (CDR lookup, DID management)
- `admin-nextcloud.php` - Nextcloud file sharing integration (document sync)
- `admin-stripe.php` - Stripe payment integration (billing overview, recent payments)
- `admin-audit.php` - Activity audit trail (filterable log of all user actions)
- `admin-roles.php` - Roles & Access Control (RBAC: super-admin, admin, sales, IT support, billing, user)

### Shared Components
- `includes/client-sidebar.php` - Client portal sidebar (dark navy #0d1b3e)
- `includes/admin-sidebar.php` - Admin panel sidebar
- `config.php` - Database connection, session, API keys, helpers
- `login-handler.php` - Authentication handler
- `setup.php` - Database initializer

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
- `GET /api/webhook/health` - Health check and endpoint listing

## Critical Notes

- Asset paths must be ABSOLUTE (start with `/`) in all PHP files
- `$_SERVER['REQUEST_METHOD']` must use `($_SERVER['REQUEST_METHOD'] ?? '')`
- PostgreSQL syntax: ILIKE, EXTRACT(), TO_CHAR(), ON CONFLICT
- Always add new PHP files to `ALLOWED_PHP_FILES` in `server/index.ts` AND restart workflow
- Session bridge: Express session mapped to PHP `$_SESSION` via `buildSessionPhpCode()`
- `$_SESSION['is_admin']` is a boolean; admin guard: `if (!isset($_SESSION['user_id']) || ($_SESSION['is_admin'] ?? false) !== true)`

## Security

- PHP execution uses `disable_functions` to block shell/file/network operations
- Rate limiting: 20 requests per minute per IP
- 10-second execution timeout and 64MB memory limit
- All API keys/secrets stored via Replit Secrets

## Integrations

- **Stripe**: Connected via Replit integration (OAuth). Schema managed by stripe-replit-sync
- **config.php**: References env vars for Coolify, VoIP.ms, ITarian, HubSpot, Matrix, ITFlow, UISP, Ollama, N8N, Flowise, AnythingLLM
