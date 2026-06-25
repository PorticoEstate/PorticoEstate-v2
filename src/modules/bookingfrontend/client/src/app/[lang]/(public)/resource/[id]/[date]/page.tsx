import Resource from '../page';

interface ResourceShowWithDateParams {
    id: string;
    date: string;
}

interface ResourceShowWithDateProps {
    params: ResourceShowWithDateParams;
}

// Reuse the parent page component but pass the date parameter
export default async function ResourceShowWithDate(props: ResourceShowWithDateProps) {
    return Resource({
        params: {
            id: props.params.id,
        },
        // Pass the date parameter through searchParams
        searchParams: {
            initialDate: props.params.date,
        },
    });
}