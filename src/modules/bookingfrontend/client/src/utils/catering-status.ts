/**
 * Application statuses in which Min side does not offer catering at all (GH #1423): no section, no
 * "Legg til bevertning", and the orders already placed are not listed either. The server refuses
 * every write on these with `application_closed` (HospitalityOrderGuard); this only stops the page
 * from presenting something the applicant can no longer act on.
 *
 * A deny-list on purpose, kept apart from the component so it is one line to change: an unknown,
 * lower-case or missing status keeps today's behaviour (the section is shown, and the server still
 * decides what is allowed). `status` is a plain string in IApplication, so a misspelt status here
 * compiles, which is why tests/hospitality-deadline/run_closed.mts checks the values themselves.
 */
export const CATERING_CLOSED_APPLICATION_STATUSES: readonly string[] = ['REJECTED', 'CANCELLED'];

export const isCateringClosed = (status: string | null | undefined): boolean =>
    status !== null && status !== undefined && CATERING_CLOSED_APPLICATION_STATUSES.includes(status);
