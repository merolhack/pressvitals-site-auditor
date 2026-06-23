# PressVitals Site Auditor - Agent Schema

This document defines the schema and operating rules for any LLM agent interacting with the PressVitals Site Auditor project. It follows the principles of the [LLM-Wiki pattern](https://gist.github.com/karpathy/442a6bf555914893e9891c11519de94f).

## Wiki Architecture
This repository implements the LLM-Wiki structure to manage project knowledge alongside code:
1.  **[index.md](index.md)**: The content catalog. Agents must read this to understand the layout of the repository and the wiki.
2.  **[HISTORY.md](HISTORY.md)**: Acts as the chronological `log.md`. Every significant session, ingestion, or structural change must be appended here.
3.  **[AGENTS.md](AGENTS.md)**: This file. It is the schema that dictates agent behavior.
4.  **[LLM_WIKI.md](LLM_WIKI.md)**: The central knowledge base detailing architecture, localization rules, and local environment quirks.

## Operating Procedures
When answering questions, building features, or fixing bugs, agents must follow this workflow:

1.  **Consult the Wiki:** Review `LLM_WIKI.md` for architectural constraints (e.g., how the Docker environment functions, WP.org translation rules, direct access protection).
2.  **Analyze the Log:** Check `HISTORY.md` to see recent changes and ensure you don't overwrite recent fixes (like the UI color improvements or the `wp-config.php` permissions logic).
3.  **Execute Safely:**
    *   Do not test changes by running `composer` in the raw WSL environment; use the Docker containers.
    *   Be aware of `chmod` and file permission differences between WSL and the Docker volume mounts.
4.  **Update the Wiki:** After solving a problem or making a significant code change, append a record of the change to `HISTORY.md`. If a new architectural rule is discovered, integrate it into `LLM_WIKI.md`.
5.  **Release Workflow:** The standard `zip` utility is missing in WSL. To build a release package, utilize the isolated Python `shutil.make_archive` script mechanism that honors `.distignore`.
