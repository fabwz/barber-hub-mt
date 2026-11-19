<script setup lang="ts">
// Sizes are in rem like the rest of the app, so the sample grows with the root font size the
// person chose: what it shows is what the screens will look like.
</script>

<template>
  <div class="rounded-card border border-line bg-surface p-4" data-testid="text-sample">
    <p class="text-xs font-medium text-fg-muted">Muestra</p>
    <div class="mt-2 flex justify-between gap-3">
      <span class="font-medium">Corte + barba</span>
      <span class="tabular-nums">₡10.000</span>
    </div>
    <p class="text-sm text-fg-muted tabular-nums">45 min · jue 5 nov, 10:30</p>
    <p class="mt-3">
      Así se ve el texto de BarberHub con este tamaño. Puede cambiarlo cuando quiera.
    </p>
  </div>
</template>
