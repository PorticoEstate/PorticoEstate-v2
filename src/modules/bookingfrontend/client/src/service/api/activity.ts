import { useQuery } from "@tanstack/react-query";
import { phpGWLink } from "@/service/util";
import { Activity } from "../types/api/activity.types";

export const useActivityList = () => {
    return useQuery({
        queryKey: ['activity'],
        retry: 2,
        queryFn: async (): Promise<Activity[]> => {
            const url = phpGWLink(['bookingfrontend', 'activity']);
            const res = await fetch(url);
            return await res.json();
        }
    })
}