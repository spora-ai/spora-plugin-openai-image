# OpenAI Image Plugin

This plugin provides the `image_openai` tool using the OpenAI Image API contract. Configure `api_key`, `base_url`, and `model` per agent so compatible providers can be used without code changes.

Generated base64 images are ingested into Spora's Media Archive when available. If the archive is unavailable or ingestion fails, the tool returns a data URL fallback.

The tool exposes two operations:

- `generate` — single image from a text prompt. Calls `POST {base_url}/images/generations`. The expected response shape is `data[].b64_json`.
- `generate_variations` — multiple images (default 3, max 8), or semantic variations of an existing image. Approval-gated by default.

  - Prompt-based (default): `POST {base_url}/images/generations` with `n` independent samples from the same prompt.
  - Image-based (`input_image` supplied): `POST {base_url}/images/variations` (multipart upload). `input_image` accepts an http(s) URL, a `data:` URI, a 36-char Media Archive UUID, or an opaque `/api/v1/assets/<uuid>.<ext>` URL. The plugin resolves Media Archive references server-side so the bytes never enter the chat context.