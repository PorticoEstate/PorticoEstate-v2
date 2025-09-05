'use client'
import React from 'react';
import { useClientTranslation } from '@/app/i18n/ClientTranslationProvider';
import { Button, Dropdown } from "@digdir/designsystemet-react";
import { ChevronDownIcon } from "@navikt/aksel-icons";
import { useVersionSettings, useSetVersionSettings } from '@/service/hooks/version-hooks';

// Version options with translation key support
const VersionSwitcher: React.FC = () => {
  const { t, i18n } = useClientTranslation();

  // Fetch current version settings
  const { data: versionSettings, isLoading } = useVersionSettings();

  // Mutation to set version
  const { mutate: setVersion, isPending: isSettingVersion } = useSetVersionSettings();

  // Get localized versions
  const versions = [
		  { key: 'original', label: 'common.original' },
		  { key: 'new', label: 'common.new' }
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

  return (
    <Dropdown.TriggerContext>
      <Dropdown.Trigger
        variant={"tertiary"}
        color={"accent"}
        data-size={'sm'}
        disabled={isSettingVersion}
      >
        {t(versionLabel)} <ChevronDownIcon width="1.875rem" height="1.875rem" />
      </Dropdown.Trigger>
      <Dropdown>
        <Dropdown.List>
          {versions.map((ver) => (
            <Dropdown.Item key={ver.key}>
              <Dropdown.Button
                onClick={() => handleVersionChange(ver.key as 'original' | 'new')}
                style={{
                  fontWeight: currentVersion === ver.key ? 'bold' : 'normal'
                }}
              >
                {t(ver.label)}
              </Dropdown.Button>
            </Dropdown.Item>
          ))}
        </Dropdown.List>
      </Dropdown>
    </Dropdown.TriggerContext>
  );
};

export default VersionSwitcher;