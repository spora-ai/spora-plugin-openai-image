---
name: example-skill
description: "A worked example skill for Spora plugin authors. Use as a starting template for plugin-bundled skills, or delete this directory if your plugin doesn't ship any skills."
license: Apache-2.0
compatibility: Designed for Spora plugins that bundle a SKILL.md alongside their code.
metadata:
  author: spora-ai
  version: "1.0"
---

# Example skill

This skill is the **plugin-skeleton equivalent of the `time-arithmetic` skill** that ships with `spora-core`. It exists to give plugin authors a copy-pasteable starting point and to exercise the end-to-end skill discovery + Skill tool wiring in the skeleton's own test suite.

## When to use this skill

This skill is intentionally minimal — it is here to demonstrate the contract, not to solve a real problem. Plugin authors should treat this file as a template:

1. Rename the directory to your skill's slug.
2. Update the frontmatter `name`, `description`, and optional `license` / `compatibility` / `metadata` fields.
3. Replace this body with the actual instructions.
4. Drop sidecar files (e.g. `examples.md`, `references/REFERENCE.md`) into the same directory; the Skill tool's `skill_files` operation enumerates them and `skill_read` reads them on demand.

## Steps

1. **Read the body** of this skill with `skill_read` (the agent's tool definition will have already surfaced the `name` + `description`).
2. **Apply the instructions** to the user's task.
3. **Drop sidecar files** for long content — `references/` is the conventional place. Keep `SKILL.md` under the agentskills.io soft cap (~500 lines, ~5000 tokens).

## Examples

See `examples.md` for worked examples.
