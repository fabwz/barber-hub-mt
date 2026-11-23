<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import ClosuresPanel from '@/components/ClosuresPanel.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import ScheduleDay from '@/components/ScheduleDay.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import { useScheduleStore } from '@/stores/schedule'

// «Horario de la barbería» (RF-04, HO-01, HO-03; lámina 32), a section of Equipo. Every change applies at once.
const route = useRoute()
const store = useScheduleStore()

const barberia = computed(() => String(route.params.barberia))

onMounted(() => store.load(barberia.value))

const DAYS = [
  { day: 1, label: 'Lunes' },
  { day: 2, label: 'Martes' },
  { day: 3, label: 'Miércoles' },
  { day: 4, label: 'Jueves' },
  { day: 5, label: 'Viernes' },
  { day: 6, label: 'Sábado' },
  { day: 7, label: 'Domingo' },
]

const done = ref('')
</script>

<template>
  <section aria-labelledby="schedule-title" :aria-busy="store.status === 'loading'">
    <h2 id="schedule-title" class="text-lg font-medium">Horario semanal</h2>
    <p class="mt-1 text-sm text-fg-muted">
      Cada día puede tener una o más franjas. Las horas son de Costa Rica, en formato de 24 horas.
      Cada cambio se aplica al instante.
    </p>

    <SkeletonBlock
      v-if="store.status === 'idle' || (store.status === 'loading' && store.slots.length === 0)"
      class="mt-4"
      shape="row"
      :count="7"
      label="Cargando el horario"
    />
    <ErrorState
      v-else-if="store.status === 'error'"
      class="mt-4"
      message="No pudimos cargar el horario"
      description="Revise su conexión e inténtelo de nuevo."
      @retry="store.load(barberia)"
    />
    <template v-else>
      <NoticeBanner v-if="done" class="mt-4">{{ done }}</NoticeBanner>
      <!-- HO-04 (láminas 32h a 32j): the list of confirmed reservations that fall outside the schedule is deferred to
           #101. The notice goes here, under the confirmation, when its API exists. -->
      <div class="mt-2">
        <ScheduleDay
          v-for="item in DAYS"
          :key="item.day"
          :barberia="barberia"
          :day="item.day"
          :label="item.label"
          @done="(message: string) => (done = message)"
        />
      </div>
      <ClosuresPanel :barberia="barberia" @done="(message: string) => (done = message)" />
    </template>
  </section>
</template>
