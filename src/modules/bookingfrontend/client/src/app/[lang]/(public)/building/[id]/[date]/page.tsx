import BuildingShow from '../page';

interface BuildingShowWithDateProps {
    params: { id: string; date: string };
}

// Reuse the parent page component but pass the date via searchParams
export default async function BuildingShowWithDate(props: BuildingShowWithDateProps) {
    return BuildingShow({
        params: {
            id: props.params.id,
        },
        searchParams: {
            initialDate: props.params.date,
        },
    });
}
