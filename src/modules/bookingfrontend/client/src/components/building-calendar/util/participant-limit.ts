/**
 * The three-step participant-limit fallback shared by the calendar popper
 * (event-popper-content.tsx) and the manage modal (manage-modal.tsx): an entity's own
 * limit (event only — booking/allocation carry no such field), else the first resource
 * with a limit, else the server's configured default. Kept in one place so the two
 * surfaces cannot drift out of sync with each other.
 */
export interface ParticipantLimitSource {
	type: 'booking' | 'allocation' | 'event';
	participant_limit?: number | null;
	resources: { participant_limit?: number | null }[];
}

export const resolveParticipantLimit = (entity: ParticipantLimitSource, serverConfigLimit?: number | null): number => {
	let limit = entity.type === 'event' ? (entity.participant_limit || 0) : 0;

	if (!limit) {
		const resourceWithLimit = entity.resources.find((resource) => (resource.participant_limit || 0) > 0);
		limit = resourceWithLimit?.participant_limit || 0;
	}

	if (!limit) {
		limit = serverConfigLimit || 0;
	}

	return limit;
};
