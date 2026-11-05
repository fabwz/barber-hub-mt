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
