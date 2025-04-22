import BuildingShow from '../page';

interface BuildingShowWithDateParams {
    id: string;
    date: string;
}

interface BuildingShowWithDateProps {
    params: BuildingShowWithDateParams;
}

// Reuse the parent page component but pass the date parameter
export default async function BuildingShowWithDate(props: BuildingShowWithDateProps) {
    return BuildingShow({
        params: {
            id: props.params.id,
        },
        // Pass the date parameter as a custom property
        initialDate: props.params.date
    });
}
