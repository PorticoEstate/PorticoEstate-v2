import React from 'react';
import {createRoot, Root} from 'react-dom/client';
import BuildingResultItem from '@/components/search/resource/building-result-item';
import {ISearchDataBuilding} from '@/service/types/api/search.types';

import '@digdir/designsystemet-css';
import '@porticoestate/design-tokens';

export interface BuildingPreviewData {
	id: number;
	name: string;
	street?: string;
	city?: string;
	district?: string;
	imageUrl?: string;
	focalPoint?: { x: number; y: number };
	townName?: string;
	short_description?: string;
}

interface Labels {
	buildingTitle: string;
	showAllResources: string;
}

let root: Root | null = null;

function render(
	element: HTMLElement,
	buildings: BuildingPreviewData[],
	lang: string,
	labels: Labels
) {
	if (!root) {
		root = createRoot(element);
	}

	root.render(
		<div className="pe-widget-scope" data-color-scheme="light">
		<div style={{display: 'flex', flexWrap: 'wrap', gap: '1rem'}}>
			{buildings.map(b => {
				const building: Partial<ISearchDataBuilding> = {
					id: b.id,
					name: b.name,
					street: b.street || '',
					city: b.city || '',
					district: b.district || '',
					short_description: b.short_description || null,
				};
				const buildingImage = b.imageUrl
					? {url: b.imageUrl, focalPoint: b.focalPoint}
					: {url: '/resource_placeholder_bilde.png'};

				return (
					<BuildingResultItem
						key={b.id}
						building={building as ISearchDataBuilding}
						selectedDate={null}
						buildingImage={buildingImage}
						townName={b.townName}
						lang={lang}
						labels={labels}
					/>
				);
			})}
		</div>
		</div>
	);
}

function unmount() {
	if (root) {
		root.unmount();
		root = null;
	}
}

(window as any).HighlightedBuildingsWidget = {render, unmount};
