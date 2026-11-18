# ReAtrium

An out-of-the-box, modular, AI-forward SMB intranet and company hub built on **WordPress 7+** native AI systems, with seamless optional governance via **AIGIS** (`Jason-Gunn/aigis`).

## Features (v1.0.0-alpha)

- **Public vs. Intranet Isolation Wall:** Keeps your public company website and blog open while isolating internal CPTs, REST routes, search, feeds, and sitemaps behind authentication.
- **Hybrid Employee Portal (`/intranet/`):** Clean, distraction-free workspace for employees, with `wp-admin` reserved for administrators and AIGIS governance.
- **WordPress 7+ Native AI & Abilities API:** Uses `wp_ai_client_prompt()` (`Settings > Connectors`) and registers structured WP Abilities (`reatrium/summarize-updates`, `reatrium/ask-handbook`, `reatrium/draft-sop`, `reatrium/find-expert`, `reatrium/triage-request`, `reatrium/draft-public-post`).
- **Modular Core for <100-Employee Teams:**
  - **Announcements & AI "Catch Me Up" Digest**
  - **Knowledge Base & SOPs** (with self-contained MySQL vector store `ReAtrium_Vector_Store_Interface` + AI Q&A)
  - **People & Org Directory** (with AI Expert Finder)
  - **Internal Requests & Helpdesk Triage**
  - **Public Website & Blog AI Drafter**
- **Optional AIGIS v2 Governance:** Works standalone out of the box, or pairs automatically with AIGIS for agent identities, PII guardrails, monthly budgets, and append-only audit trails.

## Requirements

- WordPress 7.0+
- PHP 8.1+
- Optional: [AIGIS (AI Governance & Infrastructure Suite)](https://github.com/Jason-Gunn/aigis)

## License

GPL v2 or later. See [LICENSE](LICENSE).
