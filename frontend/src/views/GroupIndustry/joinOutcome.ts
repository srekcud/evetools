export type JoinOutcome = 'redirect' | 'pending'

/** Only an accepted membership can open the project detail (403 for pending members). */
export function joinOutcome(project: { myStatus: 'accepted' | 'pending' | null }): JoinOutcome {
  return project.myStatus === 'accepted' ? 'redirect' : 'pending'
}
