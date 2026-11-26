import axe from 'axe-core'

/** WCAG 2.2 AA, as RNF-27 asks: levels A and AA of 2.0, 2.1 and 2.2. No best-practice rules. */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']

/**
 * Rules that jsdom cannot evaluate, and why. Everything else of the tags above runs.
 * They are covered elsewhere or left for a follow-up (docs/accessibility-tests.md).
 */
const NOT_EVALUABLE_IN_JSDOM = {
  // jsdom does not load main.css, so no element has a real color. The token pairs of both themes are
  // checked by themeTokens.spec.ts.
  'color-contrast': { enabled: false },
  // jsdom has no layout: every box measures 0 × 0, so the size of the targets cannot be read.
  'target-size': { enabled: false },
}

/** What a person reads in the failure: the rule, why it matters, and the offending nodes. */
function describeViolations(violations: axe.Result[]): string {
  return violations
    .map((violation) => {
      const nodes = violation.nodes
        .slice(0, 5)
        .map((node) => `    - ${node.target.join(' ')}\n      ${node.html.slice(0, 160)}`)
        .join('\n')
      return `[${violation.impact}] ${violation.id}: ${violation.help}\n${nodes}\n    ${violation.helpUrl}`
    })
    .join('\n\n')
}

/**
 * Runs axe over the whole document (the page rules, such as one `<main>`, need it) and fails with a
 * readable list if anything of WCAG 2.2 AA is broken.
 */
export async function expectNoViolations(context: Document | Element = document): Promise<void> {
  const { violations } = await axe.run(context, {
    runOnly: { type: 'tag', values: TAGS },
    rules: NOT_EVALUABLE_IN_JSDOM,
  })
  if (violations.length > 0) {
    throw new Error(
      `${violations.length} accessibility violation(s):\n\n${describeViolations(violations)}`,
    )
  }
}

const NATIVE = 'a[href], button, input:not([type="hidden"]), select, textarea, summary'
const WIDGET_ROLES = [
  'button',
  'link',
  'checkbox',
  'radio',
  'switch',
  'tab',
  'menuitem',
  'menuitemcheckbox',
  'menuitemradio',
  'option',
  'combobox',
  'textbox',
  'slider',
]
const INTERACTIVE = [NATIVE, ...WIDGET_ROLES.map((role) => `[role="${role}"]`)].join(', ')

function isHidden(element: Element): boolean {
  if (element.closest('[hidden], [aria-hidden="true"], [inert]') !== null) return true
  // jsdom has no stylesheet of ours, so only inline display (v-show) can hide a box here.
  for (let node: Element | null = element; node !== null; node = node.parentElement) {
    if (getComputedStyle(node).display === 'none') return true
  }
  return false
}

/** A short version of the accessible name: enough to tell «has a name» from «has none». */
function accessibleName(element: HTMLElement): string {
  const labelledBy = element
    .getAttribute('aria-labelledby')
    ?.split(/\s+/)
    .map((id) => document.getElementById(id)?.textContent ?? '')
    .join(' ')
  const labels = 'labels' in element ? Array.from((element as HTMLInputElement).labels ?? []) : []
  const image = element.querySelector('img[alt]:not([alt=""])')?.getAttribute('alt')
  const value = element.matches('input[type="button"], input[type="submit"], input[type="reset"]')
    ? element.getAttribute('value')
    : null
  return (
    [
      labelledBy,
      element.getAttribute('aria-label'),
      ...labels.map((label) => label.textContent),
      value,
      element.textContent,
      image,
      element.getAttribute('title'),
    ]
      .map((part) => (part ?? '').trim())
      .find((part) => part !== '') ?? ''
  )
}

/**
 * Basic keyboard check of what is on screen: every interactive element can take focus, has an
 * accessible name, and nothing uses a positive `tabindex`. It does not check the order of the tabs
 * or focus traps (docs/accessibility-tests.md).
 */
export function expectKeyboardReady(root: ParentNode = document): void {
  const problems: string[] = []
  const name = (element: Element) =>
    `<${element.tagName.toLowerCase()}> ${element.outerHTML.slice(0, 120)}`

  for (const element of root.querySelectorAll<HTMLElement>('[tabindex]')) {
    if (Number(element.getAttribute('tabindex')) > 0)
      problems.push(`positive tabindex: ${name(element)}`)
  }

  for (const element of root.querySelectorAll<HTMLElement>(INTERACTIVE)) {
    if (
      isHidden(element) ||
      element.matches(':disabled') ||
      element.getAttribute('aria-disabled') === 'true'
    ) {
      continue
    }
    element.focus()
    if (document.activeElement !== element) problems.push(`cannot take focus: ${name(element)}`)
    if (accessibleName(element) === '') problems.push(`no accessible name: ${name(element)}`)
  }

  if (problems.length > 0) throw new Error(`Keyboard problems:\n- ${problems.join('\n- ')}`)
}
