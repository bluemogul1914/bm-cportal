# PHP Playground

An online PHP code editor and execution environment built with React + Express.

## Architecture

- **Frontend**: React with Tailwind CSS, shadcn/ui components, wouter routing
- **Backend**: Express.js with PHP code execution via child_process
- **Database**: PostgreSQL with Drizzle ORM for snippet storage
- **Runtime**: PHP 8.2 installed via Nix for server-side code execution

## Features

- Write and execute PHP code in the browser
- Syntax-aware editor with line numbers
- Output panel with execution time
- Save snippets to a library
- Load and delete saved snippets
- Dark/light theme toggle
- Pre-seeded with example PHP snippets

## Structure

- `client/src/pages/playground.tsx` - Main code editor and execution page
- `client/src/pages/snippets.tsx` - Snippet library page
- `client/src/components/app-sidebar.tsx` - Navigation sidebar
- `client/src/components/theme-provider.tsx` - Theme context
- `server/routes.ts` - API endpoints (execute PHP, CRUD snippets)
- `server/storage.ts` - Database storage layer
- `server/db.ts` - PostgreSQL connection
- `server/seed.ts` - Seed data for example snippets
- `shared/schema.ts` - Drizzle schema and types

## API Endpoints

- `POST /api/execute` - Execute PHP code with sandboxing (disabled dangerous functions, restricted filesystem, rate limited)
- `GET /api/snippets` - List all saved snippets
- `POST /api/snippets` - Save a new snippet
- `DELETE /api/snippets/:id` - Delete a snippet

## Security

- PHP execution uses `disable_functions` to block shell/file/network operations
- `open_basedir` restricts filesystem access to temp directory only
- Rate limiting: 20 requests per minute per IP
- 10-second execution timeout and 64MB memory limit
- Code size limited to 50KB

## Theme

- Primary color: Purple/Indigo (258 72%)
- Font: Inter (sans), JetBrains Mono (mono)
- Dark mode default
