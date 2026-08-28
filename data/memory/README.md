# Memory directory

This directory IS the memory store: Markdown files are the source of truth for
MemoryDown. Keep it portable — it can be opened in Obsidian, VS Code, Codex or
any text editor.

Layout:

    memory/
      preferences/   durable user preferences
      projects/      ongoing project context
      decisions/     decisions and their reasoning
      facts/         general durable facts
      people/        information about people
      context/       long-term situational context

Each entry is `{category}/{slug-id}.md` with simple frontmatter:

    ---
    type: facts
    tags:
      - example
    created: 2026-08-27
    updated: 2026-08-27
    source: chatgpt
    id: example-abc123
    ---

    # Title

    Body as Markdown.

Edit freely: MemoryDown reads whatever it finds. Entries without frontmatter
are treated as plain Markdown.
