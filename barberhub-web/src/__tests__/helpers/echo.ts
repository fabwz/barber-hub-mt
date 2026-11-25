import { vi } from 'vitest'

import {
  setEchoFactory,
  type AuthHeaders,
  type ChannelLike,
  type ConnectionStatus,
  type EchoLike,
  type RealtimeSettings,
} from '@/api/realtime'

import { DAY } from './agenda'

/** A private channel of the fake Echo: what the screen asked and the ways to drive it from a test. */
class FakeChannel implements ChannelLike {
  readonly events = new Map<string, Array<(payload: unknown) => void>>()
  readonly subscribedCallbacks: Array<() => void> = []
  readonly errorCallbacks: Array<(error: unknown) => void> = []

  constructor(readonly name: string) {}

  listen(event: string, callback: (payload: unknown) => void): ChannelLike {
    this.events.set(event, [...(this.events.get(event) ?? []), callback])
    return this
  }

  subscribed(callback: () => void): ChannelLike {
    this.subscribedCallbacks.push(callback)
    return this
  }

  error(callback: (error: unknown) => void): ChannelLike {
    this.errorCallbacks.push(callback)
    return this
  }
}

/** Echo in memory: records the subscriptions and lets the test raise events, states and rejections. */
export class FakeEcho implements EchoLike {
  readonly channels: FakeChannel[] = []
  readonly left: string[] = []
  disconnected = false
  settings: RealtimeSettings | null = null
  authHeaders: AuthHeaders | null = null
  private readonly listeners = new Set<(status: ConnectionStatus) => void>()

  readonly connector = {
    onConnectionChange: (callback: (status: ConnectionStatus) => void) => {
      this.listeners.add(callback)
      return () => this.listeners.delete(callback)
    },
  }

  private(name: string): ChannelLike {
    const channel = new FakeChannel(name)
    this.channels.push(channel)
    return channel
  }

  leave(name: string): void {
    this.left.push(name)
  }

  disconnect(): void {
    this.disconnected = true
  }

  /** The names the screen subscribed to. */
  get subscriptions(): string[] {
    return this.channels.map((channel) => channel.name)
  }

  /** The server accepted the subscription (also on every resubscription after a reconnect). */
  subscribe(): void {
    for (const channel of this.channels)
      channel.subscribedCallbacks.forEach((callback) => callback())
  }

  /** The server refused the subscription with an HTTP status. */
  reject(status: number): void {
    for (const channel of this.channels) {
      channel.errorCallbacks.forEach((callback) => callback({ type: 'AuthError', status }))
    }
  }

  /** The state of the link changed. */
  connection(status: ConnectionStatus): void {
    this.listeners.forEach((callback) => callback(status))
  }

  /** The server sent `.reserva.actualizada`. */
  emit(payload: unknown): void {
    for (const channel of this.channels) {
      channel.events.get('.reserva.actualizada')?.forEach((callback) => callback(payload))
    }
  }

  get listening(): number {
    return this.listeners.size
  }
}

/** Sets the Reverb variables and makes the app use a fake Echo. Undo it with `resetFakeEcho()`. */
export function installFakeEcho(): FakeEcho {
  const echo = new FakeEcho()
  vi.stubEnv('VITE_REVERB_APP_KEY', 'local-key')
  vi.stubEnv('VITE_REVERB_HOST', 'reverb.test')
  vi.stubEnv('VITE_REVERB_PORT', '8080')
  vi.stubEnv('VITE_REVERB_SCHEME', 'http')
  setEchoFactory((settings, authHeaders) => {
    echo.settings = settings
    echo.authHeaders = authHeaders
    return echo
  })
  return echo
}

export function resetFakeEcho(): void {
  vi.unstubAllEnvs()
  setEchoFactory(null)
}

/** The event of the API for a reservation; by default a new one of the day under test. */
export function liveEvent(over: Record<string, unknown> = {}) {
  return {
    reserva: 'r9',
    colaborador: 'b1',
    estado: 'confirmada',
    inicio: `${DAY}T16:00:00-06:00`,
    fin: `${DAY}T16:30:00-06:00`,
    cambio: 'creada',
    ocurrio_en: `${DAY}T11:21:00-06:00`,
    ...over,
  }
}
