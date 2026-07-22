declare module 'bun:test' {
  export function test(name: string, callback: () => void | Promise<void>): void;
  export function expect<T>(actual: T): {toEqual(expected: unknown): void};
}
