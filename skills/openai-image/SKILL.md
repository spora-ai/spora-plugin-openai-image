---
name: openai-image
description: Generate images through the OpenAI-compatible image plugin. Use when the user asks for an image, picture, illustration, photo, poster, thumbnail, icon, or other visual created from a text description. Choose size, quality, and background based on the requested output.
license: MIT
compatibility: spora>=0.12 spora-plugin-openai-image>=0.1
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: Spora\Plugins\OpenAIImage\Tools\OpenAIImageGenerationTool
---

# OpenAI-compatible image generation

Use one `generate` operation to create one or more images from a text prompt. The tool calls the configured OpenAI-compatible `/images/generations` endpoint and stores returned images in the Media Archive when available.

## Calling

```
openai_image_openai(prompt: "subject + style + composition + lighting", size: "1536x1024", quality: "high", filename: "hero-banner")
```

| Parameter | Required | Default | Notes |
|---|---:|---|---|
| `prompt` | Yes | — | Describe the subject, style, composition, lighting, and important text. |
| `size` | No | `auto` | `1024x1024` for square assets, `1536x1024` for landscape, `1024x1536` for portrait. |
| `quality` | No | `auto` | Use `low` for drafts, `medium` for normal output, `high` for detailed final output. |
| `background` | No | `auto` | Use `transparent` for compositing and `opaque` for a solid background. |
| `n` | No | `1` | Number of independent variations, from 1 to 10. |
| `filename` | No | auto | Human-readable archive filename stem. |

When the desired surface is ambiguous, ask whether the user wants square, landscape, or portrait output before choosing `size`.

## Settings

| Setting | Default | Notes |
|---|---|---|
| `api_key` | required | API key for OpenAI or the compatible provider. |
| `base_url` | `https://api.openai.com/v1` | API base URL; `/images/generations` is appended automatically. |
| `model` | `gpt-image-2` | Image model identifier supported by the configured provider. |
| `http_timeout_seconds` | `600` | Per-request timeout. Lower for single-image drafts; raise above 600 if `gpt-image-2` still hits the idle timeout. |

## Rendering

The tool returns Markdown image embeds and `ToolResult.data.image_urls`. Echo the Markdown image block unchanged so the chat UI renders it inline. Use `image_urls` when the user explicitly requests raw URLs.

If Media Archive is unavailable or ingestion fails, the tool returns a data URL fallback. Do not fabricate a public URL or replace the returned image URL.

## Failure handling

- If the API key is missing, ask the operator to configure the tool settings rather than retrying.
- If the API returns no image data, report the failure and do not claim an image was generated.
- Do not retry a successful generation; additional images consume provider quota.
