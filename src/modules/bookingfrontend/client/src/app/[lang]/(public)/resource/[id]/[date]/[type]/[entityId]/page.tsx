import Resource from '../../../page';

interface ResourceShowHighlightProps {
    params: { id: string; date: string; type: string; entityId: string };
}

// Deep link from an application's reserved times into the resource calendar:
// opens the resource at the entity's date and highlights the specific
// schedule entity (event/allocation/booking).
export default async function ResourceShowHighlight(props: ResourceShowHighlightProps) {
    return Resource({
        params: {
            id: props.params.id,
        },
        searchParams: {
            initialDate: props.params.date,
            highlightType: props.params.type,
            highlightId: props.params.entityId,
        },
    });
}
