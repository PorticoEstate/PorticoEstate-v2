import ApplicationDetails from "@/components/user/application/application-details";
import {fetchApplication} from "@/service/api/api-utils";
interface ApplicationDetailProps {
  params: Promise<{
    id: string;
  }>
}



const ApplicationDetailPage = async ({params}: ApplicationDetailProps) => {
  const {id} = await params;
  const applicationId = parseInt(id, 10);
  const initialApplication = await fetchApplication(applicationId);
  return <ApplicationDetails applicationId={applicationId} initialApplication={initialApplication} />
};

export default ApplicationDetailPage;