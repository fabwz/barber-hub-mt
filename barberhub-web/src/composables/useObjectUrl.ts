import { onBeforeUnmount, ref, watch, type Ref } from 'vue'

/** A temporary address to show a chosen file; it is released when the file changes or the screen closes. */
export function useObjectUrl(file: Ref<File | null>): Ref<string | null> {
  const url = ref<string | null>(null)

  function release(): void {
    if (url.value !== null && typeof URL.revokeObjectURL === 'function') {
      URL.revokeObjectURL(url.value)
    }
    url.value = null
  }

  watch(
    file,
    (chosen) => {
      release()
      if (chosen !== null && typeof URL.createObjectURL === 'function') {
        url.value = URL.createObjectURL(chosen)
      }
    },
    { immediate: true },
  )
  onBeforeUnmount(release)
  return url
}
