# Architecture

## Implemented foundation

A modular monorepo with Laravel 12 / PHP 8.3 in `apps/api` and React / TypeScript / Vite in `apps/web`. Dependencies are resolved in Composer and npm lockfiles. Nginx serves the compiled SPA and proxies `/api`, `/sanctum`, and `/up` to PHP-FPM on one origin. React Router controls navigation; TanStack Query owns server state; Tailwind provides styling.

PostgreSQL 17 stores durable records; the foundation migration enables pgvector. Redis stores sessions, cache, throttles and queued work. API, queue worker and scheduler share an image. Internal Docker networking exposes only Nginx on loopback port 8080. Named volumes persist PostgreSQL, Redis and Laravel storage. No GPU is required.

## Authentication and authorization

The SPA obtains `/sanctum/csrf-cookie` and sends the decoded XSRF token in mutation headers. Sanctum stateful middleware uses encrypted HttpOnly session cookies; login rotates the session, logout invalidates it. Browser sessions require an allowed Origin/Referer. Public auth is limited to five requests per minute per IP, and APIs to 120 per user/IP. Passwords are hashed; password fields are hidden from serialization. API failures use Laravel JSON `message` and optional validation `errors`.

Registration atomically creates a user, an organization and owner membership. Phase 1 supports listing the user's organizations, not organization administration or invitations. A project creator becomes its initial project member. All project listings and reads require organization AND project membership; `ProjectPolicy` governs individual resources. Only organization owners with project membership can update, archive, restore or delete. Client input cannot reassign a project's organization. Deletion cascades project membership. UI confirmation precedes deletion.

## Data model

Users, organizations and projects have UUID primary keys. Membership pivots use composite primary keys and cascading foreign keys. Projects store name, nullable description, cloud-processing permission (false by default), archive timestamp and timestamps. Organization/creation-time indexes support scoped pagination (20 items per page). Laravel's sessions, cache, queue and Sanctum infrastructure tables are included. Successful API mutations record actor, route template, optional project reference and timestamp in an audit ledger within the mutation transaction; request bodies, passwords and session tokens are never recorded. Remaining master-specification tables belong to their owning phases.

## Future boundaries (unimplemented)

`services/intelligence` is reserved for FastAPI, Tree-sitter, embeddings and tokenization. `extensions/vscode` is reserved for the Phase 7 client. Neither runs in Phase 1. Laravel will own provider interfaces, routing, ContextBuilder, memory access, verification and spending controls. Python must not duplicate these rules. Ollama and cloud adapters are deferred until their approved phases.

Embeddings will record model identifier, version and dimension. A model change requires a new index generation, a full background re-embedding and evaluated cutover; never compare mixed embedding spaces. Retrieval must filter by authorized project before ranking.

## Operational limits

This is a local foundation, not a production release. TLS, production secret provisioning, backups, observability, retention administration and security/performance acceptance remain Phase 8. No AI calls, code execution, repository access or external code transmission exists. See decisions and progress for exact verification evidence and limitations.

Implementation references: [Sanctum](https://laravel.com/docs/12.x/sanctum), [Vite setup](https://vite.dev/guide/).
