import { onScopeDispose, ref, watch } from 'vue'

import { api, type components } from '@/api'

export type DirectoryShop = components['schemas']['BarberiaDelDirectorio']
export type DirectoryStatus = 'loading' | 'ready' | 'error'

/** The largest page the API accepts (`por_pagina` up to 50): the design has no pagination. */
export const DIRECTORY_PAGE_SIZE = 50
/** Wait after the last key before asking the API. */
export const SEARCH_DELAY_MS = 300

/**
 * State of the public directory: the shops shown, the search and the page. The search is
 * delegated to the API; an answer that arrives after a newer request started is dropped.
 */
export function useDirectory() {
  const query = ref('')
  const shops = ref<DirectoryShop[]>([])
  const status = ref<DirectoryStatus>('loading')
  /** Total of shops that match the search, from `meta.total`. */
  const total = ref(0)
  /** The term the shown list was asked with (trimmed). */
  const searched = ref('')
  const hasMore = ref(false)
  const loadingMore = ref(false)
  const moreFailed = ref(false)
  /** False until the very first answer or failure: only then the search field is disabled. */
  const loadedOnce = ref(false)

  let page = 1
  let latest = 0
  let timer: ReturnType<typeof setTimeout> | undefined

  function request(term: string, pageNumber: number) {
    return api.GET('/directorio', {
      params: {
        query: {
          buscar: term === '' ? undefined : term,
          pagina: pageNumber,
          por_pagina: DIRECTORY_PAGE_SIZE,
        },
      },
    })
  }

  /** First page for a term. Replaces the list. */
  async function load(term: string): Promise<void> {
    const id = ++latest
    searched.value = term
    status.value = 'loading'
    loadingMore.value = false
    moreFailed.value = false
    try {
      const { data } = await request(term, 1)
      if (id !== latest) return
      shops.value = data!.data
      total.value = data!.meta.total
      page = data!.meta.pagina
      hasMore.value = data!.meta.pagina < data!.meta.ultima_pagina
      status.value = 'ready'
    } catch {
      if (id !== latest) return
      status.value = 'error'
    } finally {
      if (id === latest) loadedOnce.value = true
    }
  }

  /** Next page of the same search, appended to the list. */
  async function loadMore(): Promise<void> {
    if (loadingMore.value || !hasMore.value || status.value !== 'ready') return
    const id = latest
    loadingMore.value = true
    moreFailed.value = false
    try {
      const { data } = await request(searched.value, page + 1)
      if (id !== latest) return
      shops.value = [...shops.value, ...data!.data]
      total.value = data!.meta.total
      page = data!.meta.pagina
      hasMore.value = data!.meta.pagina < data!.meta.ultima_pagina
    } catch {
      if (id === latest) moreFailed.value = true
    } finally {
      if (id === latest) loadingMore.value = false
    }
  }

  function search(): Promise<void> | undefined {
    const term = query.value.trim()
    // Typing a space or going back to the same text is not a new search.
    if (term === searched.value && status.value !== 'error') return undefined
    return load(term)
  }

  function cancelTimer(): void {
    if (timer !== undefined) clearTimeout(timer)
    timer = undefined
  }

  watch(query, () => {
    cancelTimer()
    timer = setTimeout(() => void search(), SEARCH_DELAY_MS)
  })

  /** Empties the field and shows every shop again, without waiting for the delay. */
  function clear(): void {
    query.value = ''
    cancelTimer()
    void search()
  }

  function retry(): Promise<void> {
    cancelTimer()
    return load(searched.value)
  }

  onScopeDispose(cancelTimer)

  void load('')

  return {
    query,
    shops,
    status,
    total,
    searched,
    hasMore,
    loadingMore,
    moreFailed,
    loadedOnce,
    loadMore,
    clear,
    retry,
  }
}
