import BuildingShow from '../../../page';

interface BuildingShowHighlightProps {
    params: { id: string; date: string; type: string; entityId: string };
}

// Deep link from an application's reserved times into the building calendar:
// opens the building at the entity's date and highlights the specific
// schedule entity (event/allocation/booking).
export default async function BuildingShowHighlight(props: BuildingShowHighlightProps) {
    return BuildingShow({
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
