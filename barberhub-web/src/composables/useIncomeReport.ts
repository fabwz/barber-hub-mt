import { ref } from 'vue'

import { api, isApiError, type components, type ApiError } from '@/api'

export type IncomeReport = components['schemas']['ReporteIngresos']
export type IncomeStatus = 'idle' | 'loading' | 'ready' | 'error'

export interface IncomeRange {
  /** «AAAA-MM-DD», first day, included. */
  from: string
  /** «AAAA-MM-DD», last day, included. */
  to: string
}

/**
 * The income report of a shop for a range of dates (RF-25). The figures are shown as the API gives them: nothing is
 * added, subtracted or rounded here. `range` is the one the report on screen answers, so the empty state can name it.
 * A 422 of the API (a range it refuses, for instance more than 366 days) stays in `rejection` for the screen to put
 * under the field; any other failure is `error`.
 */
export function useIncomeReport() {
  const report = ref<IncomeReport | null>(null)
  const status = ref<IncomeStatus>('idle')
  const range = ref<IncomeRange | null>(null)
  const rejection = ref<ApiError | null>(null)

  // A newer request drops the answer of an older one.
  let generation = 0

  async function load(barberia: string, wanted: IncomeRange): Promise<void> {
    const current = ++generation
    status.value = 'loading'
    rejection.value = null
    try {
      const { data } = await api.GET('/barberias/{barberia}/administracion/ingresos', {
        params: { path: { barberia }, query: { desde: wanted.from, hasta: wanted.to } },
      })
      if (current !== generation) return
      report.value = data!.data
      range.value = wanted
      status.value = 'ready'
    } catch (error) {
      if (current !== generation) return
      if (isApiError(error) && error.status === 422) {
        rejection.value = error
        // The report on screen, if any, still answers its own range.
        status.value = 'ready'
        return
      }
      status.value = 'error'
    }
  }

  return { report, status, range, rejection, load }
}
