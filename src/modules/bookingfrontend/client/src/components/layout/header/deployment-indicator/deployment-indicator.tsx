'use client'
import React, {useEffect, useState} from 'react';
import {Tooltip, Paragraph, Spinner} from '@digdir/designsystemet-react';
import styles from './deployment-indicator.module.scss';

interface VersionStatus {
    state: 'loading' | 'up-to-date' | 'outdated' | 'error';
    deployedCommit?: string;
    latestCommit?: string;
    behindBy?: number;
}

const GITHUB_API_URL =
    'https://api.github.com/repos/PorticoEstate/PorticoEstate-v2/branches/testing';

const DeploymentIndicator: React.FC = () => {
    const [status, setStatus] = useState<VersionStatus>({state: 'loading'});
    const [isTestDomain, setIsTestDomain] = useState(false);

    useEffect(() => {
        if (typeof window === 'undefined') return;

        const allowedHosts = ['test.aktiv-kommune.no', 'pe-api.test'];
        if (!allowedHosts.includes(window.location.hostname)) {
            return;
        }
        setIsTestDomain(true);

        const checkVersion = async () => {
            try {
                const [deployedRes, githubRes] = await Promise.all([
                    fetch('/bookingfrontend/client/api/version'),
                    fetch(GITHUB_API_URL, {
                        headers: {'Accept': 'application/vnd.github.v3+json'},
                    }),
                ]);

                if (!deployedRes.ok || !githubRes.ok) {
                    setStatus({state: 'error'});
                    return;
                }

                const deployed = await deployedRes.json();
                const github = await githubRes.json();

                const deployedCommit = deployed.commitId?.toLowerCase().trim();
                const latestCommit = github.commit?.sha?.toLowerCase().trim();

                if (!deployedCommit || deployedCommit === 'unknown' || !latestCommit) {
                    setStatus({state: 'error'});
                    return;
                }

                if (latestCommit.startsWith(deployedCommit) || deployedCommit.startsWith(latestCommit)) {
                    setStatus({state: 'up-to-date', deployedCommit, latestCommit});
                } else {
                    setStatus({state: 'outdated', deployedCommit, latestCommit});
                }
            } catch {
                setStatus({state: 'error'});
            }
        };

        checkVersion();
    }, []);

    if (!isTestDomain) return null;

    const shortSha = (sha?: string) => sha?.slice(0, 7) ?? '???';

    const getTooltipContent = () => {
        switch (status.state) {
            case 'loading':
                return 'Checking deployment status...';
            case 'up-to-date':
                return `Deployed: ${shortSha(status.deployedCommit)} (up to date)`;
            case 'outdated':
                return `Deployed: ${shortSha(status.deployedCommit)} | Latest: ${shortSha(status.latestCommit)}`;
            case 'error':
                return 'Could not check deployment status';
        }
    };

    const getIndicator = () => {
        switch (status.state) {
            case 'loading':
                return <Spinner data-size="2xs" aria-label="Checking version"/>;
            case 'up-to-date':
                return <span className={`${styles.dot} ${styles.upToDate}`} aria-label="Up to date"/>;
            case 'outdated':
                return <span className={`${styles.dot} ${styles.outdated}`} aria-label="Outdated"/>;
            case 'error':
                return <span className={`${styles.dot} ${styles.error}`} aria-label="Status unknown"/>;
        }
    };

    return (
        <Tooltip content={getTooltipContent()} placement="bottom">
            <button className={styles.indicator} type="button" aria-label={getTooltipContent()}>
                {getIndicator()}
            </button>
        </Tooltip>
    );
};

export default DeploymentIndicator;
