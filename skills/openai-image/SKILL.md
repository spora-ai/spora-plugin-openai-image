---
name: openai-image
description: "Generate images through the OpenAI-compatible image plugin. Two operations: `generate` (single image, no approval) and `generate_variations` (multiple images or semantic variations of an input image, approval-gated by default). Use when the user asks for an image, picture, illustration, photo, poster, thumbnail, icon, or other visual created from a text description."
license: MIT
compatibility: spora>=0.15 spora-plugin-openai-image>=1.1
metadata:
  author: spora-ai
  version: "1.1"
allowed-tools: Spora\Plugins\OpenAIImage\Tools\OpenAIImageGenerationTool
---

# OpenAI-compatible image generation

Two operations:

- `generate` — single image, no approval. The default for "give me an image of X".
- `generate_variations` — multiple images (3 by default, up to 8), or semantic variations of an existing image. Approval-gated by default — the LLM must ask the operator before issuing this call.

The tool calls the configured OpenAI-compatible endpoints and stores returned images in the Media Archive when available.

## Operations

### `generate` — single image

Always produces one image. Use this for the common case.

```text
openai_image_openai(action: "generate", prompt: "subject + style + composition + lighting", size: "1536x1024", quality: "high", filename: "hero-banner")
```

| Parameter | Required | Default | Notes |
|---|---:|---|---|
| `prompt` | Yes | — | Describe the subject, style, composition, lighting, and important text. |
| `size` | No | `auto` | `1024x1024` for square assets, `1536x1024` for landscape, `1024x1536` for portrait. |
| `quality` | No | `auto` | Use `low` for drafts, `medium` for normal output, `high` for detailed final output. |
| `background` | No | `auto` | Use `transparent` for compositing and `opaque` for a solid background. |
| `filename` | No | auto | Human-readable archive filename stem. |

When the desired surface is ambiguous, ask whether the user wants square, landscape, or portrait output before choosing `size`.

### `generate_variations` — multiple images or input-image variations

Approval-gated by default: the LLM must ask the operator before issuing this call. Useful when the user wants to see options, or when refining an existing image.

Two modes, selected by `input_image`:

- **Prompt-based** (default, `input_image` omitted): calls `POST /v1/images/generations` with `n` independent samples from the same prompt.
- **Image-based** (`input_image` supplied): calls `POST /v1/images/variations` (multipart upload) to produce semantic variations of an existing image. The upstream `/v1/images/variations` endpoint does not accept `prompt`, `quality`, or `background` — only `n` and `size` are forwarded. Accepted `input_image` shapes: http(s) URL, `data:` URI, Media Archive UUID (`/api/v1/assets/<uuid>.<ext>`). The plugin resolves the Media Archive reference server-side (the LLM never sees the bytes), then multipart-uploads to the upstream endpoint.

```text
openai_image_openai(action: "generate_variations", prompt: "subject + style", n: 3, size: "1024x1024", filename: "options")
```

```text
openai_image_openai(action: "generate_variations", input_image: "https://cdn.example.com/seed.png", n: 4, size: "1024x1024")
```

| Parameter | Required | Default | Notes |
|---|---:|---|---|
| `prompt` | One of | — | Required when `input_image` is absent. Ignored on image-based variations. |
| `input_image` | One of | — | Source image. Accepts an http(s) URL, a `data:` URI, a 36-char Media Archive UUID, or an opaque `/api/v1/assets/<uuid>.<ext>` URL. When supplied, switches to `/v1/images/variations`. |
| `n` | No | `3` | Number of variations. Minimum 2, maximum 8. The upstream API charges per image. |
| `size` | No | `auto` | Same options as `generate`. |
| `quality` | No | `auto` | Ignored on image-based variations. |
| `background` | No | `auto` | Ignored on image-based variations. |
| `filename` | No | auto | Stem; a `-1`, `-2`, … suffix is added per image. |

## Settings

| Setting | Default | Notes |
|---|---|---|
| `api_key` | required | API key for OpenAI or the compatible provider. |
| `base_url` | `https://api.openai.com/v1` | API base URL; `/images/generations` and `/images/variations` are appended automatically. |
| `model` | `gpt-image-2` | Image model identifier supported by the configured provider. |
| `http_timeout_seconds` | `600` | Per-request timeout. The default is generous to cover `generate_variations(n: 8)` at high quality on slow networks. Lower it for single-image drafts where you want fast failure; raise it above 600 if the upstream still hits the idle timeout. |

## Rendering

The tool returns a Markdown image block plus a trailing "Echo the markdown image block above verbatim" sentence. Echo the block into your reply unchanged so the chat UI renders the image inline. Use `image_urls` for the raw URLs.

When the API returns multiple URLs (the `generate_variations` case), the tool joins them with blank lines and indices each one (`Generated image 1: …`, `Generated image 2: …`, …). Don't split them or rewrite the per-image captions.

If Media Archive is unavailable or ingestion fails, the tool returns a data URL fallback. Do not fabricate a public URL or replace the returned image URL.

## Failure handling

- If the API key is missing, ask the operator to configure the tool settings rather than retrying.
- If the API returns no image data, report the failure and do not claim an image was generated.
- On a timeout error, the message includes the current `http_timeout_seconds` value — either retry with a smaller `size` or `quality: low`, or ask the operator to raise the timeout.
- On `HTTP 429` (rate limited), wait or reduce `n` before retrying.
- **Do not retry a successful generation** — additional images consume provider quota.
- The internal retry loop is intentionally absent; the LLM is the right place to decide whether to retry and how.
