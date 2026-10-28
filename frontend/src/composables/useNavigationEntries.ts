export interface NavigationEntry {
  route: string
  label: string
  aliasLabel: string
  isTab: boolean
}

export interface NavigationEntriesOptions {
  isAdmin: boolean
}

export function useNavigationEntries(): {
  listNavigationEntries: (options: NavigationEntriesOptions) => NavigationEntry[]
} {
  return {
    listNavigationEntries: () => {
      throw new Error('not implemented')
    },
  }
}
