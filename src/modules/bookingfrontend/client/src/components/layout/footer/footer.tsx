import styles from './footer.module.scss'
import {fetchServerSettings} from "@/service/api/api-utils";
import {getTranslation} from "@/app/i18n";
import Link from "next/link";
import FooterUser from "@/components/layout/footer/footer-user";

interface FooterProps {
	lang: string
}

const Footer = async (props: FooterProps) => {
	const {t} = await getTranslation(props.lang)
	const serverSettings = await fetchServerSettings();


	const supportAddress = serverSettings.booking_config?.support_address || serverSettings.support_address;

	return (
		<footer className={styles.footerContainer}>
			<div className={styles.footerContent}>

				<div className={styles.footerLogoContainer}>
					{/*  LOGO */}
					{serverSettings.bakcground_image ? (
						<Link href={serverSettings.bakcground_image} target="_blank" rel="noopener noreferrer">
							<img
								className={styles.footerLogo}
								src={serverSettings.logo_url} alt={serverSettings.logo_title}
							/>
						</Link>
					) : (
						<img
							className={styles.footerLogo}
							src={serverSettings.logo_url} alt={serverSettings.logo_title}
						/>
					)}
				</div>
				<div>
					{/*Contact */}
					<h3 className={'text-body'}>
						{t('booking.contact')}
					</h3>
					<ul className={'list-unstyled text-small'}>
						<li>
							<Link href={`mailto:${supportAddress}`} target="_blank"
								  rel="noopener noreferrer" className="link-text link-text-secondary normal">
								{supportAddress}
							</Link>
						</li>
						{serverSettings.bookingfrontend_config?.url_system_feedback && (
							<li>
								<Link href={serverSettings.bookingfrontend_config.url_system_feedback} target="_blank"
									  rel="noopener noreferrer" className="link-text link-text-secondary normal">
									{t('common.error_report_system')}
								</Link>
							</li>
						)}
						{serverSettings.bookingfrontend_config?.url_uustatus && (
							<li>
								<Link href={serverSettings.bookingfrontend_config.url_uustatus} target="_blank"
									  rel="noopener noreferrer" className="link-text link-text-secondary normal">
									{t('bookingfrontend.uustatus')}
								</Link>
							</li>
						)}
					</ul>
				</div>
				<div>
					{/*About*/}
					<h3 className={'text-body'}>
						Aktiv kommune
					</h3>
					<ul className={'list-unstyled text-small'}>
						<li>
							<Link href="https://www.aktiv-kommune.no/" target="_blank" rel="noopener noreferrer"
								  className="link-text link-text-secondary normal">
								{t('bookingfrontend.about aktive kommune')}
							</Link>
						</li>
						<li>
							<Link href="https://www.aktiv-kommune.no/manual/" target="_blank" rel="noopener noreferrer"
								  className="link-text link-text-secondary normal">
								{t('common.manual')}
							</Link>
						</li>
						<li>
							<Link href={"https://www.aktiv-kommune.no/hva-er-aktivkommune/"} target="_blank"
								  rel="noopener noreferrer" className="link-text link-text-secondary normal">
								{t('bookingfrontend.privacy')}
							</Link>
						</li>
						<li>
							<Link href={`/${props.lang}/purchase-conditions`}
								  className="link-text link-text-secondary normal">
								{t('bookingfrontend.purchase_conditions')}
							</Link>
						</li>
					</ul>

				</div>
				{/*<div>*/}
				{/*    /!*User*!/*/}
				{/*    <h3 className={'text-body'}>*/}
				{/*        {t('common.login')}*/}
				{/*    </h3>*/}
				{/*    <FooterUser />*/}

				{/*</div>*/}
			</div>
		</footer>
	);
}

export default Footer


