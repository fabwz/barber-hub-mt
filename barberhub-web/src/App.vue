<script setup lang="ts"></script>

<template>
  <!-- RegisterView stays alive so the form is not lost when the person reads the legal pages. -->
  <RouterView v-slot="{ Component }">
    <KeepAlive include="RegisterView">
      <component :is="Component" />
    </KeepAlive>
  </RouterView>
</template>
