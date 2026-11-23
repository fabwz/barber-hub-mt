import { useRoute } from 'vue-router'

import { safeRedirect } from '@/router/guard'
import { useActiveShopStore } from '@/stores/activeShop'

/**
 * Where a person goes once they are in (after signing in, or after the mandatory password change).
 * A valid `redirect` (checked in #75) is respected as is. Without one, staff go to the shop selector
 * (UP-08) and everyone else to «/». If the shops cannot be loaded, the destination is still «/».
 */
export function useDestinationAfterLogin(): () => Promise<string> {
  const route = useRoute()
  const activeShop = useActiveShopStore()

  return async () => {
    const redirect = safeRedirect(route.query.redirect)
    if (redirect !== '/') return redirect
    await activeShop.loadStaffShops()
    return activeShop.status === 'ready' && activeShop.shops.length > 0 ? '/negocios' : '/'
  }
}
