import type { ReactNode } from 'react';
import manifest from '../../public/api-symbols.json';

type ApiSymbol = {
  kind: string;
  label: string;
  url: string;
};

const symbols = manifest.symbols as Record<string, ApiSymbol>;

export function ApiLink({
  symbol,
  children,
}: {
  symbol: string;
  children?: ReactNode;
}) {
  const normalized = symbol.replace(/^\\/, '');
  const target = symbols[normalized];
  const label = children ?? target?.label ?? normalized.split('\\').at(-1);

  if (!target) {
    return <code title={`Unknown API symbol: ${normalized}`}>{label}</code>;
  }

  return (
    <a href={target.url} data-api-kind={target.kind}>
      <code>{label}</code>
    </a>
  );
}
