# Laravel Zero documentation

This Astro and Fumadocs site contains the handwritten Laravel Zero guide and the
generated PHP API reference.

## Development

From this directory:

```bash
npm install
npm run dev
```

Build the static site with:

```bash
npm run build
```

## Regenerating the API reference

From the `laravel-zero` repository root:

```bash
vendor/bin/testbench fumadocs:generate --framework=astro --no-interaction
```

The generator updates files recorded in `.fumadocs/generated.json` and preserves
handwritten pages under `content/docs/guide`.
