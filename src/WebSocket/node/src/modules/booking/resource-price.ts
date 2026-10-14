/**
 * The citizen's price for a resource (article_cat 1), for the timeslot path.
 *
 * Port of PHP bookingfrontend ArticleRepository::getEligibleResourcePrices,
 * ::preselectedPrice and ::priceLabel. The two lanes must agree on which
 * prices a resource offers, so the SQL is the same text; change both or
 * neither.
 */

export interface EligiblePrice {
  id: number;
  /** Ex. tax, as stored (numeric arrives as a string) */
  price: string;
  remark: string | null;
  default_: number | null;
}

interface Queryable {
  query(text: string, values?: unknown[]): Promise<{ rows: any[] }>;
}

/**
 * Every active price row of the mapping whose from_ has been reached today.
 * from_ is a naive local timestamp, so "today" is the Oslo date: the session
 * runs in UTC, where CURRENT_DATE is still yesterday until 02:00.
 */
export async function getEligibleResourcePrices(
  client: Queryable,
  mappingId: number,
): Promise<EligiblePrice[]> {
  const { rows } = await client.query(
    `SELECT id, price, remark, default_
     FROM bb_article_price
     WHERE article_mapping_id = $1
     AND active = 1
     AND from_ < (now() AT TIME ZONE 'Europe/Oslo')::date + 1
     ORDER BY price, id`,
    [mappingId],
  );
  return rows;
}

/**
 * The option a resource's price starts out as: the only one, or else the
 * default. Two or more default rows are no default at all. Null means the
 * citizen has to choose, or that there is nothing to choose between.
 */
export function preselectedPrice(options: EligiblePrice[]): EligiblePrice | null {
  if (options.length === 1) {
    return options[0];
  }
  const defaults = options.filter((option) => Number(option.default_) === 1);
  return defaults.length === 1 ? defaults[0] : null;
}

/**
 * The label stored with the order line: the remark, or the amount when the
 * remark is empty. Only a real choice gets one.
 */
export function priceLabel(option: EligiblePrice, optionCount: number): string | null {
  if (optionCount < 2) {
    return null;
  }
  const remark = (option.remark ?? '').trim();
  return remark !== '' ? remark : parseFloat(option.price).toFixed(2);
}
