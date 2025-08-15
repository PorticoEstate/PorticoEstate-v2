'use client'
import React, {useMemo, useState} from 'react';
import { useClientTranslation } from '@/app/i18n/ClientTranslationProvider';
import Dialog from "@/components/dialog/mobile-dialog";
import { Button } from "@digdir/designsystemet-react";
import { ChevronDownIcon } from "@navikt/aksel-icons";
import { useVersionSettings, useSetVersionSettings } from '@/service/hooks/version-hooks';

// Version options with translation key support
const VersionSwitcher: React.FC = () => {
  const { t, i18n } = useClientTranslation();
  const [isOpen, setIsOpen] = useState(false);

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
    setIsOpen(false);
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
    <>
      <Button
        onClick={() => setIsOpen(true)}
        variant={"tertiary"}
        color={"accent"}
        data-size={'sm'}
        disabled={isSettingVersion}
      >
        {t(versionLabel)} <ChevronDownIcon width="1.875rem" height="1.875rem" />
      </Button>
      <Dialog open={isOpen} onClose={() => setIsOpen(false)} dialogId={'version-switcher'}>
        <div style={{
          display: 'flex',
          flexDirection: 'column',
          justifyContent: 'center',
          alignItems: 'center',
          height: '100%',
          gap: '5px'
        }}>
          <h3>{t('bookingfrontend.version_choice')}</h3>
          <p>{t('bookingfrontend.which_version_do_you_want')}</p>

          {versions.map((ver) => (
            <Button
              key={ver.key}
              onClick={() => handleVersionChange(ver.key as 'original' | 'new')}
              variant={currentVersion === ver.key ? "secondary" : "tertiary"}
              style={{
                width: '200px',
                display: 'flex',
                flexDirection: 'row',
                justifyContent: 'flex-start'
              }}
            >
              {t(ver.label)}
            </Button>
          ))}
        </div>
      </Dialog>
    </>
  );
};

export default VersionSwitcher;