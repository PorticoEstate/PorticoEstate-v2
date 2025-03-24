import {usePathname} from "next/navigation";

export const useCurrentPath = () => {
	const actualPath = usePathname();
	const pathParts = actualPath.split('/').slice(1);
	return pathParts.slice(1).join('/') || '/';
}