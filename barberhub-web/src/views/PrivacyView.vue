<script setup lang="ts">
import { useRouter } from 'vue-router'
import { ChevronLeft } from 'lucide-vue-next'

import BrandLogo from '@/components/BrandLogo.vue'
import InlineNotice from '@/components/InlineNotice.vue'
import { privacyDocument as doc } from './legal/privacyContent'

const router = useRouter()

function goBack(): void {
  // vue-router keeps the previous entry in history.state; without it (direct visit) go to the home page.
  if (window.history.state?.back) router.back()
  else void router.push('/')
}
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <header class="flex h-16 flex-none items-center justify-between gap-2 px-5 md:px-8">
      <button
        type="button"
        class="-ml-3 flex min-h-11 items-center gap-1 rounded-pill pr-4 pl-2.5 text-sm font-medium text-fg transition-colors duration-150 ease-out hover:bg-surface"
        @click="goBack"
      >
        <ChevronLeft :size="18" :stroke-width="1.5" aria-hidden="true" />
        Volver
      </button>
      <BrandLogo />
    </header>
    <main class="px-5 pt-4 pb-16 md:px-8 md:pt-8">
      <div class="mx-auto w-full max-w-[58ch]">
        <InlineNotice title="Texto de ejemplo.">
          El contenido final lo define el negocio.
        </InlineNotice>
        <h1 class="mt-8 text-2xl">{{ doc.title }}</h1>
        <p class="mt-2 text-sm text-fg-subtle">
          Última actualización: {{ doc.updated }} · Versión {{ doc.version }}
        </p>
        <template v-for="section in doc.sections" :key="section.heading">
          <h2 class="mt-10 mb-2 text-lg font-medium">{{ section.heading }}</h2>
          <template v-for="(block, index) in section.blocks" :key="index">
            <p v-if="block.kind === 'p'" class="mb-4 leading-relaxed">
              <component
                :is="part.is"
                v-for="(part, partIndex) in block.parts"
                :key="partIndex"
                :to="part.to"
                :class="part.class"
                >{{ part.text }}</component
              >
            </p>
            <ul
              v-else-if="block.kind === 'ul'"
              class="mb-4 list-disc space-y-2 pl-5 leading-relaxed"
            >
              <li v-for="(item, itemIndex) in block.items" :key="itemIndex">
                <component
                  :is="part.is"
                  v-for="(part, partIndex) in item"
                  :key="partIndex"
                  :to="part.to"
                  :class="part.class"
                  >{{ part.text }}</component
                >
              </li>
            </ul>
            <div v-else class="mb-4 overflow-x-auto">
              <table class="w-full min-w-[32rem] border-collapse text-left text-sm">
                <thead>
                  <tr>
                    <th
                      v-for="(cell, cellIndex) in block.head"
                      :key="cellIndex"
                      scope="col"
                      class="border-b border-line-strong py-2 pr-4 align-top font-medium"
                    >
                      <component
                        :is="part.is"
                        v-for="(part, partIndex) in cell"
                        :key="partIndex"
                        :to="part.to"
                        :class="part.class"
                        >{{ part.text }}</component
                      >
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, rowIndex) in block.rows" :key="rowIndex">
                    <td
                      v-for="(cell, cellIndex) in row"
                      :key="cellIndex"
                      class="border-b border-line py-3 pr-4 align-top"
                    >
                      <component
                        :is="part.is"
                        v-for="(part, partIndex) in cell"
                        :key="partIndex"
                        :to="part.to"
                        :class="part.class"
                        >{{ part.text }}</component
                      >
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </template>
      </div>
    </main>
  </div>
</template>
