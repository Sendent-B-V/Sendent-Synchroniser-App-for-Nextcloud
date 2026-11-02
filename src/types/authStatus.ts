/** The user's Microsoft sign-in status as the sync service last reported it. */
export interface AuthStatus {
	provider: string
	/** notConnected, active or needsReconnect; anything else is treated as needing attention. */
	status: string
	/** A short machine code, only with needsReconnect. Unknown codes are shown generically. */
	reason: string | null
	/** UTC seconds. */
	changedAt: number
}

/** What GET /api/1.0/graphAuth/status answers for the logged-in user. */
export interface MyStatusResponse {
	signInMode: string
	activeUser: boolean
	status: AuthStatus | null
}

/**
 * True for both per-user modes; anything else (including unknown) is the service account.
 *
 * @param mode the stored sign-in mode as the server reports it
 */
export function isPerUserMode(mode: string): boolean {
	return mode === 'perUserFallback' || mode === 'perUserStrict'
}

/**
 * Whether the user should be asked to connect (or reconnect) their Microsoft
 * account instead of running the consent flow. Only for users whose consent
 * is in place: without consent the normal flow applies and ends with the
 * Microsoft step anyway.
 *
 * - needsReconnect asks in both per-user modes: the sign-in broke.
 * - Under perUserStrict a user who never connected (no status yet, or
 *   notConnected) is asked too, because nothing syncs for them until they do.
 *
 * @param activeUser consent is in place
 * @param signInMode the org-wide sign-in mode
 * @param status the stored Microsoft status, null when the service never reported one
 */
export function needsMicrosoftAttention(activeUser: boolean, signInMode: string, status: AuthStatus | null | undefined): boolean {
	if (!activeUser || !isPerUserMode(signInMode)) return false
	if (status?.status === 'needsReconnect') return true
	return signInMode === 'perUserStrict' && status?.status !== 'active'
}
