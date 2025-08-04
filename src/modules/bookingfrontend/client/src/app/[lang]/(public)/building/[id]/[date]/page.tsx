import BuildingShow from '../page';

interface BuildingShowWithDateParams {
    id: string;
    date: string;
}

interface BuildingShowWithDateProps {
    params: Promise<BuildingShowWithDateParams>;
}

// Reuse the parent page component but pass the date parameter
export default async function BuildingShowWithDate(props: BuildingShowWithDateProps) {
    const params = await props.params;
    
    return BuildingShow({
        params: Promise.resolve({
            id: params.id,
        }),
        // Pass the date parameter as a custom property
        initialDate: params.date
    });
}
