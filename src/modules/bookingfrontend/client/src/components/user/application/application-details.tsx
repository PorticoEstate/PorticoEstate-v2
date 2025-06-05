'use client'
import React, {FC} from 'react';
import {IApplication} from "@/service/types/api/application.types";
import {useApplication, useResourceRegulationDocuments} from "@/service/hooks/api-hooks";
import {Card, Heading, Paragraph, Spinner, Link as DigdirLink} from "@digdir/designsystemet-react";
import ApplicationComments from "./application-comments";
import PageHeader from "@/components/page-header/page-header";
import navStyles from "@/components/layout/header/internal-nav/internal-nav.module.scss";
import styles from "./application-details.module.scss";
import Link from "next/link";
import {FontAwesomeIcon} from "@fortawesome/react-fontawesome";
import {faArrowLeft} from "@fortawesome/free-solid-svg-icons";
import {DateTime} from "luxon";
import ResourceCircles from "@/components/resource-circles/resource-circles";
import GSAccordion from "@/components/gs-accordion/g-s-accordion";
import {useTrans} from "@/app/i18n/ClientTranslationProvider";
import {getDocumentLink} from "@/service/api/building";

interface ApplicationDetailsProps {
	initialApplication?: IApplication;
	applicationId: number;
}

const ApplicationDetails: FC<ApplicationDetailsProps> = (props) => {
	const {data: application, isLoading, error} = useApplication(props.applicationId, {
		initialData: props.initialApplication
	});
	const {data: regulationDocuments} = useResourceRegulationDocuments(
		application?.resources || []
	);
	const t = useTrans();
	console.log(application);

	if (isLoading) {
		return (
			<div style={{display: 'flex', justifyContent: 'center', padding: '2rem'}}>
				<Spinner data-size="lg" aria-label={t('common.loading')}/>
			</div>
		);
	}

	if (error || !application) {
		return (
			<main>
				<PageHeader title={t('bookingfrontend.application')}/>
				<Card>
					<Heading level={2} data-size="sm">{t('common.error')}</Heading>
					<Paragraph>{t('common.application not found')}</Paragraph>
				</Card>
			</main>
		);
	}

	return (
		<main>
			<div>
				<div className={`${navStyles.internalNavContainer}`}>
					<Link className={'link-text link-text-primary'} href={'/user/applications'}>
						<FontAwesomeIcon icon={faArrowLeft}/>
						{t('common.back')}
					</Link>
				</div>
			</div>
			<PageHeader title={application.name || t('bookingfrontend.application')}/>
			<div>
				<div className="font-size-h4" style={{color: 'var(--ds-color-text-subtle)', fontWeight: 400}}>
					#{application.id}
				</div>
			</div>

			<Card className={styles.responsiveCard}>
				<div className={styles.contentGrid}>
					<div className={styles.detailItem}>
						<div>
							<Heading level={3} data-size="xs">{t('bookingfrontend.status')}</Heading>
						</div>
						<div>
							<Paragraph>
								<span className={`status-badge status-${application.status.toLowerCase()}`}>
									{t(`bookingfrontend.${application.status.toLowerCase()}`)}
								</span>
							</Paragraph>
						</div>
					</div>

					<div className={styles.detailItem}>
						<div>
							<Heading level={3} data-size="xs">{t('bookingfrontend.created')}</Heading>
						</div>
						<div>
							<Paragraph>{DateTime.fromSQL(application.created).toFormat('dd.MM.yyyy')}</Paragraph>
						</div>
					</div>

					<div className={styles.detailItem}>
						<div>
							<Heading level={3} data-size="xs">{t('bookingfrontend.modified')}</Heading>
						</div>
						<div>
							<Paragraph>{DateTime.fromSQL(application.modified).toFormat('dd.MM.yyyy HH:mm')}</Paragraph>
						</div>
					</div>

					<div className={styles.detailItem}>
						<div>
							<Heading level={3} data-size="xs">{t('bookingfrontend.where')}</Heading>
						</div>
						<div>
							<Paragraph>{application.building_name}</Paragraph>
						</div>
					</div>

					<div className={styles.detailItem}>
						<div>
							<Heading level={3} data-size="xs">{t('bookingfrontend.resources')}</Heading>
						</div>
						<div>
							<ResourceCircles resources={application.resources} maxCircles={4} size={'small'} expandable/>
						</div>
					</div>

					<div className={styles.detailItem}>
						<div>
							<Heading level={3} data-size="xs">{t('bookingfrontend.participants')}</Heading>
						</div>
						<div>
							<Paragraph>
								{application.agegroups && application.agegroups.length > 0 
									? application.agegroups.reduce((total, agegroup) => {
										const groupTotal = agegroup.female ? (agegroup.male + agegroup.female) : agegroup.male;
										return total + groupTotal;
									}, 0)
									: 0
								}
							</Paragraph>
						</div>
					</div>

					<div className={styles.detailItem}>
						<div>
							<Heading level={3} data-size="xs">{t('bookingfrontend.start_time')}</Heading>
						</div>
						<div>
							<div>
								{application.dates.map((date, index) => (
									<Paragraph key={index}>
										{DateTime.fromISO(date.from_).toFormat('dd.MM.yyyy HH:mm')}
									</Paragraph>
								))}
							</div>
						</div>
					</div>

					<div className={styles.detailItem}>
						<div>
							<Heading level={3} data-size="xs">{t('bookingfrontend.end_time')}</Heading>
						</div>
						<div>
							<div>
								{application.dates.map((date, index) => (
									<Paragraph key={index}>
										{DateTime.fromISO(date.to_).toFormat('dd.MM.yyyy HH:mm')}
									</Paragraph>
								))}
							</div>
						</div>
					</div>

				</div>
			</Card>

			{application.description && (
				<Card className={styles.responsiveCard} style={{marginTop: '1rem'}}>
					<Heading level={3} data-size="xs">{t('bookingfrontend.description')}</Heading>
					<Paragraph>{application.description}</Paragraph>
				</Card>
			)}

			<section className="my-2">
				<ApplicationComments
					applicationId={props.applicationId}
					secret={application?.secret || undefined}
				/>

				<GSAccordion data-color="neutral">
					<GSAccordion.Heading>
						<h3>{t('bookingfrontend.information about the event')}</h3>
					</GSAccordion.Heading>
					<GSAccordion.Content>
						<Paragraph>{t('bookingfrontend.no information available')}</Paragraph>
					</GSAccordion.Content>
				</GSAccordion>

				<GSAccordion data-color="neutral">
					<GSAccordion.Heading>
						<h3>{t('bookingfrontend.agegroup')}</h3>
					</GSAccordion.Heading>
					<GSAccordion.Content>
						{application.agegroups && application.agegroups.length > 0 ? (
							<div>
								{application.agegroups.map((agegroup, index) => (
									<div key={index} style={{marginBottom: '0.5rem'}}>
										<Heading level={4} data-size="xs">{agegroup.name}</Heading>
										<Paragraph>
											{t('bookingfrontend.participants')}: {agegroup.female ? (agegroup.male + agegroup.female) : agegroup.male}
										</Paragraph>
									</div>
								))}
							</div>
						) : (
							<Paragraph>{t('bookingfrontend.no agegroup information')}</Paragraph>
						)}
					</GSAccordion.Content>
				</GSAccordion>

				<GSAccordion data-color="neutral">
					<GSAccordion.Heading>
						<h3>{t('bookingfrontend.contact & invoice')}</h3>
					</GSAccordion.Heading>
					<GSAccordion.Content>
						<div style={{marginBottom: '1rem'}}>
							<Heading level={4} data-size="xs">{t('bookingfrontend.contact')}</Heading>
							<Paragraph>{application.contact_name}</Paragraph>
							<Paragraph>{application.contact_email}</Paragraph>
							<Paragraph>{application.contact_phone}</Paragraph>
						</div>

						{application.customer_organization_name && (
							<div>
								<Heading level={4} data-size="xs">{t('bookingfrontend.organization')}</Heading>
								<Paragraph>{application.customer_organization_name}</Paragraph>
								{application.customer_organization_number && (
									<Paragraph>{t('bookingfrontend.organization number')}: {application.customer_organization_number}</Paragraph>
								)}
							</div>
						)}
					</GSAccordion.Content>
				</GSAccordion>

				{regulationDocuments && regulationDocuments.length > 0 && (
					<GSAccordion data-color="neutral">
						<GSAccordion.Heading>
							<h3>{t('bookingfrontend.terms and conditions')}</h3>
						</GSAccordion.Heading>
						<GSAccordion.Content>
							<div>
								{regulationDocuments.map((doc) => (
									<div key={doc.id} style={{marginBottom: '0.5rem'}}>
										<DigdirLink
											href={getDocumentLink(doc, doc.owner_type || 'resource')}
											target="_blank"
										>
											{doc.name}
										</DigdirLink>
									</div>
								))}
							</div>
						</GSAccordion.Content>
					</GSAccordion>
				)}
			</section>
		</main>
	);
}

export default ApplicationDetails