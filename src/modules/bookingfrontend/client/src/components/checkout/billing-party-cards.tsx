'use client';
import {FC, MouseEvent, RefObject} from 'react';
import {Button, Card, Fieldset, Paragraph, Radio, Spinner} from '@digdir/designsystemet-react';
import {PencilIcon} from '@navikt/aksel-icons';
import {useTrans} from '@/app/i18n/ClientTranslationProvider';
import styles from './checkout.module.scss';

export interface BillingPartyOption {
	key: string;
	title: string;
	/** Short facts about the party, one per line; empty values are left out */
	lines: (string | null | undefined)[];
}

interface BillingPartyCardsProps {
	options: BillingPartyOption[];
	selectedKey: string;
	/** The party being picked while its details are looked up */
	pendingKey: string | null;
	onSelect: (key: string) => void;
	onEdit: () => void;
	editButtonRef: RefObject<HTMLButtonElement>;
}

/**
 * The billing parties the citizen can choose between, as cards: one for themselves, one per organisation
 * they can act for and, after an edit in the dialog, one with the custom details. With only one party
 * there is nothing to choose, so it is a plain card. «Rediger» opens the full billing form.
 */
const BillingPartyCards: FC<BillingPartyCardsProps> = ({options, selectedKey, pendingKey, onSelect, onEdit, editButtonRef}) => {
	const t = useTrans();
	const checkedKey = pendingKey ?? selectedKey;

	const renderLines = (option: BillingPartyOption) => option.lines
		.filter((line): line is string => !!line)
		.map((line, index) => <span key={index} className={styles.billingPartyLine}>{line}</span>);

	const editButton = (
		<Button
			ref={editButtonRef}
			type="button"
			variant="secondary"
			data-size="sm"
			onClick={onEdit}
			aria-label={t('bookingfrontend.edit_billing_information')}
		>
			<PencilIcon aria-hidden="true"/>
			{t('bookingfrontend.edit')}
		</Button>
	);

	if (options.length === 1) {
		const [only] = options;
		return (
			<div className={styles.billingParties}>
				<Card data-color="neutral" className={styles.billingPartyCard}>
					<Paragraph><strong>{only.title}</strong></Paragraph>
					<Paragraph data-size="sm">{renderLines(only)}</Paragraph>
				</Card>
				{editButton}
			</div>
		);
	}

	// The whole card picks the party; the radio and its label already do that themselves
	const selectFromCard = (event: MouseEvent<HTMLDivElement>, key: string) => {
		if ((event.target as HTMLElement).closest('input, label')) {
			return;
		}
		onSelect(key);
	};

	return (
		<div className={styles.billingParties}>
			<Fieldset className={styles.billingPartyFieldset}>
				<Fieldset.Legend>{t('bookingfrontend.choose_billing_party')}</Fieldset.Legend>
				<div className={styles.billingPartyList}>
					{options.map(option => {
						const checked = option.key === checkedKey;
						return (
							<Card
								key={option.key}
								data-color={checked ? 'accent' : 'neutral'}
								variant={checked ? 'tinted' : 'default'}
								className={styles.billingPartyCard}
								onClick={(event) => selectFromCard(event, option.key)}
							>
								<div className={styles.billingPartyRow}>
									<Radio
										name="billingParty"
										value={option.key}
										checked={checked}
										onChange={() => onSelect(option.key)}
										label={option.title}
										description={renderLines(option)}
									/>
									{option.key === pendingKey && (
										<Spinner data-size="sm" aria-label={t('common.loading')}/>
									)}
								</div>
							</Card>
						);
					})}
				</div>
			</Fieldset>
			{editButton}
		</div>
	);
};

export default BillingPartyCards;
