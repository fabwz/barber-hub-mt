import { inject, type InjectionKey, type Ref } from 'vue'

/** What OptionGroup shares with the OptionCard children it contains. */
export interface OptionGroupContext {
  /** Chosen value, read-only for the cards. */
  selected: Readonly<Ref<string | null>>
  /** Value that holds the single tab stop (roving tabindex). */
  tabbable: Readonly<Ref<string | null>>
  select: (value: string) => void
  /** Registers a card; returns the function that unregisters it. */
  register: (value: string) => () => void
}

export const optionGroupKey: InjectionKey<OptionGroupContext> = Symbol('optionGroup')

export function useOptionGroupContext(): OptionGroupContext {
  const context = inject(optionGroupKey)

  if (context === undefined) {
    throw new Error('OptionCard must be rendered inside an OptionGroup.')
  }

  return context
}
