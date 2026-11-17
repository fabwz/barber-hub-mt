import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api, isApiError, type ApiError, type components } from '@/api'
import { networkError } from '@/api/errors'

type Proposal = components['schemas']['PropuestaDeReserva']

/** Maximum length of the phrase: the contract of `POST /reservas/propuesta` says 300 characters. */
export const MAX_PHRASE_LENGTH = 300

export type ProposalStatus = Proposal['estado']
export type ProposalField = NonNullable<Proposal['campo']>
export type ProposalBand = NonNullable<Proposal['franja']>['nombre']

export interface ProposalSpace {
  barberId: string
  /** ISO 8601 start of the space, as the API delivers it. */
  start: string
  end: string
}

export interface ProposalCandidate {
  id: string
  name: string
}

/** What the API understood and validated; the groups the contract types as `unknown[]` are read here. */
export interface BookingProposal {
  status: ProposalStatus
  /** The field that failed or is still open. */
  field: ProposalField | null
  /** The service, as the person said it. */
  serviceText: string | null
  /** The barber, as the person said it. */
  barberText: string | null
  serviceId: string | null
  barberId: string | null
  date: string | null
  /** «HH:MM» when the phrase gave an exact hour. */
  time: string | null
  band: ProposalBand | null
  missing: ProposalField[]
  candidates: ProposalCandidate[]
  spaces: ProposalSpace[]
  alternatives: ProposalSpace[]
  /** Start and end of the exact space, when the proposal is valid with an exact hour. */
  start: string | null
  end: string | null
}

export type AiBookingState = 'idle' | 'loading' | 'ready' | 'error'

const FIELDS: ProposalField[] = ['servicio', 'barbero', 'fecha', 'hora']

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readSpaces(raw: unknown[]): ProposalSpace[] {
  return raw.flatMap((item): ProposalSpace[] => {
    if (!isRecord(item)) return []
    const { barbero, inicio, fin } = item
    return typeof barbero === 'string' && typeof inicio === 'string' && typeof fin === 'string'
      ? [{ barberId: barbero, start: inicio, end: fin }]
      : []
  })
}

function readCandidates(raw: unknown[]): ProposalCandidate[] {
  return raw.flatMap((item): ProposalCandidate[] => {
    if (!isRecord(item)) return []
    const { id, nombre } = item
    return typeof id === 'string' && typeof nombre === 'string' ? [{ id, name: nombre }] : []
  })
}

function readFields(raw: unknown[]): ProposalField[] {
  return FIELDS.filter((field) => raw.includes(field))
}

export function readProposal(data: Proposal): BookingProposal {
  return {
    status: data.estado,
    field: data.campo,
    serviceText: data.servicio,
    barberText: data.barbero,
    serviceId: data.servicio_id,
    barberId: data.barbero_id,
    date: data.fecha,
    time: data.hora,
    band: data.franja?.nombre ?? null,
    missing: readFields(data.campos_faltantes),
    candidates: readCandidates(data.candidatos),
    spaces: readSpaces(data.espacios),
    alternatives: readSpaces(data.alternativas),
    start: data.inicio,
    end: data.fin,
  }
}

/**
 * The proposal of a phrase (RF-37). It lives in memory only: the phrase is never stored and it
 * survives a trip to the login (the router does not reload the page). A proposal books nothing.
 */
export const useAiBookingStore = defineStore('aiBooking', () => {
  const phrase = ref('')
  const state = ref<AiBookingState>('idle')
  const proposal = ref<BookingProposal | null>(null)
  const error = ref<ApiError | null>(null)
  // A late answer of a previous request must not overwrite a newer one.
  let attempt = 0
  let shopId: string | null = null

  /** Sets the shop being viewed: another shop starts clean (the phrase is not carried over). */
  function enter(id: string): void {
    if (shopId === id) return
    const changed = shopId !== null
    shopId = id
    if (changed) reset()
  }

  function reset(): void {
    attempt += 1
    phrase.value = ''
    state.value = 'idle'
    proposal.value = null
    error.value = null
  }

  /** Sends the phrase and keeps the proposal. Answers whether there is a proposal to apply. */
  async function propose(shopId: string): Promise<boolean> {
    const text = phrase.value.trim()
    if (text === '' || state.value === 'loading') return false
    const current = ++attempt
    state.value = 'loading'
    error.value = null
    proposal.value = null
    try {
      const { data } = await api.POST('/barberias/{barberia}/reservas/propuesta', {
        params: { path: { barberia: shopId } },
        body: { frase: text },
      })
      if (current !== attempt) return false
      proposal.value = readProposal(data!.data)
      state.value = 'ready'
      return true
    } catch (caught) {
      if (current !== attempt) return false
      error.value = isApiError(caught) ? caught : networkError(caught)
      state.value = 'error'
      return false
    }
  }

  return { phrase, state, proposal, error, enter, reset, propose }
})
