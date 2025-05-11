'use client';

import { FC, useCallback } from 'react';
import { Button } from "@digdir/designsystemet-react";
import { WebSocketService } from '@/service/websocket/websocket-service';
import { useQueryClient } from '@tanstack/react-query';

interface PageProps {
}

const Page: FC<PageProps> = (props) => {
    const queryClient = useQueryClient();

    const handleRefreshPartialApplications = useCallback(() => {
        try {
            // Get websocket service instance
            const wsService = WebSocketService.getInstance();

            // Send custom message to request partial applications refresh
            wsService.sendMessage(
                'get_partial_applications',
                'Requesting partial applications refresh',
                { timestamp: new Date().toISOString() }
            );

            // Also invalidate the query cache for immediate UI refresh
            // queryClient.invalidateQueries({ queryKey: ['partialApplications'] });

            console.log('Sent websocket message to refresh partial applications');
        } catch (error) {
            console.error('Error sending websocket message:', error);
        }
    }, [queryClient]);

    return (
        <div>
            <div>Placeholder page</div>
            <div style={{display: 'flex', gap: '1.5rem', flexWrap: 'wrap', marginBottom: '2rem'}}>
                <Button variant={"primary"} data-color={'accent'}>Accent</Button>
                <Button variant={"primary"} data-color={'neutral'}>neutral</Button>
                <Button variant={"primary"} data-color={'brand1'}>brand1</Button>
                <Button variant={"primary"} data-color={'brand2'}>brand2</Button>
                <Button variant={"primary"} data-color={'brand3'}>brand3</Button>
                {/*<Button variant={"primary"} data-color={'info'}>info</Button>*/}
                {/*<Button variant={"primary"} data-color={'success'}>success</Button>*/}
                {/*<Button variant={"primary"} data-color={'warning'}>warning</Button>*/}
            </div>

            <div style={{marginTop: '2rem', padding: '1rem', border: '1px solid #ddd', borderRadius: '0.5rem'}}>
                <h2 style={{marginBottom: '1rem'}}>WebSocket Test</h2>
                <Button
                    variant="primary"
                    data-color="brand1"
                    onClick={handleRefreshPartialApplications}
                >
                    Refresh Partial Applications via WebSocket
                </Button>
                <p style={{marginTop: '0.5rem', fontSize: '0.875rem', color: '#666'}}>
                    This button sends a WebSocket message with type "get_partial_applications"
                    and invalidates the React Query cache.
                </p>
            </div>
        </div>
    );
}

export default Page

