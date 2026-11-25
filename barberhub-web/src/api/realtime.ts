import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

import { API_BASE_URL } from './client'

/** Where the API authorizes a subscription to a private channel (`socket_id` and `channel_name`). */
export const REALTIME_AUTH_PATH = `${API_BASE_URL}/tiempo-real/autorizacion`

/** What the screens read of the connection: the pusher states, without the ones they do not tell apart. */
export type ConnectionStatus =
  'connected' | 'connecting' | 'reconnecting' | 'disconnected' | 'failed'

/** The part of a channel of Echo that the live agenda uses. */
export interface ChannelLike {
  listen(event: string, callback: (payload: unknown) => void): ChannelLike
  subscribed(callback: () => void): ChannelLike
  error(callback: (error: unknown) => void): ChannelLike
}

/** The part of Echo that the live agenda uses, so a test can give another one. */
export interface EchoLike {
  private(channel: string): ChannelLike
  leave(channel: string): void
  disconnect(): void
  connector: { onConnectionChange(callback: (status: ConnectionStatus) => void): () => void }
}

export interface RealtimeSettings {
  key: string
  host: string
  port: number
  secure: boolean
}

/** Gives the headers of the authorization request each time, so it always carries the token of the session. */
export type AuthHeaders = () => Record<string, string>

/** Reads the public Reverb variables; `null` when they are not set, which turns the live agenda off. */
export function readRealtimeSettings(): RealtimeSettings | null {
  const key = import.meta.env.VITE_REVERB_APP_KEY ?? ''
  const host = import.meta.env.VITE_REVERB_HOST ?? ''
  if (key === '' || host === '') return null
  const secure = (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https'
  const port = Number(import.meta.env.VITE_REVERB_PORT) || (secure ? 443 : 80)
  return { key, host, port, secure }
}

type EchoFactory = (settings: RealtimeSettings, authHeaders: AuthHeaders) => EchoLike

const defaultFactory: EchoFactory = (settings, authHeaders) =>
  new Echo({
    broadcaster: 'reverb',
    Pusher,
    key: settings.key,
    wsHost: settings.host,
    wsPort: settings.port,
    wssPort: settings.port,
    forceTLS: settings.secure,
    enabledTransports: ['ws', 'wss'],
    // The authorization goes to the API with the bearer of the session, read on every request.
    channelAuthorization: {
      transport: 'ajax',
      endpoint: REALTIME_AUTH_PATH,
      headersProvider: () => ({ Accept: 'application/json', ...authHeaders() }),
    },
  } as ConstructorParameters<typeof Echo<'reverb'>>[0]) as unknown as EchoLike

let factory: EchoFactory = defaultFactory

/** The tests give their own Echo; without an argument the real one comes back. */
export function setEchoFactory(next: EchoFactory | null): void {
  factory = next ?? defaultFactory
}

/** One connection to Reverb for one screen: private channels and the state of the link. */
export interface Realtime {
  /** Subscribes to a private channel (the name without `private-`). */
  listen(
    channel: string,
    handlers: {
      onEvent: (payload: unknown) => void
      onSubscribed: () => void
      onRejected: (status: number | null) => void
    },
  ): void
  /** Leaves a private channel. */
  leave(channel: string): void
  onConnection(callback: (status: ConnectionStatus) => void): () => void
  /** Closes the socket: nothing arrives after this. */
  close(): void
}

/** The HTTP status that pusher reports when an authorization is refused (`pusher:subscription_error`). */
export function rejectionStatus(error: unknown): number | null {
  const status =
    typeof error === 'object' && error !== null ? (error as { status?: unknown }).status : null
  return typeof status === 'number' ? status : null
}

/** Opens the connection, or gives `null` when the Reverb variables are not set. */
export function openRealtime(authHeaders: AuthHeaders): Realtime | null {
  const settings = readRealtimeSettings()
  if (settings === null) return null
  const echo = factory(settings, authHeaders)
  return {
    listen(channel, handlers) {
      echo
        .private(channel)
        .listen('.reserva.actualizada', handlers.onEvent)
        .subscribed(handlers.onSubscribed)
        .error((error) => handlers.onRejected(rejectionStatus(error)))
    },
    leave: (channel) => echo.leave(channel),
    onConnection: (callback) => echo.connector.onConnectionChange(callback),
    close: () => echo.disconnect(),
  }
}
