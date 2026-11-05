<script setup lang="ts">
import { ref } from 'vue'
import { Banknote, CreditCard, Scissors } from 'lucide-vue-next'

import BaseButton from '@/components/BaseButton.vue'
import BaseLink from '@/components/BaseLink.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import OptionCard from '@/components/OptionCard.vue'
import OptionGroup from '@/components/OptionGroup.vue'
import SelectChip from '@/components/SelectChip.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StatusLabel from '@/components/StatusLabel.vue'
import TextField from '@/components/TextField.vue'

const reservationStatuses = [
  'temporary',
  'confirmed',
  'completed',
  'no_show',
  'cancelled_by_customer',
  'expired',
] as const
const paymentStatuses = ['pending', 'approved', 'rejected', 'failed', 'refunded'] as const

const chipFree = ref(false)
const chipSelected = ref(true)
const service = ref<string | null>('corte')
const method = ref<string | null>(null)
const name = ref('')
const nameWithError = ref('')
const noticeVisible = ref(true)
const retries = ref(0)
</script>

<template>
  <main class="mx-auto flex max-w-3xl flex-col gap-12 p-5 md:p-8">
    <header class="flex flex-col gap-2">
      <h1 class="text-2xl text-fg">Catálogo de componentes</h1>
      <p class="text-sm text-fg-muted">
        Solo disponible en desarrollo. Todos los estados se pueden recorrer con el teclado.
      </p>
    </header>

    <section aria-labelledby="cat-button" class="flex flex-col gap-4">
      <h2 id="cat-button" class="text-lg font-medium">BaseButton</h2>
      <div class="flex flex-wrap items-center gap-2">
        <BaseButton>Reservar cita</BaseButton>
        <BaseButton size="large">Continuar al pago</BaseButton>
        <BaseButton variant="secondary">Cancelar</BaseButton>
        <BaseButton disabled>Deshabilitado</BaseButton>
        <BaseButton loading>Guardando</BaseButton>
        <BaseButton variant="secondary" loading>Cargando</BaseButton>
        <BaseButton variant="secondary" disabled>Deshabilitado</BaseButton>
        <BaseButton>
          <template #icon><Scissors :size="16" :stroke-width="1.5" aria-hidden="true" /></template>
          Con ícono
        </BaseButton>
      </div>
    </section>

    <section aria-labelledby="cat-link" class="flex flex-col gap-4">
      <h2 id="cat-link" class="text-lg font-medium">BaseLink</h2>
      <div class="flex flex-wrap items-center gap-4">
        <BaseLink href="#cat-link">Ver servicios</BaseLink>
        <BaseLink href="#cat-link" current>Página actual</BaseLink>
        <BaseLink href="https://www.google.com/maps" external>Ver en Google Maps</BaseLink>
      </div>
    </section>

    <section aria-labelledby="cat-chip" class="flex flex-col gap-4">
      <h2 id="cat-chip" class="text-lg font-medium">SelectChip</h2>
      <div class="flex flex-wrap items-center gap-2">
        <SelectChip v-model="chipFree">10:30</SelectChip>
        <SelectChip v-model="chipSelected">11:15</SelectChip>
        <SelectChip busy>12:00</SelectChip>
      </div>
    </section>

    <section aria-labelledby="cat-option" class="flex flex-col gap-4">
      <h2 id="cat-option" class="text-lg font-medium">OptionGroup y OptionCard</h2>
      <p id="cat-service-label" class="text-sm text-fg-muted">Servicio (fila)</p>
      <OptionGroup v-model="service" aria-labelledby="cat-service-label">
        <OptionCard value="corte" variant="row">
          Corte clásico
          <template #description>
            <span class="tabular-nums">45 min · ₡10.000</span>
          </template>
        </OptionCard>
        <OptionCard value="barba" variant="row">
          Barba
          <template #description>
            <span class="tabular-nums">30 min · ₡6.000</span>
          </template>
        </OptionCard>
        <OptionCard value="combo" variant="row" disabled>
          Corte y barba
          <template #description>
            <span class="tabular-nums">60 min · ₡14.000</span>
          </template>
        </OptionCard>
      </OptionGroup>
      <p id="cat-method-label" class="text-sm text-fg-muted">Método de pago (ficha)</p>
      <OptionGroup v-model="method" aria-labelledby="cat-method-label">
        <OptionCard value="card" :icon="CreditCard">
          Tarjeta
          <template #description>Crédito o débito</template>
        </OptionCard>
        <OptionCard value="sinpe" :icon="Banknote">
          SINPE Móvil
          <template #description>Transferencia desde su celular</template>
        </OptionCard>
      </OptionGroup>
    </section>

    <section aria-labelledby="cat-field" class="flex flex-col gap-4">
      <h2 id="cat-field" class="text-lg font-medium">TextField</h2>
      <TextField v-model="name" label="Nombre" placeholder="Escriba su nombre" />
      <TextField
        v-model="nameWithError"
        label="Correo"
        type="email"
        error="El correo no es válido. Revise que tenga el formato nombre@dominio.com."
      />
      <TextField label="Teléfono" model-value="8888 8888" disabled />
    </section>

    <section aria-labelledby="cat-status" class="flex flex-col gap-4">
      <h2 id="cat-status" class="text-lg font-medium">StatusLabel</h2>
      <div class="flex flex-wrap gap-4">
        <StatusLabel v-for="status in reservationStatuses" :key="status" :status="status" />
      </div>
      <div class="flex flex-wrap gap-4">
        <StatusLabel v-for="status in paymentStatuses" :key="status" :status="status" />
      </div>
    </section>

    <section aria-labelledby="cat-notice" class="flex flex-col gap-4">
      <h2 id="cat-notice" class="text-lg font-medium">NoticeBanner</h2>
      <NoticeBanner :visible="noticeVisible">
        Su reserva quedó confirmada.
        <template #action>
          <BaseButton variant="secondary" @click="noticeVisible = false">Cerrar</BaseButton>
        </template>
      </NoticeBanner>
      <BaseButton v-if="!noticeVisible" variant="secondary" @click="noticeVisible = true">
        Mostrar aviso
      </BaseButton>
      <NoticeBanner variant="error">
        No pudimos guardar los cambios. Revise su conexión e intente de nuevo.
      </NoticeBanner>
    </section>

    <section aria-labelledby="cat-skeleton" class="flex flex-col gap-4">
      <h2 id="cat-skeleton" class="text-lg font-medium">SkeletonBlock</h2>
      <SkeletonBlock shape="text" :count="2" />
      <SkeletonBlock shape="row" :count="3" />
      <SkeletonBlock shape="block" />
    </section>

    <section aria-labelledby="cat-empty" class="flex flex-col gap-4">
      <h2 id="cat-empty" class="text-lg font-medium">EmptyState</h2>
      <EmptyState
        title="No hay reservas para el sáb 7 nov."
        description="Cuando alguien reserve, la cita aparecerá aquí."
      >
        <template #action><BaseButton variant="secondary">Ir a hoy</BaseButton></template>
      </EmptyState>
    </section>

    <section aria-labelledby="cat-error" class="flex flex-col gap-4">
      <h2 id="cat-error" class="text-lg font-medium">ErrorState</h2>
      <ErrorState message="Error: no pudimos cargar las reservas." @retry="retries++" />
      <p class="text-sm text-fg-muted tabular-nums">Reintentos: {{ retries }}</p>
    </section>
  </main>
</template>
