import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import ImageField from '@/components/ImageField.vue'
import { fileSizeLabel, imageProblem } from '@/composables/imageUpload'

beforeEach(() => {
  // jsdom does not build object URLs.
  URL.createObjectURL = vi.fn(() => 'blob:preview')
  URL.revokeObjectURL = vi.fn()
})
afterEach(() => {
  document.body.innerHTML = ''
})

function fileOf(name: string, type: string, bytes: number): File {
  return new File([new Uint8Array(bytes)], name, { type })
}

function mountField(props: Record<string, unknown> = {}) {
  return mount(ImageField, {
    props: { label: 'Imagen del servicio (opcional)', ...props },
    attachTo: document.body,
  })
}

async function choose(wrapper: ReturnType<typeof mountField>, file: File) {
  const input = wrapper.get('[data-testid="image-input"]')
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  await input.trigger('change')
}

describe('imageProblem', () => {
  it('accepts JPG, PNG and WebP up to 2 MB', () => {
    expect(imageProblem({ type: 'image/jpeg', size: 1024 * 1024 })).toBe('')
    expect(imageProblem({ type: 'image/png', size: 2 * 1024 * 1024 })).toBe('')
    expect(imageProblem({ type: 'image/webp', size: 740 * 1024 })).toBe('')
  })

  it('turns down another type, whatever the name says', () => {
    expect(imageProblem({ type: 'application/pdf', size: 420 * 1024 })).toBe(
      'Solo se aceptan imágenes JPG, PNG o WebP.',
    )
  })

  it('turns down more than 2 MB naming the weight', () => {
    expect(imageProblem({ type: 'image/png', size: 3 * 1024 * 1024 })).toBe(
      'El archivo pesa 3 MB. Elija una imagen JPG, PNG o WebP de hasta 2 MB.',
    )
    expect(fileSizeLabel(1.5 * 1024 * 1024)).toBe('1,5 MB')
  })
})

describe('ImageField', () => {
  it('starts without image and offers to choose one', () => {
    const wrapper = mountField()
    expect(wrapper.text()).toContain('Aún sin imagen')
    expect(wrapper.text()).toContain('Elegir imagen')
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('shows the current image and offers to change it', () => {
    const wrapper = mountField({ currentUrl: '/api/v1/archivos/a.webp' })
    expect(wrapper.get('img').attributes('src')).toBe('/api/v1/archivos/a.webp')
    expect(wrapper.text()).toContain('Cambiar imagen')
    expect(wrapper.text()).not.toContain('Quitar')
  })

  it('accepts a valid file, shows its name and sends it to the model', async () => {
    const wrapper = mountField()
    const file = fileOf('corte-clasico.webp', 'image/webp', 740 * 1024)
    await choose(wrapper, file)
    expect(wrapper.emitted('update:modelValue')![0]).toEqual([file])
    expect(wrapper.get('[data-testid="image-name"]').text()).toContain('corte-clasico.webp')
    expect(wrapper.get('[data-testid="image-name"]').text()).toContain('WebP · 740 KB')
  })

  it('turns down a heavy file: error under the field, file kept on screen, nothing sent', async () => {
    const wrapper = mountField()
    await choose(wrapper, fileOf('rapado.png', 'image/png', 3 * 1024 * 1024))
    expect(wrapper.get('p.text-danger').text()).toContain('El archivo pesa 3 MB')
    expect(wrapper.get('[data-testid="image-name"]').text()).toContain('rapado.png')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('turns down a PDF', async () => {
    const wrapper = mountField()
    await choose(wrapper, fileOf('rapado.pdf', 'application/pdf', 420 * 1024))
    expect(wrapper.get('p.text-danger').text()).toBe('Solo se aceptan imágenes JPG, PNG o WebP.')
  })

  it('shows the error of the API for the field and links it to the button', () => {
    const wrapper = mountField({ error: 'La imagen no es válida.' })
    expect(wrapper.get('p.text-danger').text()).toBe('La imagen no es válida.')
    expect(wrapper.get('button').attributes('aria-describedby')).toContain('-error')
  })

  it('a valid file after a rejected one clears the error', async () => {
    const wrapper = mountField()
    await choose(wrapper, fileOf('a.pdf', 'application/pdf', 10))
    await choose(wrapper, fileOf('a.png', 'image/png', 10 * 1024))
    expect(wrapper.find('p.text-danger').exists()).toBe(false)
  })
})
