import { createPinia, setActivePinia } from 'pinia'
import { useAppraisalHandoffStore } from '@/stores/appraisalHandoff'

const TRITANIUM_AND_PYERITE = 'Tritanium\t12000\nPyerite\t4500'
const MORPHITE_ONLY = 'Morphite\t37'

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('appraisalHandoff store', () => {
  it('hands over the last sent text once and empties the pending text', () => {
    const handoff = useAppraisalHandoffStore()

    handoff.send(TRITANIUM_AND_PYERITE)
    handoff.send(MORPHITE_ONLY)

    expect(handoff.take()).toBe(MORPHITE_ONLY)
    expect(handoff.pendingText).toBeNull()
    expect(handoff.take()).toBeNull()
  })

  it('ignores an empty or blank text', () => {
    const handoff = useAppraisalHandoffStore()

    handoff.send('')
    handoff.send('  \n\t ')

    expect(handoff.pendingText).toBeNull()
    expect(handoff.take()).toBeNull()
  })
})
