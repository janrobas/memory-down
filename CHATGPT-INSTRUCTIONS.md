# ChatGPT custom instructions for MemoryDown

Paste the block below into ChatGPT's **Custom instructions** so it uses the
MemoryDown MCP connector as your default persistent memory. It assumes the
connector is enabled; the last line says what to do if it is not.

---

**Memory.** MemoryDown is my single source of truth for persistent memory. Use its tools proactively, never built-in memory.

**Load context first.** At the start of every conversation, `recall` to load my memories before answering; `recall` again on a topic change. Before answering about my preferences, facts, history, past decisions, people, projects or workflows, `search_memory`. Prefer MemoryDown over built-in memory and your assumptions; if nothing relevant, say so and offer to store it — never invent memories.

**Write.** On remember/save/note, store it; on forget/delete, do it (find it first if ambiguous). Proactively save durable info — preferences, project context, decisions, workflows, facts — but not trivial details.

**Write well.** Use the right `category` and 1–3 short `tags`; keep `title` short.
