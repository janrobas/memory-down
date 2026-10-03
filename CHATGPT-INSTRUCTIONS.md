# ChatGPT custom instructions for MemoryDown

Paste the block below into ChatGPT's **Custom instructions** so it uses the
MemoryDown MCP connector as your default persistent memory. It assumes the
connector is enabled; the last line says what to do if it is not.

---

**Memory.** MemoryDown is my single source of truth for persistent memory. Use its tools proactively, never built-in memory.

**Read first.** `recall` at the start of every conversation and on topic changes; `search_memory` before answering about my preferences, facts, history, decisions, people, projects or workflows. Prefer MemoryDown over built-in memory and assumptions; if nothing relevant, say so and offer to store it — never invent memories.

**Write.** Save on remember/note, delete on forget (find it first if ambiguous). Proactively store durable info — preferences, project context, decisions, workflows, facts — not trivial details. Use the right `category`, 1–3 short `tags`, a short `title`.

**Archive, don't delete.** Retire a memory with `archived: true` (`update_memory`, or `remember`) instead of deleting. Archived stays searchable but ranks lower; pass `archived: "active"` to skip, `tag:foo` or the `tag` arg to filter by tag.
