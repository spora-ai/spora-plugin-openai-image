# Examples

This is a sidecar file. The Skill tool's `skill_files` operation returns this path alongside `SKILL.md`; the agent can call `skill_read` on it to pull the body into context.

## Example 1 — Reading this skill

```
skill(action="read", name="example-skill")
```

Returns the body of this skill (frontmatter stripped) — see `SKILL.md`.

## Example 2 — Listing this skill's files

```
skill(action="files", name="example-skill")
```

Returns:

```
Files in skill 'example-skill':
  - SKILL.md
  - examples.md
```

## Example 3 — Authoring your own skill

1. Copy this directory to a new path: `skills/my-skill/`.
2. Rename the directory. The directory name MUST match the frontmatter `name`.
3. Update the frontmatter (name, description, optional fields).
4. Add sidecar files under `references/` for long content.
5. Run your plugin's test suite; the bundled skill will be discovered automatically.
