# PHP Playground / Blue Mogul Portal

An online PHP code editor and execution environment built with React + Express, with Stripe integration for payments.

## Architecture

- **Frontend**: React with Tailwind CSS, shadcn/ui components, wouter routing
- **Backend**: Express.js with PHP code execution via child_process
- **Database**: PostgreSQL with Drizzle ORM for snippets, users, clients, products, subscriptions, invoices, tickets, agent_logs + Stripe schema via stripe-replit-sync
- **Runtime**: PHP 8.2 installed via Nix for server-side code execution
- **Payments**: Stripe integration via Replit connector (OAuth-managed)

## Features

- Write and execute PHP code in the browser
- Syntax-aware editor with line numbers
- Output panel with execution time
- Save snippets to a library
- Load and delete saved snippets
- Dark/light theme toggle
- Pre-seeded with example PHP snippets
- Stripe payment integration (products, prices, checkout)

## Structure

- `client/src/pages/playground.tsx` - Main code editor and execution page
- `client/src/pages/snippets.tsx` - Snippet library page
- `client/src/components/app-sidebar.tsx` - Navigation sidebar
- `client/src/components/theme-provider.tsx` - Theme context
- `server/routes.ts` - API endpoints (execute PHP, CRUD snippets, Stripe)
- `server/storage.ts` - Database storage layer
- `server/db.ts` - PostgreSQL connection
- `server/seed.ts` - Seed data for example snippets
- `server/stripeClient.ts` - Stripe client via Replit connector (OAuth)
- `server/webhookHandlers.ts` - Stripe webhook processing
- `shared/schema.ts` - Drizzle schema and types
- `config.php` - Blue Mogul Portal PHP configuration (env-based secrets)
- `setup.php` - Database setup script (creates tables, seeds products, creates admin user)

## API Endpoints

- `POST /api/execute` - Execute PHP code with sandboxing
- `GET /api/snippets` - List all saved snippets
- `POST /api/snippets` - Save a new snippet
- `DELETE /api/snippets/:id` - Delete a snippet
- `GET /api/stripe/publishable-key` - Get Stripe publishable key
- `GET /api/stripe/products` - List Stripe products with prices
- `POST /api/stripe/checkout` - Create Stripe checkout session
- `POST /api/stripe/webhook` - Stripe webhook handler (registered before express.json)

## Security

- PHP execution uses `disable_functions` to block shell/file/network operations
- `open_basedir` restricts filesystem access to temp directory only
- Rate limiting: 20 requests per minute per IP
- 10-second execution timeout and 64MB memory limit
- Code size limited to 50KB
- All API keys/secrets stored via Replit Secrets (never hardcoded)

## Integrations

- **Stripe**: Connected via Replit integration (OAuth). Schema managed by stripe-replit-sync. Webhook route registered before express.json() middleware.
- **config.php**: References env vars for Coolify, VoIP.ms, ITarian, HubSpot, Matrix, ITFlow, UISP

## Theme

- Primary color: Purple/Indigo (258 72%)
- Font: Inter (sans), JetBrains Mono (mono)
- Dark mode default
