# OpenAI Image Generation

Use the `openai-image:image` tool to generate images through an OpenAI-compatible Image API.

## Usage

- Always provide a concise, descriptive `prompt`.
- Use `size` only when the requested composition needs a specific aspect ratio.
- Use `quality: high` for detailed output and `low` for quick drafts.
- Use `background: transparent` only when the generated asset should be composited elsewhere.
- Use `n` for multiple independent variations, then inspect the returned `image_urls`.
- Use `filename` when the image has a meaningful name for the media archive.
- Return the markdown image block from the tool result unchanged; do not rewrite archive URLs.

The operator configures the API key, base URL, and model. The default endpoint is OpenAI's `/v1` API, but compatible providers can be selected by changing the base URL.
