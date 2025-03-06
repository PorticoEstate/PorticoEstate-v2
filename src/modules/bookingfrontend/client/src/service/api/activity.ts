import { useQuery } from "@tanstack/react-query";
import { phpGWLink } from "@/service/util";
import { Activity } from "../types/api/activity.types";

export const useActivityList = (orgId: number) => {
    return useQuery({
        queryKey: ['organization', 'activities'],
        queryFn: async (): Promise<Activity[]> => {
            const url = phpGWLink(['bookingfrontend', 'organization', orgId, 'activities']);
            const res = await fetch(url);
            const { data } = await res.json();
            return JSON.parse(data);
        }
    })
}