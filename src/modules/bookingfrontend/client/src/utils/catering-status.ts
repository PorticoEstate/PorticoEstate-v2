/**
 * Application statuses in which Min side shows catering READ-ONLY (GH #1423): the orders already
 * placed stay on view, but nothing is offered: no "Legg til bevertning", no Rediger / Avbestill, no
 * deadline hints. The server refuses every write on these with `application_closed`
 * (HospitalityOrderGuard); this only stops the page from presenting something the applicant can no
 * longer act on.
 *
 * A deny-list on purpose, kept apart from the component so it is one line to change: an unknown,
 * lower-case or missing status keeps today's behaviour (the section is shown, and the server still
 * decides what is allowed). `status` is a plain string in IApplication, so a misspelt status here
 * compiles, which is why tests/hospitality-deadline/run_closed.mts checks the values themselves.
 */
export const CATERING_CLOSED_APPLICATION_STATUSES: readonly string[] = ['REJECTED', 'CANCELLED'];

export const isCateringClosed = (status: string | null | undefined): boolean =>
    status !== null && status !== undefined && CATERING_CLOSED_APPLICATION_STATUSES.includes(status);

/**
 * Whether the catering section is shown at all. `orderCount` is the orders that are still on (a
 * cancelled order is not counted), `hospitalityCount` what is offered for the booked resources.
 *
 * A section is shown for an order, or for something to add. A read-only application has nothing to
 * add, so only its orders bring the section: no orders means no section, whatever is on offer.
 */
export const showsCateringSection = (
    status: string | null | undefined,
    orderCount: number,
    hospitalityCount: number,
): boolean => orderCount > 0 || (!isCateringClosed(status) && hospitalityCount > 0);
