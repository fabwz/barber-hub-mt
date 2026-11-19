import { computed, ref, watchEffect } from 'vue'
import { defineStore } from 'pinia'

/** Theme the person chose; `auto` follows the device. */
export type ThemeChoice = 'auto' | 'light' | 'dark'
/** The theme that is on screen. */
export type ResolvedTheme = 'light' | 'dark'
export type TextSize = 'normal' | 'large' | 'xlarge'

export interface StoredPreferences {
  theme: ThemeChoice
  textSize: TextSize
  /** `null` follows the device (prefers-reduced-motion); a boolean is the person's own choice. */
  reduceMotion: boolean | null
}

/** Only this key is read and written: the three appearance preferences live in the browser (AC-05). */
export const PREFERENCES_STORAGE_KEY = 'barberhub.preferences'

export const THEME_CHOICES: readonly ThemeChoice[] = ['auto', 'light', 'dark']
export const TEXT_SIZES: readonly TextSize[] = ['normal', 'large', 'xlarge']
/** AC-03: the root font size, as a percentage of the browser's. */
export const TEXT_SIZE_PERCENT: Record<TextSize, number> = { normal: 100, large: 125, xlarge: 150 }

const DEFAULTS: StoredPreferences = { theme: 'auto', textSize: 'normal', reduceMotion: null }

/** Reads what was saved. Anything missing, broken or unknown falls back to the default of that field. */
export function parseStoredPreferences(raw: string | null): StoredPreferences {
  if (raw === null) return { ...DEFAULTS }
  let data: unknown
  try {
    data = JSON.parse(raw)
  } catch {
    return { ...DEFAULTS }
  }
  if (typeof data !== 'object' || data === null) return { ...DEFAULTS }
  const { theme, textSize, reduceMotion } = data as Record<string, unknown>
  return {
    theme: THEME_CHOICES.find((choice) => choice === theme) ?? DEFAULTS.theme,
    textSize: TEXT_SIZES.find((size) => size === textSize) ?? DEFAULTS.textSize,
    reduceMotion: typeof reduceMotion === 'boolean' ? reduceMotion : DEFAULTS.reduceMotion,
  }
}

function readSaved(): StoredPreferences {
  try {
    return parseStoredPreferences(localStorage.getItem(PREFERENCES_STORAGE_KEY))
  } catch {
    // Storage blocked or unavailable: the preferences live in memory for this visit.
    return { ...DEFAULTS }
  }
}

function writeSaved(preferences: StoredPreferences): void {
  try {
    localStorage.setItem(PREFERENCES_STORAGE_KEY, JSON.stringify(preferences))
  } catch {
    // Same: nothing to do, the choice still applies in memory.
  }
}

function deviceQuery(query: string): MediaQueryList | null {
  return typeof window !== 'undefined' && typeof window.matchMedia === 'function'
    ? window.matchMedia(query)
    : null
}

/**
 * Theme, text size and motion reduction (RF-48, AC-02 to AC-05). The three live here and nowhere
 * else. Each change applies to <html> at once and is saved in the browser; no account, no API.
 * The attributes it sets (data-theme, data-text-size, data-motion) are what main.css reads, and
 * the script in index.html sets the same ones before the first render.
 */
export const usePreferencesStore = defineStore('preferences', () => {
  const saved = readSaved()
  const theme = ref<ThemeChoice>(saved.theme)
  const textSize = ref<TextSize>(saved.textSize)
  const reduceMotion = ref<boolean | null>(saved.reduceMotion)

  // What the device says. With no matchMedia the app stays dark, as it was before the light theme.
  const darkQuery = deviceQuery('(prefers-color-scheme: dark)')
  const motionQuery = deviceQuery('(prefers-reduced-motion: reduce)')
  const deviceDark = ref(darkQuery?.matches ?? true)
  const deviceReducesMotion = ref(motionQuery?.matches ?? false)
  darkQuery?.addEventListener('change', (event) => (deviceDark.value = event.matches))
  motionQuery?.addEventListener('change', (event) => (deviceReducesMotion.value = event.matches))

  const resolvedTheme = computed<ResolvedTheme>(() => {
    if (theme.value === 'auto') return deviceDark.value ? 'dark' : 'light'
    return theme.value
  })
  /** What the switch shows: the person's choice or, without one, the device's. */
  const reducedMotion = computed(() => reduceMotion.value ?? deviceReducesMotion.value)

  // `sync`: the change shows at once, not on the next tick.
  watchEffect(
    () => {
      const root = document.documentElement
      root.dataset.theme = resolvedTheme.value
      root.dataset.textSize = textSize.value
      // Without a choice there is no attribute and main.css follows the device by itself.
      if (reduceMotion.value === null) delete root.dataset.motion
      else root.dataset.motion = reduceMotion.value ? 'reduce' : 'full'
    },
    { flush: 'sync' },
  )

  function save(): void {
    writeSaved({ theme: theme.value, textSize: textSize.value, reduceMotion: reduceMotion.value })
  }

  function setTheme(value: ThemeChoice): void {
    theme.value = value
    save()
  }

  function setTextSize(value: TextSize): void {
    textSize.value = value
    save()
  }

  function setReduceMotion(value: boolean | null): void {
    reduceMotion.value = value
    save()
  }

  return {
    theme,
    textSize,
    reduceMotion,
    resolvedTheme,
    reducedMotion,
    setTheme,
    setTextSize,
    setReduceMotion,
  }
})
