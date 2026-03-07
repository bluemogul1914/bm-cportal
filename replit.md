# PHP Playground / Blue Mogul Portal

## Overview

This project is an online PHP code editor and execution environment combined with a comprehensive client portal and administrative panel for Blue Mogul, an IT services company. The portal aims to streamline client management, billing, support, and project tracking, while providing robust tools for internal operations and AI agent integration. The PHP Playground offers an interactive development and testing environment for PHP code.

## User Preferences

I want iterative development and detailed explanations of changes. Ask before making major architectural changes or introducing new external dependencies.

## System Architecture

The application is built with a React frontend using Tailwind CSS and shadcn/ui components, and an Express.js backend. PostgreSQL with Drizzle ORM is used for data persistence. PHP 8.2 handles server-side code execution.

**Frontend:**
- React, Tailwind CSS, shadcn/ui, wouter routing.
- Client Portal: Dashboard, ticketing, billing, service management, product catalog, document management, profile settings, VoIP services, chat, and project tracking.
- Admin Portal: Comprehensive CRM-style client management, ticket management, invoicing, product/service management, network documentation, knowledge base, AI agent command center, automation, reporting, system settings, and various third-party integrations dashboards.
- Shared Components: Client and admin sidebars, email helpers, configuration, authentication handlers, and database initialization.
- Branding: Primary color #1a56db, secondary #0d1b3e, accent #3b82f6, Inter font, custom logo.

**Backend:**
- Express.js manages API endpoints, PHP code execution via `child_process`, and session bridging.
- Drizzle ORM for database interactions.
- PHP execution is sandboxed with `disable_functions` to prevent shell/file/network operations.
- Security: CSRF protection, login rate limiting, session regeneration, and comprehensive security headers (X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy, Content-Security-Policy).
- API Endpoints:
    - PHP execution and snippet management.
    - Stripe integration for payments and subscriptions.
    - Webhooks for AI Agent integration (logging, ticket creation, device updates, notifications, project management).
    - Dedicated API for AI Agent management (listing, metrics, activity, ROI, triggering).
    - Project management API.

**Database Schema:**
- Core tables: `users`, `clients`, `products`, `subscriptions`, `invoices`, `tickets`, `ticket_comments`, `ticket_time_entries`, `documents`, `payments`, `activity_log`, `agent_logs`, `agent_config`, `agent_metrics`, `system_settings`, `network_devices`, `network_credentials`, `knowledge_articles`, `notifications`, `projects`, `project_tasks`, `project_notes`, `snippets`, `invoice_footer_templates`, `project_time_entries`.
- Integration-specific tables: `vultr_instances`, `action1_endpoints`, `action1_alerts`, `crm_leads`, `crm_campaigns`, `crm_meetings`, and Stripe-related schemas.

## External Dependencies

- **Stripe**: Payment processing and subscription management (via Replit Connector, OAuth-managed).
- **Action1 RMM**: Remote Monitoring and Management via REST API.
- **Vultr**: Cloud instance management and billing.
- **N8N**: Automation workflow integration for AI agents.
- **VoIP.ms**: VoIP services integration.
- **UISP**: Network device management.
- **Nextcloud**: File sharing integration.
- **Uptime Kuma & Grafana**: Monitoring dashboards (external tools, linked).
- **HubSpot**: CRM lead synchronization via CRM API v3 (contacts endpoint with HUBSPOT_TOKEN).
- **Coolify, Matrix, ITFlow, Ollama, Flowise, AnythingLLM**: Referenced in `config.php` for potential future or existing integrations (via environment variables).