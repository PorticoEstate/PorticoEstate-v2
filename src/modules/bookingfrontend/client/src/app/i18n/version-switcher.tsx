'use client'
import React from 'react';
import {useClientTranslation} from '@/app/i18n/ClientTranslationProvider';
import {Button, Dropdown} from "@digdir/designsystemet-react";
import {ChevronDownIcon} from "@navikt/aksel-icons";
import {useVersionSettings, useSetVersionSettings} from '@/service/hooks/version-hooks';
import InlineResponsiveDropdown from '@/components/common/inline-responsive-dropdown/inline-responsive-dropdown';

// Version options with translation key support
const VersionSwitcher: React.FC = () => {
	const {t, i18n} = useClientTranslation();

	// Fetch current version settings
	const {data: versionSettings, isLoading} = useVersionSettings();

	// Mutation to set version
	const {mutate: setVersion, isPending: isSettingVersion} = useSetVersionSettings();

	// Get localized versions
	const versions = [
		{key: 'original', label: 'common.original'},
		{key: 'new', label: 'common.new'}
	];

	// Determine current version
	const currentVersion = versionSettings?.version || 'original';

	// Handle version change
	const handleVersionChange = (version: 'original' | 'new') => {
		setVersion(version);
	};

	// If loading, show empty button
	if (isLoading) {
		return (
			<Button
				variant={"tertiary"}
				color={"accent"}
				data-size={'sm'}
			>
				<span>...</span>
			</Button>
		);
	}

	// Current version display
	const versionLabel = versions.find(v => v.key === currentVersion)?.label || t('common.version', 'Version');

	// Prepare options for the dropdown
	const dropdownOptions = versions.map(ver => ({
		value: ver.key,
		label: t(ver.label)
	}));

	return (
		<InlineResponsiveDropdown
			triggerContent={
					t(versionLabel)
			}
			title={t('common.version')}
			options={dropdownOptions}
			currentValue={currentVersion}
			onValueChange={(value) => handleVersionChange(value as 'original' | 'new')}
			disabled={isSettingVersion}
		/>
	);
};

export default VersionSwitcher;