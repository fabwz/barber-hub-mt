<script setup lang="ts">
import { useId } from 'vue'

export interface IncomeRow {
  label: string
  value: string
}

interface Props {
  /** A small line above the title, e.g. «Aparte». */
  eyebrow?: string
  title: string
  /** The figure of the block, already formatted. */
  total: string
  note: string
  rows: IncomeRow[]
}

const { eyebrow = '', title, total, note, rows } = defineProps<Props>()

const titleId = useId()
</script>

<template>
  <section
    :aria-labelledby="titleId"
    class="flex min-w-0 flex-col gap-3 rounded-card border border-line p-5"
  >
    <p v-if="eyebrow" class="text-sm text-fg-muted">{{ eyebrow }}</p>
    <h2 :id="titleId" class="text-base font-medium text-fg">{{ title }}</h2>
    <p class="text-4xl font-medium break-words text-fg tabular-nums">{{ total }}</p>
    <p class="text-sm text-fg-muted">{{ note }}</p>
    <dl class="mt-1 flex flex-col border-t border-line">
      <div
        v-for="row in rows"
        :key="row.label"
        class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-line py-3 last:border-b-0"
      >
        <dt class="text-sm text-fg-muted">{{ row.label }}</dt>
        <dd class="text-base font-medium break-words text-fg tabular-nums">{{ row.value }}</dd>
      </div>
    </dl>
  </section>
</template>
