/**
 * Quick check of an image before it travels (SV-06). It only spares a trip: the API reads the type from the
 * content of the file and decides, so its answer always wins.
 */

export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'] as const
export const IMAGE_ACCEPT = IMAGE_TYPES.join(',')
export const MAX_IMAGE_BYTES = 2 * 1024 * 1024

export const WRONG_TYPE_MESSAGE = 'Solo se aceptan imágenes JPG, PNG o WebP.'

/** «740 KB», «1,5 MB». */
export function fileSizeLabel(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`
  const megabytes = Math.round((bytes / (1024 * 1024)) * 10) / 10
  return `${String(megabytes).replace('.', ',')} MB`
}

/** The problem of a file as a message that names it and the way out, or an empty text when it is fine. */
export function imageProblem(file: Pick<File, 'type' | 'size'>): string {
  if (!(IMAGE_TYPES as readonly string[]).includes(file.type)) return WRONG_TYPE_MESSAGE
  if (file.size > MAX_IMAGE_BYTES) {
    return `El archivo pesa ${fileSizeLabel(file.size)}. Elija una imagen JPG, PNG o WebP de hasta 2 MB.`
  }
  return ''
}

/** «WebP», «PNG»… for the line under the file name. */
export function imageTypeLabel(type: string): string {
  if (type === 'image/jpeg') return 'JPG'
  if (type === 'image/png') return 'PNG'
  if (type === 'image/webp') return 'WebP'
  return type.split('/')[1]?.toUpperCase() ?? 'Archivo'
}

export interface ImageSize {
  width: number
  height: number
}

/** 1200 → «1.200», as the design writes the pixels. */
export function pixels(value: number): string {
  return String(Math.round(value)).replace(/\B(?=(\d{3})+(?!\d))/g, '.')
}

/** «1.600 × 533 px». */
export function sizeLabel(size: ImageSize): string {
  return `${pixels(size.width)} × ${pixels(size.height)} px`
}

/** The size of a chosen image, or `null` when the browser cannot read it: then the API decides. */
export async function readImageSize(file: Blob): Promise<ImageSize | null> {
  try {
    if (typeof createImageBitmap === 'function') {
      const bitmap = await createImageBitmap(file)
      const size = { width: bitmap.width, height: bitmap.height }
      bitmap.close()
      return size
    }
    if (typeof Image === 'function' && typeof URL.createObjectURL === 'function') {
      const url = URL.createObjectURL(file)
      try {
        return await new Promise<ImageSize | null>((resolve) => {
          const image = new Image()
          image.onload = () => resolve({ width: image.naturalWidth, height: image.naturalHeight })
          image.onerror = () => resolve(null)
          image.src = url
        })
      } finally {
        URL.revokeObjectURL(url)
      }
    }
  } catch {
    // Not an image the browser can decode: the API will say so.
  }
  return null
}

/** The message of an image smaller than the minimum, or an empty text. */
export function smallImageProblem(
  size: ImageSize,
  minimum: ImageSize,
  subject = 'La imagen',
): string {
  if (size.width >= minimum.width && size.height >= minimum.height) return ''
  return `${subject} mide ${sizeLabel(size)}. Debe medir al menos ${sizeLabel(minimum)}.`
}
