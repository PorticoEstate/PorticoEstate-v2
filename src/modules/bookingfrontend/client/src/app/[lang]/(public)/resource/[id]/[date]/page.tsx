import Resource from '../page';

interface ResourceShowWithDateParams {
    id: string;
    date: string;
}

interface ResourceShowWithDateProps {
    params: Promise<ResourceShowWithDateParams>;
}

// Reuse the parent page component but pass the date parameter
export default async function ResourceShowWithDate(props: ResourceShowWithDateProps) {
    const params = await props.params;
    
    return Resource({
        params: Promise.resolve({
            id: params.id,
        }),
        // Pass the date parameter as a custom property
        initialDate: params.date
    });
}