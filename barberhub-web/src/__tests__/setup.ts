// Node no resuelve URL relativas en Request (el navegador sí) y el cliente HTTP usa la base
// relativa /api/v1. Antes de cargar cualquier módulo, Request resuelve contra un origen ficticio.
const NativeRequest = globalThis.Request

globalThis.Request = class extends NativeRequest {
  constructor(input: RequestInfo | URL, init?: RequestInit) {
    super(
      typeof input === 'string' && input.startsWith('/') ? `http://localhost${input}` : input,
      init,
    )
  }
} as typeof Request

// jsdom brings its own FormData, which the Request of Node cannot read (the body would travel as
// «[object FormData]»). In a browser both are the same, so the tests use the FormData of Node.
const NodeFormData = (await new Response(new URLSearchParams({ a: 'b' })).formData())
  .constructor as typeof FormData
globalThis.FormData = NodeFormData

// Newer Node versions ship an experimental global `localStorage` that is undefined unless
// --localstorage-file is given, and it hides the one from jsdom (the CI runs such a version).
// The tests always get the same in-memory storage, whatever the Node version.
class MemoryStorage {
  readonly #items = new Map<string, string>()

  get length(): number {
    return this.#items.size
  }

  clear(): void {
    this.#items.clear()
  }

  getItem(key: string): string | null {
    return this.#items.get(key) ?? null
  }

  key(index: number): string | null {
    return [...this.#items.keys()][index] ?? null
  }

  removeItem(key: string): void {
    this.#items.delete(key)
  }

  setItem(key: string, value: string): void {
    this.#items.set(key, String(value))
  }
}

Object.defineProperty(globalThis, 'localStorage', {
  configurable: true,
  writable: true,
  value: new MemoryStorage() as unknown as Storage,
})
