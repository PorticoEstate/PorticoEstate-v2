// Shim for @/service/hooks/api-hooks — used in widget builds only.
// The widget passes all data as props, so these hooks just return {data: undefined}.
const noop = () => ({data: undefined, isLoading: false, error: null});

export const useSearchData = noop;
export const useTowns = noop;
export const useMultiDomains = noop;
