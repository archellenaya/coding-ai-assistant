# ADR 0001: Foundation boundaries and tenancy

Date: 2026-11-07. Status: Accepted for Phase 1.

Start from the specification-only repository. Use Laravel 12 on available PHP 8.3, locked dependencies, React TypeScript and same-origin Nginx delivery. Sanctum cookie sessions avoid browser token storage and preserve CSRF protection. Future extension token issuance is Phase 7.

Register a personal organization and owner membership atomically. Require both organization and project membership, and owner role for mutations. Invitations and membership administration are outside basic Phase 1 project management. UUIDs identify public resources.

Install pgvector now but introduce embedding columns, dimensions and indexing only with Phase 3. Do not prebuild future tables or dormant AI services. Reserve directories for FastAPI and VS Code; add their containers when implemented. Redis worker and scheduler processes are ready, with no AI jobs or schedules yet.

Use SQLite for fast feature tests and a separate PostgreSQL test database for integration checks. Document each actual test run. Local Compose binds only to loopback and must not be described as production hosting.
