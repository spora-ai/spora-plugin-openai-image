# OpenAI Image Plugin

This plugin provides one image-generation tool using the OpenAI Image API contract. Configure `api_key`, `base_url`, and `model` per agent so compatible providers can be used without code changes.

Generated base64 images are ingested into Spora's Media Archive when available. If the archive is unavailable or ingestion fails, the tool returns a data URL fallback.

The tool uses `POST {base_url}/images/generations` and expects `data[].b64_json` in the response.
