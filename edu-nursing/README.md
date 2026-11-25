# Edu Nursing – Medical Education Academy

## Overview
This repository contains the full‑stack e‑learning platform **Edu Nursing** built with **Next.js (React + TypeScript)**, **Supabase (PostgreSQL + Auth)** and **Docker** for local development.

## Quick Start (Local Development)
```bash
# 1️⃣ Clone the repo (if you haven't already)
# git clone <repo‑url>

# 2️⃣ Install dependencies
npm install

# 3️⃣ Start Supabase (PostgreSQL + Auth) & the Next.js app via Docker Compose
docker compose up -d

# 4️⃣ Run the dev server
npm run dev
```
The app will be available at `http://localhost:3000` and Supabase UI at `http://localhost:8000`.

## Project Layout
```
edu-nursing/
├─ apps/
│   └─ web/                # Next.js application (frontend + API routes)
│       ├─ pages/          # Next.js pages (public site, dashboard, admin)
│       ├─ public/         # Static assets (fonts, images)
│       └─ src/            # React components, hooks, utils
├─ packages/
│   ├─ ui/                 # Re‑usable UI component library (Tailwind + styled‑components)
│   └─ utils/              # Helper functions, API client, validation
├─ infra/
│   ├─ docker-compose.yml  # Docker services for dev (app, supabase)
│   └─ supabase/           # Supabase migrations & RLS policies
├─ scripts/
│   └─ seed-data.ts        # Generates demo courses, books, users
├─ .env.local              # Environment variables (Supabase keys, etc.)
├─ .gitignore
├─ package.json
├─ tsconfig.json
└─ next.config.js
```

## Environment Variables
Create a `.env.local` file in the project root with the following keys (values taken from your Supabase project):
```
NEXT_PUBLIC_SUPABASE_URL=https://<your‑project>.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=xxxxxxxxxxxx
SUPABASE_SERVICE_ROLE_KEY=yyyyyyyyyyyy
```

## Development Scripts
- `npm run dev` – Start Next.js in development mode.
- `npm run build` – Build the production bundle.
- `npm run start` – Run the production build.
- `npm run lint` – Run ESLint.
- `npm run type-check` – Run TypeScript type checking.

## License
MIT © Edu Nursing Team
