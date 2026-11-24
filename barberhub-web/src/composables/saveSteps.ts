/**
 * A screen that saves with several calls runs them in order and stops at the first that fails. Each step
 * works out what is still pending from what the API holds, so a new try sends only what was left.
 */
export interface SaveStep {
  /** What the person calls it: «los datos del negocio», «el banner»… */
  label: string
  run: () => Promise<void>
}

export interface SaveOutcome {
  /** Labels of the steps that were saved. */
  saved: string[]
  /** The step that failed with its error, or `null` when all went well. Whatever came after it was not tried. */
  failed: { label: string; error: unknown } | null
  /** Labels of the steps that were not tried because one before failed. */
  pending: string[]
}

export async function runSteps(steps: readonly SaveStep[]): Promise<SaveOutcome> {
  const saved: string[] = []
  for (const [index, step] of steps.entries()) {
    try {
      await step.run()
      saved.push(step.label)
    } catch (error) {
      return {
        saved,
        failed: { label: step.label, error },
        pending: steps.slice(index + 1).map((next) => next.label),
      }
    }
  }
  return { saved, failed: null, pending: [] }
}

/** «los datos y el banner», for a message. */
export function listLabels(labels: readonly string[]): string {
  if (labels.length <= 1) return labels[0] ?? ''
  return `${labels.slice(0, -1).join(', ')} y ${labels[labels.length - 1]}`
}

/**
 * The message of a save that did not finish. With nothing saved there is nothing to tell apart; with something
 * saved the person learns what stayed and what is left.
 */
export function partialMessage(outcome: SaveOutcome): string {
  if (outcome.failed === null) return ''
  if (outcome.saved.length === 0) return 'No se cambió nada. Inténtelo de nuevo.'
  const left = [outcome.failed.label, ...outcome.pending]
  return `Se guardó ${listLabels(outcome.saved)}. Falta guardar ${listLabels(left)}. Inténtelo de nuevo.`
}
