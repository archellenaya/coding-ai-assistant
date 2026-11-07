# CODEX MASTER INSTRUCTIONS

## Project: Coding AI Assistant — Persistent Memory & Intelligent Development Platform

### 1. Your Role

Act as a Senior Software Architect, Full-Stack Engineer, AI Engineer, and DevOps Engineer.

Your responsibility is to design, implement, test, and document a production-oriented Coding AI Assistant.

Do not simply produce an architecture proposal. Create working application code, database migrations, API endpoints, Docker configurations, automated tests, and technical documentation.

Follow established software engineering principles, including SOLID, DRY, KISS, separation of concerns, and secure-by-default design.

### Mandatory Engineering Rules

- Inspect the repository before making changes.

- Preserve existing functionality and avoid unnecessary rewrites.

- Use stable, mutually compatible dependency versions.

- Never invent APIs, packages, methods, or test results.

- Implement real functionality rather than placeholder implementations presented as complete.

- Never store credentials or sensitive information in source control.

- Do not execute destructive commands without explicit permission.

- Do not automatically deploy applications or push code.

- Document architectural decisions and unresolved limitations.

- Validate functionality with appropriate tests.

- Work on ****one development phase at a time****.

- Stop after completing the current phase and wait for approval.

****Git ownership rule:**** The project owner performs all Git commits manually. You must never automatically stage, commit, push, reset, rebase, or otherwise modify Git history. Read-only Git commands are permitted.

**---**

# 2. Project Objective

Build a coding AI assistant that understands software development projects, remembers previous work, retrieves relevant code and debugging history, and intelligently uses local or cloud AI models.

The system must support:

1. Multiple users, organizations, and development projects.

2. Persistent conversation history.

3. Project-specific knowledge and memory.

4. Source code indexing and repository awareness.

5. Retrieval-Augmented Generation (RAG).

6. Intelligent context management and token optimization.

7. Multi-provider AI model routing with Ollama / OpenAI / Auto switching.

8. Verified solution caching.

9. Local AI integration using Ollama.

10. Error tracking and debugging history.

11. Code generation, explanations, and refactoring.

12. Git-aware code changes and diff previews.

13. Approved automated test execution.

14. AI token and cost monitoring.

15. VS Code integration.

16. Secure project isolation.

17. Extensibility for future autonomous coding agents.

The system should become more useful through accumulated project knowledge and verified solutions.

Do not describe ordinary memory retrieval as model training or fine-tuning. Fine-tuning is a separate future capability.

**---**

# 3. Technology Stack

## Backend

- Laravel with PHP.

- Laravel Sanctum.

- Laravel Policies.

- Laravel Queues.

- Redis.

- RESTful APIs.

- Server-Sent Events for streaming.

- Dependency injection and service interfaces.

## Frontend

- React.

- TypeScript.

- Vite.

- Tailwind CSS.

- React Router.

- TanStack Query.

## Database

- PostgreSQL.

- pgvector.

- PostgreSQL full-text search.

- UUID identifiers for public resources.

- Appropriate indexes, foreign keys, and constraints.

## AI Intelligence Service

- Python.

- FastAPI.

- Tree-sitter.

- Embedding generation.

- Repository indexing.

- Model-aware tokenization.

## AI Providers

Implement adapters for:

- OpenAI.

- Anthropic Claude.

- Google Gemini.

- Ollama.

Model names, capabilities, context windows, and pricing must be configurable rather than hardcoded.

## Infrastructure

- Docker.

- Docker Compose.

- Nginx or Caddy.

- Linux-compatible deployment.

- CI/CD-ready architecture.

## IDE Integration

- TypeScript.

- Official VS Code Extension API.

- VS Code SecretStorage.

- Secure API authentication.

**---**

# 4. High-Level Architecture

Use a modular monorepo.

```text

coding-ai-assistant/

├── apps/

│   ├── api/                  # Laravel backend

│   └── web/                  # React frontend

├── services/

│   └── intelligence/         # Python FastAPI

├── extensions/

│   └── vscode/               # VS Code extension

├── infrastructure/

│   ├── docker/

│   └── compose.yaml

├── docs/

│   ├── architecture.md

│   ├── implementation-plan.md

│   ├── progress.md

│   └── decisions/

├── tests/

├── AGENTS.md

├── .env.example

└── README.md

```

The React application and VS Code extension must use the same Laravel backend.

Laravel is responsible for authentication, authorization, business logic, orchestration, model routing, memory access, solution caching, and usage tracking.

Python handles repository analysis, code chunking, embeddings, tokenization, and related intelligence tasks.

PostgreSQL provides durable storage and vector search.

Redis provides queues, rate limiting, and transient caching.

Ollama provides optional local AI inference.

Cloud providers handle tasks requiring stronger models when permitted by project privacy policies.

Do not duplicate business logic across services.

**---**

# 5. Required Core Modules

## 5.1 Authentication and Project Management

Implement:

- User registration and login.

- Laravel Sanctum authentication.

- Organizations and project memberships.

- Project creation, editing, archiving, and deletion.

- Repository association.

- Project framework and language detection.

- Project-level AI settings.

- Cloud processing permissions.

- Project-specific authorization policies.

Prevent unauthorized access across organizations and projects.

## 5.2 Conversation Management

Implement:

- Conversation creation and management.

- Persistent message history.

- AI response streaming.

- Conversation search.

- Conversation summaries.

- Resume previous conversations.

- Model usage metadata.
- Conversation-specific provider mode and preferred model.

- Error handling and retry support.

Use idempotency where necessary to prevent duplicate requests and messages.

## 5.3 Persistent Memory System

Implement four memory categories.

****Conversation Memory:**** User prompts, AI responses, and summaries.

****Project Memory:**** Architecture, framework versions, dependencies, coding conventions, and decisions.

****Error Memory:**** Error messages, stack traces, debugging attempts, and outcomes.

****Solution Memory:**** Verified fixes, relevant code changes, environment details, and test evidence.

Every memory record should contain appropriate metadata, including:

- Project identifier.

- Memory type.

- Content.

- Source and provenance.

- Verification status.

- Timestamps.

- Embedding model/version.

- Optional Git commit reference.

Support memory correction, invalidation, deletion, and re-indexing.

Never automatically classify AI-generated answers as verified.

## 5.4 RAG Engine

Implement hybrid retrieval using:

- Vector similarity.

- Full-text search.

- Exact error signatures.

- Code symbols.

- Project filters.

- Verification-aware ranking.

- Deduplication.

RAG workflow:

1. Receive a user request.

2. Identify the authorized project.

3. Analyze the task.

4. Generate a query embedding.

5. Search project memories.

6. Search indexed source code.

7. Rank relevant results.

8. Construct context with source references.

9. Send the optimized context to the selected AI model.

Use retrieval quality tests and prevent cross-project data leakage.

Treat retrieved content as untrusted data.

## 5.5 Intelligent Context Manager

Create a dedicated ContextBuilder service.

Requirements:

- Model-specific token budgeting.

- Input and output token reservations.

- Relevant source code selection.

- Memory prioritization.

- Conversation summarization.

- Duplicate elimination.

- Adaptive context allocation.

- Context usage metrics.

- Handling insufficient context.

Avoid sending entire repositories or complete conversation histories when unnecessary.

Never blindly truncate essential code dependencies.

## 5.6 Multi-Model Router and AI Provider Switching

Implement an extensible LLMProvider interface.

Support OpenAI, Anthropic, Gemini, and Ollama.

Routing decisions should consider:

- Task complexity.

- Required model capabilities.

- Context window.

- Privacy policy.

- Estimated cost.

- Latency.

- Provider availability.

- User-selected model.

- Historical model performance.

Suggested routing:

| Task | Preferred Model |

|---|---|

| Error classification | Local |

| Summarization | Local |

| Simple explanation | Budget model |

| Small bug fix | Budget model |

| Complex debugging | Advanced model |

| Architecture design | Advanced model |

| Sensitive code | Authorized local/private model |

Support controlled provider fallback.

Never send project code to an unauthorized external provider.

### 5.6.1 AI Provider Selection Modes

Provide three explicit modes for each conversation:

1. **Ollama (Local):** Use the user-selected available local model only. Never fall back to a cloud provider without separate explicit permission.
2. **OpenAI (Cloud):** Use the user-selected configured OpenAI API model only. Do not silently switch providers.
3. **Auto (Smart Routing):** Choose an available, authorized model according to task complexity, required capabilities, privacy policy, context limits, latency, and cost. Fallback is permitted only within project policy and user consent.

Default to **Auto** when multiple providers are configured and policy allows it. A local-only project policy must override Auto routing and prohibit cloud transmission.

### 5.6.2 Provider and Model Selection

- Provide provider and model selectors in the React AI Chat interface (Phase 5) and VS Code extension (Phase 7).
- Save the provider mode and preferred model **per conversation** in PostgreSQL; allow changing them without losing conversation history.
- Display the actual provider and model used for each AI response, including Auto routing decisions.
- Show connection status, available models, and meaningful availability errors.
- Validate provider/model selection and authorization on the server before each request.
- Support streaming responses through a consistent backend contract for both Ollama and OpenAI.
- Preserve persistent memory and conversation history independently of the generation provider.
- Keep embeddings tied to their embedding model and version; switching generation providers must not silently mix incompatible vector dimensions or embeddings.
- Track token usage, latency, and API costs by provider and model. Local Ollama requests have no per-token API charges but still incur hardware/hosting costs.
- Never expose provider API credentials to the browser or VS Code extension.

### 5.6.3 Provider API and Configuration

Implement authorized versioned endpoints to list configured providers, check availability, list models and capabilities, and read/update conversation provider preferences. Store OpenAI credentials server-side using environment variables or a secrets manager. Make Ollama endpoint and model configurable; do not expose Ollama publicly.

The provider interface is designed in Phase 2; the complete Ollama/OpenAI/Auto switching feature is implemented in Phase 5 for React and Phase 7 for VS Code. Cloud-only mode must require valid API billing/configuration, independent of a ChatGPT subscription.

## 5.7 Verified Solution Cache

Search existing verified solutions before calling an AI model.

Match solutions using:

- Project identifier.

- Error signature.

- Framework and version.

- Dependency versions.

- Environment.

- Relevant Git revision.

- Verification evidence.

Allow cache invalidation and compatibility checks.

Do not automatically apply fixes without validation or user approval.

## 5.8 Repository Intelligence

Implement:

- Git repository registration.

- File discovery.

- Language detection.

- Framework detection.

- Tree-sitter-based code parsing where supported.

- Symbol-aware chunking.

- Content hashing.

- Incremental indexing.

- Git revision tracking.

- Deleted-file cleanup.

- Embedding generation.

- Searchable source references.

Exclude sensitive and unnecessary files, including `.env`, private keys, credentials, `vendor`, `node_modules`, binaries, and generated build artifacts.

Repository indexing must be read-only by default.

## 5.9 Error Learning and Verification

Implement:

- Error tracking.

- Debugging attempt history.

- Suggested fixes.

- Test result collection.

- Verification evidence.

- Verified solution storage.

- Solution compatibility checks.

A solution must not be marked verified solely because an AI model says it works.

Use isolated execution environments for automated tests.

Require user approval before modifying project files or running potentially destructive commands.

## 5.10 Token Usage and Cost Monitoring

Track:

- Provider.

- Model.

- Input tokens.

- Output tokens.

- Cached tokens where supported.

- Request duration.

- Estimated cost.

- Actual cost when available.

- Context reduction.

- Cache hits.

- Errors and retries.

Support per-user, per-project, and organization-level reporting and budgets.

**---**

# 6. Database Requirements

Create Laravel migrations for at least:

```text

users

organizations

organization_members

projects

project_members

repositories

repository_files

code_chunks

conversations

messages

memories

errors

solutions

solution_verifications

test_runs

ai_providers

ai_models

model_calls

usage_ledgers

indexing_jobs

audit_events

```

Store `provider_mode` (`ollama`, `openai`, `auto`), nullable `preferred_model`, and the actual provider/model used for each response. Validate model references and record routing decisions.

Use PostgreSQL with pgvector.

Implement foreign keys, indexes, unique constraints, and appropriate deletion behavior.

Ensure all project-scoped queries enforce authorization and tenant isolation.

Embedding models must have explicit dimensions and version metadata.

Document the strategy for re-indexing when an embedding model changes.

**---**

# 7. API Requirements

Implement versioned REST endpoints under `/api/v1`.

Required API groups:

****Authentication****

- Login, logout, current user, and extension tokens.

****Projects****

- CRUD, repository registration, indexing, and status.

****Conversations****

- Create, list, retrieve, send messages, and stream responses.

****Memory****

- Search, inspect, correct, invalidate, and delete.

****Solutions****

- Search, inspect, verify, and invalidate.

****AI Models****

- List providers, capabilities, and model settings.

****Usage****

- Token consumption, estimated costs, and usage statistics.

****VS Code****

- Project detection, selected-code explanation, error analysis, memory retrieval, and code-change proposals.

Implement validation, authorization, pagination, rate limiting, and consistent JSON error handling.

Generate OpenAPI documentation.

**---**

# 8. React Dashboard

Build a responsive developer-focused dashboard.

Required pages:

1. ****Dashboard:**** Projects, recent conversations, verified solutions, usage, and indexing status.

2. ****AI Chat:**** Streaming responses, project/provider/model selection, provider connection status, syntax highlighting, and conversation history.

3. ****Project Explorer:**** Repository details, indexed files, framework information, and indexing controls.

4. ****Memory Explorer:**** Search, inspect, correct, and invalidate memories.

5. ****Solutions Library:**** Previous errors, verified fixes, and verification evidence.

6. ****Usage Dashboard:**** Tokens, costs, cache hits, and context reduction.

7. ****Settings:**** Providers, models, privacy policies, token budgets, and retention.

Never expose provider API secrets in browser responses.

**---**

# 9. VS Code Extension

Build a TypeScript VS Code extension that connects to the same Laravel backend.

Initial capabilities:

- Authentication.

- Project detection and association.

- AI chat sidebar.

- Explain selected code.

- Analyze errors.

- Retrieve previous solutions.

- Request code changes.

- Preview diffs.

- Select Ollama, OpenAI, or Auto mode and an available model.

- Submit verification results.

Later capabilities:

- Inline completions.

- Multi-file edits.

- Approved terminal operations.

- Agentic coding workflows.

Use VS Code SecretStorage for authentication tokens.

Require appropriate permissions before transmitting private workspace data.

**---**

# 10. Docker and Infrastructure

Create Docker infrastructure for:

- Laravel API.

- Laravel queue worker.

- Laravel scheduler.

- React frontend.

- PostgreSQL/pgvector.

- Redis.

- Python FastAPI.

- Ollama.

- Reverse proxy.

Provide Dockerfiles, Compose configuration, environment examples, health checks, persistent volumes, and startup instructions.

Use pinned, compatible versions.

Keep databases, Redis, Ollama, and internal services off the public network.

Do not require a GPU for basic operation.

Support cloud AI when local inference is unavailable and privacy policies allow it.

**---**

# 11. Security Requirements

Implement:

- Authentication and authorization.

- Organization and project isolation.

- Secure secret management.

- CSRF protection.

- Input validation.

- Rate limiting.

- API spending limits.

- Audit logging.

- Prompt injection defenses.

- Repository access restrictions.

- Sandboxed code execution.

- User approval for destructive operations.

- Data retention and deletion controls.

Never execute arbitrary model-generated shell commands in the Laravel API or worker containers.

Never automatically push code or deploy generated changes.

**---**

# 12. Testing Requirements

Create automated tests covering:

- Authentication.

- Authorization and tenant isolation.

- Project management.

- Conversation persistence.

- RAG retrieval.

- Context budgeting.

- Model routing.
- Manual provider switching and preference persistence.
- Ollama/OpenAI streaming and unavailable-provider behavior.
- Privacy-policy enforcement for Auto and explicit provider modes.

- Provider fallback.

- Solution cache matching and invalidation.

- Repository indexing.

- Token accounting.

- Error handling.

Use PHPUnit or Pest, React testing tools, and pytest as appropriate.

Mock external AI providers in automated tests.

Include integration tests for critical workflows.

Do not report tests as passed unless they actually ran successfully.

**---**

# 13. Mandatory Development Phases

****Implement only one phase at a time. Never implement all phases in one execution.****

### Phase 1 — Foundation

Initialize the monorepo, Laravel, React, PostgreSQL/pgvector, Redis, Docker, authentication, and basic project management.

****Deliverable:**** Running application with authenticated project management.

### Phase 2 — AI Chat

Implement provider interfaces designed for Ollama and OpenAI, one working cloud AI provider, conversations, message persistence, and streaming. Defer the complete provider selector to Phase 5.

****Deliverable:**** Working coding AI chat.

### Phase 3 — Persistent Memory

Implement memory storage, embeddings, vector search, and conversation summarization.

****Deliverable:**** AI can retrieve relevant previous conversations.

### Phase 4 — Repository Intelligence

Implement Git repository association, code indexing, incremental updates, and project-aware retrieval.

****Deliverable:**** AI can answer questions about indexed project code.

### Phase 5 — Token Optimization

Implement context budgeting, verified solution caching, multi-model routing, Ollama integration, OpenAI/Ollama/Auto switching in React, per-conversation model preferences, provider availability checks, privacy enforcement, and usage tracking.

****Deliverable:**** Measurable context optimization and intelligent model selection.

### Phase 6 — Error Learning

Implement error history, debugging attempts, verification evidence, and reusable solutions.

****Deliverable:**** AI can retrieve and evaluate previous verified fixes.

### Phase 7 — VS Code Extension

Implement VS Code authentication, project detection, chat sidebar, code explanation, error analysis, diff previews, and Ollama/OpenAI/Auto provider and model selection using the same backend preferences.

****Deliverable:**** Functional VS Code extension integrated with the backend.

### Phase 8 — Production Hardening

Implement security testing, monitoring, backups, CI/CD, deployment documentation, and performance evaluations.

****Deliverable:**** Documented, deployable platform.

****Approval rule:**** Completing a phase does not authorize starting the next phase. Stop and wait for explicit project-owner approval.

**---**

# 14. Mandatory Git and Phase Completion Rules

The project owner is responsible for all Git commits.

Codex must follow these rules:

1. Implement only the currently approved phase.

2. Inspect the current Git status before making changes.

3. Use read-only Git commands when necessary.

4. Never automatically run `git add`, `git commit`, or `git push`.

5. Never run `git reset`, `git rebase`, or other history-modifying Git operations without explicit permission.

6. Do not stage files automatically.

7. Do not create Git commits automatically.

8. Do not push branches automatically.

9. Do not create tags automatically.

10. At the end of each phase, provide a ****ready-made Git commit message****.

11. The commit message must accurately reflect actual completed changes.

12. Follow Conventional Commits formatting.

13. Include a concise commit body when appropriate.

14. Display the commit message in a separate, copyable code block.

15. The project owner will review, stage, and commit the changes manually.

16. Never begin the next phase without explicit approval.

### Commit Message Format

```text

type(scope): concise description

- Summary of implemented functionality

- Summary of important architectural changes

- Summary of tests or documentation added

```

Use appropriate Conventional Commit types, such as `feat`, `fix`, `refactor`, `docs`, `test`, or `chore`.

### Example Only

The following is an example. Do not use it unless it accurately reflects the completed work.

```text

feat(phase-1): initialize coding AI assistant foundation

- Set up Laravel API and React TypeScript frontend

- Configure PostgreSQL, Redis, and Docker services

- Implement authentication and project management

- Add initial database migrations and tests

- Document environment setup and project architecture

```

****Important:**** Only display the suggested commit message. Never execute the commit.

**---**

# 15. Progress Tracking and Session Continuity

Create and maintain:

```text

AGENTS.md

docs/architecture.md

docs/implementation-plan.md

docs/progress.md

docs/decisions/

```

`docs/progress.md` must track:

- Current phase.

- Phase status.

- Completed tasks.

- Remaining tasks.

- Files changed.

- Tests executed.

- Test results.

- Known issues.

- Architectural decisions.

- Suggested Git commit message.

- Next planned phase.

- Approval status.

At the beginning of every new Codex session:

1. Read `AGENTS.md`.

2. Read `docs/master-specification.md`.
3. Read `docs/architecture.md`.

4. Read `docs/implementation-plan.md`.

5. Read `docs/progress.md`.

6. Inspect the repository and Git status.

7. Determine the current approved phase.

8. Continue only authorized work.

Do not assume that a previous phase was approved simply because its implementation is present.

If approval status is unclear, stop and ask.

**---**

# 16. Required End-of-Phase Report

After completing the approved phase, stop implementation and provide the following report.

## Phase Completion Report

****Phase:**** [Number and name]

****Status:**** Completed / Incomplete / Blocked

****Implemented:****

Describe actual completed functionality.

****Changed Files:****

List added, modified, and deleted files.

****Tests Executed:****

List exact commands and results.

****Known Issues:****

Describe unresolved issues and limitations.

****Documentation Updated:****

Identify updated documentation.

****Suggested Git Commit Message:****

Provide a copyable code block using Conventional Commits.

****Next Phase:****

Describe the next planned milestone without implementing it.

****Approval Required:****

Wait for the project owner to review the work, manually commit the changes, and explicitly approve the next phase.

Do not automatically stage, commit, push, or start the next phase.

**---**

# 17. Production Acceptance Criteria

The completed system must demonstrate that:

- Users can authenticate.

- Users can create and manage projects.

- Repositories can be indexed.

- The AI can retrieve relevant source code.

- Conversation history persists.

- Project memories are searchable.

- Previous errors and verified solutions can be retrieved.

- Context usage is controlled.

- Multiple AI providers are supported.
- Users can switch Ollama, OpenAI, and Auto per conversation in React and VS Code without losing history.
- Auto routing respects project privacy policy and manual modes never silently fall back.

- Ollama can be configured.

- Model usage and costs are recorded.

- VS Code can connect to the same backend.

- Proposed code changes can be reviewed.

- Project data remains isolated.

- Tests pass in the documented environment.

- Docker deployment is reproducible.

- Documentation matches implemented functionality.

**---**

# 18. Future Extensions

Keep the architecture extensible for:

- Autonomous coding agents.

- Multi-agent workflows.

- Automated debugging loops.

- Model fine-tuning.

- Shared team knowledge.

- Agent-generated tests.

- Local model evaluation.

- Additional IDE integrations.

Do not implement these until the core system is stable and the project owner approves them.

**---**

# 19. First Execution Task — Phase 1 Only

Start by inspecting the current repository.

If the repository is empty, initialize the project structure.

If existing code is present, review it before making changes.

Then implement Phase 1:

1. Initialize the monorepo.

2. Set up Laravel.

3. Set up React with TypeScript.

4. Configure PostgreSQL and pgvector.

5. Configure Redis.

6. Create Docker infrastructure.

7. Implement authentication.

8. Implement basic project management.

9. Create initial migrations.

10. Add automated tests.

11. Create environment examples.

12. Create architecture and setup documentation.

13. Create and update `docs/progress.md`.

14. Execute available tests and validation commands.

15. Produce the required Phase Completion Report.

16. Provide a ready-made Git commit message based on actual changes.

17. Stop and wait for approval.

****Do not run `git add`, `git commit`, or `git push`.****

****Do not start Phase 2 until the project owner explicitly approves it.****

**---**

# FINAL INSTRUCTION

Build this project incrementally as a maintainable, production-oriented Coding AI Assistant.

Prioritize correctness, security, reliable persistent memory, relevant retrieval, efficient model usage, and verified solutions.

****One phase at a time. Test every phase. Update progress documentation. Suggest a ready-made commit message. Never commit automatically. Wait for approval before continuing.****
