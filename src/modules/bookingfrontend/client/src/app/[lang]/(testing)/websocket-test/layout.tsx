import WebSocketTestClientLayout from './client-layout';

export default function WebSocketTestLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <WebSocketTestClientLayout>
      {children}
    </WebSocketTestClientLayout>
  );
}