import router from '@/router'
import { SITE_ID } from './miningSiteFixtures'

describe('mining site route', () => {
  it('resolves the page of a site by its id under the mining-site-detail name', () => {
    const resolved = router.resolve(`/mining-sites/${SITE_ID}`)

    expect({ name: resolved.name, id: resolved.params.id, requiresAuth: resolved.meta.requiresAuth }).toEqual({
      name: 'mining-site-detail',
      id: SITE_ID,
      requiresAuth: true,
    })
  })
})
