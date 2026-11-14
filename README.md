# MCP Content Tools

**Give AI agents a safe, read-only window on your Drupal content through the [MCP Server](https://www.drupal.org/project/mcp_server) module: search, read as Markdown with a citation, see what changed, and pull the corpus incrementally for indexing.**

[![CI](https://github.com/walidsadfi-evolurise/mcp_content_tools/actions/workflows/ci.yml/badge.svg)](https://github.com/walidsadfi-evolurise/mcp_content_tools/actions/workflows/ci.yml)
![Drupal 10.3+ / 11](https://img.shields.io/badge/Drupal-10.3%2B%20%7C%2011-0678be)
![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb4)
![License GPLv2](https://img.shields.io/badge/license-GPLv2-green)

## The problem

Every team wiring an LLM to a Drupal site hits the same three walls: the agent needs to **find** the right page, **read** it without HTML noise and without seeing fields it should not, and a RAG pipeline needs to **sync** the whole site without re-fetching everything each night. JSON:API gives raw entities (every field, every relationship, HTML included) and no full-text search; scraping the front end loses structure and ignores access.

## What it does

Six MCP tools, discovered automatically by MCP Server (`/mcp` over HTTP, or `drush mcp:server` over STDIO):

| tool | what the agent gets |
|---|---|
| `site_map` | site name, base URL, languages, exposed content types with counts and fields, vocabularies — the vocabulary of the other tools |
| `search_content` | keyword search over title + text fields, optional type / language / taxonomy-term filter; id, URL, snippet, dates |
| `get_content` | one page by id, UUID, alias or URL — as **Markdown** (headings, lists, links, tables kept), plain text, or structured fields; with `content_hash`, canonical `url` and a `citation` string |
| `list_recent` | what changed since `7d` / a date / a timestamp |
| `get_taxonomy` | a vocabulary as a flat tree (id, name, depth, parent) |
| `export_content` | the corpus, page by page, ordered by change date with a stable cursor — **incremental sync for your index** (`since` + `next_cursor`), one document per translation, separate permission |

Everything is **access-checked for the account the MCP session runs as**: entity queries use `accessCheck(TRUE)`, every node is checked with `access('view')`, referenced entities and files too. On top of that, configuration narrows what *any* agent sees: content types allowlist, field exclusions (`field_internal_*`), published-only by default, size caps. A `hook_mcp_content_tools_field_exposed_alter()` covers the rest.

```jsonc
// get_content {"id": "/pricing", "langcode": "fr"}
{
  "id": 42, "type": "page", "title": "Tarifs", "url": "https://example.com/fr/tarifs",
  "langcode": "fr", "changed": "2026-11-02T09:14:00+00:00",
  "content": "# Tarifs\n\n## Body\n\nTrois formules…\n\n| Formule | Prix |\n| --- | --- |\n| Starter | 29 € |…",
  "content_format": "text/markdown", "content_hash": "9f2c…", "truncated": false,
  "citation": "Tarifs — https://example.com/fr/tarifs", "translations": ["en"]
}
```

## Install

```bash
composer require drupal/mcp_content_tools
drush en mcp_content_tools
```

1. **People → Permissions**: give the role your MCP clients authenticate as *Access MCP server* (from MCP Server) and *Use MCP content tools*. Give *Export content through MCP* only to the indexing account.
2. **Configuration → Web services → MCP Content Tools**: tick the content types to expose, list the fields to hide.
3. Point a client at `https://your-site/mcp` (OAuth via the MCP Server OAuth submodule, or cookie/session for a first try), or run `drush mcp:server --user=agent` for a local STDIO session.

Index the site from the command line with the same rules:

```bash
drush --user=indexer mcp-content-tools:export --since=1d > delta.jsonl
```

## Why this and not JSON:API

| | JSON:API / REST | MCP Content Tools |
|---|---|---|
| Full-text search | no | yes (title + text fields, term filter) |
| Output | every field, raw HTML, relationship links | Markdown document with citation, or curated fields |
| Field exposure | all fields the account can view | allowlist by configuration + hook |
| Incremental sync | filter on `changed` by hand, no stable cursor | `since` + `next_cursor` + `content_hash` |
| Agent orientation | read the schema | `site_map` tells it the types, fields, languages, vocabularies |

They are complementary: this module is the *reading* surface for agents. For *writing* through agents with editorial approval, see [Change Proposals](https://www.drupal.org/project/change_proposals).

## Development

```bash
# inside a Drupal project with the module and mcp_server installed
vendor/bin/phpunit -c web/core web/modules/contrib/mcp_content_tools
vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/contrib/mcp_content_tools
```

Unit tests cover the HTML→Markdown converter; kernel tests build real nodes, terms, aliases and users and assert search, allowlist, access, Markdown output, resolution by alias/URL/UUID and the export cursor. CI runs Drupal 10.3 and 11 on PHP 8.3/8.4 with SQLite.

## License

GPLv2 or later. Built by [Walid Sadfi](https://walidsadfi.com) — Forward Deployed Engineer, Drupal & AI agents.
