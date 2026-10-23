import { mount } from '@vue/test-utils'
import router from '@/router'
import ShareLinkCard from '@/components/group-industry/ShareLinkCard.vue'
import { groupJoinUrl } from '@/views/GroupIndustry/groupJoinUrl'

const ORIGIN = 'https://evetools.example'
const SHORT_LINK_CODE = 'K7Q2MX'

describe('groupJoinUrl', () => {
  it('builds the join link from the origin and the short link code', () => {
    expect(groupJoinUrl(ORIGIN, SHORT_LINK_CODE)).toBe('https://evetools.example/join/K7Q2MX')
  })

  it('resolves the join link path to the group industry join route with the short link code', () => {
    const resolved = router.resolve(new URL(groupJoinUrl(ORIGIN, SHORT_LINK_CODE)).pathname)

    expect(resolved.name).toBe('group-industry-join')
    expect(resolved.params.code).toBe('K7Q2MX')
  })
})

describe('ShareLinkCard', () => {
  it('shows the same join link as groupJoinUrl', () => {
    const wrapper = mount(ShareLinkCard, { props: { shortLinkCode: 'P3ZR8N' } })

    expect(wrapper.get('input').element.value).toBe(groupJoinUrl(window.location.origin, 'P3ZR8N'))
  })
})
