import { readFile, writeFile, mkdir, readdir, rm } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { generateFiles } from 'fumadocs-openapi';
import { createOpenAPI } from 'fumadocs-openapi/server';

const config = JSON.parse(
  await readFile(new URL('../.fumadocs/openapi.json', import.meta.url), 'utf8'),
);

if (!config.enabled || config.specs.length === 0) {
  process.exit(0);
}

const openapi = createOpenAPI({ input: config.specs });
const output = resolve(config.output);
await rm(output, { recursive: true, force: true });
await generateFiles({
  input: openapi,
  output,
  includeDescription: true,
});

for (const file of await mdxFiles(output)) {
  let content = await readFile(file, 'utf8');
  const documents = [...content.matchAll(/document="([^"]+)"/g)];

  for (const match of documents) {
    const schema = await openapi.getSchema(match[1]);
    const payload = JSON.stringify({ bundled: schema.bundled });
    content = content.replace(match[0], `payload={${payload}}`);
  }

  await writeFile(file, content);
}

await mkdir(output, { recursive: true });
await writeFile(
  resolve(output, 'meta.json'),
  `${JSON.stringify(
    {
      title: 'OpenAPI',
      description: 'Generated HTTP API documentation.',
      root: true,
    },
    null,
    2,
  )}\n`,
);

async function mdxFiles(directory) {
  const files = [];

  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...(await mdxFiles(path)));
    } else if (entry.isFile() && entry.name.endsWith('.mdx')) {
      files.push(path);
    }
  }

  return files;
}
