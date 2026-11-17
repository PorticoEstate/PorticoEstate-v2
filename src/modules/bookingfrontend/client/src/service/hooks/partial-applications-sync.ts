import type {QueryClient} from "@tanstack/react-query";
import type {IApplication} from "@/service/types/api/application.types";

/**
 * Keeping the cart (the partial applications query) in step with the server when its updates
 * come over the WebSocket.
 *
 * The WebSocket may add an application to the cart or update one, and it may remove one the
 * server says it removed (diff.removed) or the user is deleting here. A list that drops anything
 * else, or lacks an application it says was just added, is not taken as it is: the cart is read
 * over REST, and REST decides. A WebSocket server that cannot read the database used to answer
 * with an empty list, and the cart emptied itself after every add.
 */

export const PARTIAL_APPLICATIONS_QUERY_KEY = ['partialApplications'];

export type PartialApplicationsCache = { list: IApplication[], total_sum: number };

export interface PartialApplicationsDiff {
	added?: number[] | null;
	removed?: number[] | null;
}

/** The data of a partial_applications_response: a push (source 'server_push') or a reply. */
export interface PartialApplicationsMessageData {
	error?: boolean;
	applications?: IApplication[];
	seq?: number;
	diff?: PartialApplicationsDiff | null;
	source?: string;
}

export function partialApplicationsTotal(list: readonly IApplication[]): number {
	return list.reduce((sum, app) => {
		const orderSum = app.orders?.reduce((acc: number, order: any) => acc + (Number(order.sum) || 0), 0) || 0;
		return sum + orderSum;
	}, 0);
}

/**
 * True when a list from the WebSocket drops an application the cart holds without the server
 * saying it removed it (diff.removed) or the user deleting it here (pendingDeletions), or lacks
 * an application the same message says was added.
 */
export function isStalePartialApplicationsList(
	current: readonly Pick<IApplication, 'id'>[] | undefined,
	incoming: readonly Pick<IApplication, 'id'>[],
	diff: PartialApplicationsDiff | null | undefined,
	pendingDeletions: ReadonlySet<number>,
): boolean {
	const incomingIds = new Set(incoming.map(app => app.id));
	if (diff?.added?.some(id => !incomingIds.has(id))) {
		return true;
	}
	if (!current) {
		return false;
	}
	const removed = new Set(diff?.removed ?? []);
	return current.some(app => !incomingIds.has(app.id) && !removed.has(app.id) && !pendingDeletions.has(app.id));
}

/**
 * What the cart does with one partial_applications_response:
 * - 'apply': take it as it is;
 * - 'ignore': leave the cart alone (an older list, or a reply: the query that asked for a reply
 *   checks it and reads REST itself);
 * - 'read-rest': a push that failed or is stale; read the cart over REST.
 */
export function judgePartialApplicationsMessage(
	current: PartialApplicationsCache | undefined,
	data: PartialApplicationsMessageData,
	lastSeq: number,
	pendingDeletions: ReadonlySet<number>,
): 'apply' | 'ignore' | 'read-rest' {
	const isPush = data.source === 'server_push';
	if (data.error !== false) {
		return isPush ? 'read-rest' : 'ignore';
	}
	const seq = data.seq ?? 0;
	if (seq > 0 && seq < lastSeq && !data.diff) {
		return 'ignore'; // an older full list, already superseded
	}
	if (isStalePartialApplicationsList(current?.list, data.applications ?? [], data.diff, pendingDeletions)) {
		return isPush ? 'read-rest' : 'ignore';
	}
	return 'apply';
}

/**
 * Runs `read` at most once at a time. Every mounted usePartialApplications hears the same push,
 * so one stale push asks for as many reads as there are hooks: the calls made while a read is
 * running share ONE more read, started after it, so the last read always starts after the last
 * call (a read that started before a change cannot stand in for one asked for after it).
 */
export function coalesceReads(read: () => Promise<void>): () => Promise<void> {
	let running: Promise<void> | null = null;
	let next: Promise<void> | null = null;
	const start = (): Promise<void> => {
		const current: Promise<void> = read().finally(() => {
			if (running === current) running = null;
		});
		running = current;
		return current;
	};
	return () => {
		if (!running) return start();
		next ??= running.catch(() => undefined).then(() => {
			next = null;
			return start();
		});
		return next;
	};
}

/**
 * Reads the cart over REST and puts it in the query cache, never through the query's own
 * queryFn (which asks the WebSocket first). Nothing is asked again over the WebSocket, and a
 * failed read leaves the cart as it is.
 */
export async function readPartialApplicationsOverRest(
	queryClient: QueryClient,
	fetchList: () => Promise<PartialApplicationsCache>,
	pendingDeletions: ReadonlySet<number>,
): Promise<void> {
	await queryClient.cancelQueries({queryKey: PARTIAL_APPLICATIONS_QUERY_KEY});
	const data = await fetchList();
	if (!Array.isArray(data?.list)) {
		throw new Error('The partial applications could not be read');
	}
	const list = pendingDeletions.size > 0
		? data.list.filter(app => !pendingDeletions.has(app.id))
		: data.list;
	queryClient.setQueryData<PartialApplicationsCache>(PARTIAL_APPLICATIONS_QUERY_KEY, {
		list,
		total_sum: list === data.list ? data.total_sum : partialApplicationsTotal(list),
	});
}
