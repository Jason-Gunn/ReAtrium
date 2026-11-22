# ReAtrium

An out-of-the-box, modular, AI-forward SMB intranet and company hub built on **WordPress 7+** native AI systems, with seamless optional governance via **AIGIS** (`Jason-Gunn/aigis`) — bringing an owned, self-hosted "SMB Agent 365" experience to WordPress.

## Features (v1.0.0-alpha)

- **Public vs. Intranet Isolation Wall:** Keeps your public company website and blog open while isolating internal CPTs, taxonomies, REST routes, search, feeds, and sitemaps behind configurable WordPress role access.
- **Hybrid Employee Portal (`/intranet/`):** Clean, distraction-free employee workspace (with automatic Plain-permalink fallback `?reatrium_portal=1`), custom company branding, and `wp-admin` reserved for configuration and AIGIS governance.
- **Extensible Module SDK & Registry:** Built-in and community-contributed modules implement `ReAtrium_Abstract_Module` and register on `reatrium_register_modules`, automatically inheriting isolation, portal navigation, Settings toggles, WP Abilities discovery, and AIGIS agent sync.
- **WordPress 7+ Native AI & Abilities API:** Built on `wp_ai_client_prompt()` (`Settings → Connectors`) and structured WP Abilities (`reatrium/summarize-updates`, `reatrium/ask-handbook`, `reatrium/draft-sop`, `reatrium/find-expert`, `reatrium/triage-request`, `reatrium/draft-public-post`).
- **Built-in Core Modules:**
  - **Ask ReAtrium (AI Workspace Assistant):** Unified intranet assistant orchestrating all enabled intranet WP 7+ Abilities.
  - **Announcements & AI "Catch Me Up" Digest:** Pinned company updates + executive summary digest.
  - **Knowledge Base & SOPs:** Self-contained MySQL hybrid vector/lexical store (`ReAtrium_Vector_Store_Interface`), department-filtered Q&A with citations, and AI SOP drafting.
  - **People & Org Directory:** Role-scoped team roster with AI Expert Finder.
  - **Internal Requests & Helpdesk Triage:** Private IT/HR/Ops request queue with automated AI triage.
  - **Public Website & Blog AI Drafter:** Public-zone drafting tool strictly isolated from private intranet data and abilities.
- **Optional AIGIS v2 Governance:** Works standalone out of the box, or pairs automatically with AIGIS v2 for centralized agent registry, kill-switches, PII guardrails, zone/sensitivity DLP, monthly budgets, and immutable audit trails.

## Documentation & Extensibility

- **Building Community Modules:** See [docs/MODULE-SDK.md](docs/MODULE-SDK.md)
- **AIGIS v2 Integration Contract:** See [docs/AIGIS-INTEGRATION.md](docs/AIGIS-INTEGRATION.md)
- **Starter Add-on Plugin:** See [examples/reatrium-module-starter/](examples/reatrium-module-starter/) (working Employee Onboarding Checklists module)

## Requirements

- WordPress 7.0+
- PHP 8.1+
- Optional: [AIGIS (AI Governance & Infrastructure Suite)](https://github.com/Jason-Gunn/aigis)

## License

GPL v2 or later. See [LICENSE](LICENSE).
