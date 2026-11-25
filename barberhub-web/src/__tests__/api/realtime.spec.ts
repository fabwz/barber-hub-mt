import { afterEach, describe, expect, it, vi } from 'vitest'

interface Captured {
  [key: string]: unknown
  channelAuthorization: {
    endpoint: string
    transport: string
    headersProvider: () => Record<string, string>
  }
}

const created = vi.hoisted(() => ({ options: [] as Captured[] }))

// A stand-in for laravel-echo: it only remembers how it was built.
vi.mock('laravel-echo', () => ({
  default: class {
    connector = { onConnectionChange: () => () => {} }
    constructor(options: Captured) {
      created.options.push(options)
    }
    private() {
      return { listen() {}, subscribed() {}, error() {} }
    }
    leave() {}
    disconnect() {}
  },
}))

import {
  REALTIME_AUTH_PATH,
  openRealtime,
  readRealtimeSettings,
  rejectionStatus,
} from '@/api/realtime'

afterEach(() => {
  vi.unstubAllEnvs()
  created.options.length = 0
})

function stubReverb(over: Record<string, string> = {}): void {
  const values = {
    VITE_REVERB_APP_KEY: 'local-key',
    VITE_REVERB_HOST: 'localhost',
    VITE_REVERB_PORT: '8080',
    VITE_REVERB_SCHEME: 'http',
    ...over,
  }
  for (const [name, value] of Object.entries(values)) vi.stubEnv(name, value)
}

describe('readRealtimeSettings', () => {
  it('is off without the key or the host', () => {
    stubReverb({ VITE_REVERB_APP_KEY: '' })
    expect(readRealtimeSettings()).toBeNull()
    stubReverb({ VITE_REVERB_HOST: '' })
    expect(readRealtimeSettings()).toBeNull()
  })

  it('reads the public variables and defaults the port to the one of the scheme', () => {
    stubReverb()
    expect(readRealtimeSettings()).toEqual({
      key: 'local-key',
      host: 'localhost',
      port: 8080,
      secure: false,
    })

    stubReverb({ VITE_REVERB_PORT: '', VITE_REVERB_SCHEME: 'https' })
    expect(readRealtimeSettings()).toMatchObject({ port: 443, secure: true })
  })
})

describe('openRealtime', () => {
  it('opens nothing when it is not configured', () => {
    stubReverb({ VITE_REVERB_HOST: '' })

    expect(openRealtime(() => ({}))).toBeNull()
    expect(created.options).toEqual([])
  })

  it('builds Echo for Reverb and authorizes against the API with the bearer given on every request', () => {
    stubReverb()
    let token = 'tok-1'

    openRealtime(() => ({ Authorization: `Bearer ${token}` }))

    const options = created.options[0]!
    expect(options).toMatchObject({
      broadcaster: 'reverb',
      key: 'local-key',
      wsHost: 'localhost',
      wsPort: 8080,
      forceTLS: false,
    })
    expect(options.channelAuthorization.endpoint).toBe('/api/v1/tiempo-real/autorizacion')
    expect(REALTIME_AUTH_PATH).toBe('/api/v1/tiempo-real/autorizacion')
    expect(options.channelAuthorization.transport).toBe('ajax')
    expect(options.channelAuthorization.headersProvider()).toEqual({
      Accept: 'application/json',
      Authorization: 'Bearer tok-1',
    })
    token = 'tok-2'
    expect(options.channelAuthorization.headersProvider().Authorization).toBe('Bearer tok-2')
  })

  it('reads the HTTP status of a refused subscription', () => {
    expect(rejectionStatus({ type: 'AuthError', status: 403 })).toBe(403)
    expect(rejectionStatus({ type: 'AuthError' })).toBeNull()
    expect(rejectionStatus(null)).toBeNull()
  })
})
