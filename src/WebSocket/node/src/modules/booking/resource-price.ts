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
  /** The row's price category (bb_price_category, ref #1477); null without one */
  category_id: number | null;
  category_name: string | null;
}

interface Queryable {
  query(text: string, values?: unknown[]): Promise<{ rows: any[] }>;
}

let categoriesInstalled = false;

/**
 * Whether the booking migration m20261103_create_bb_price_category has run
 * (PHP: PriceCategoryRepository::isInstalled). Until it has, prices are read
 * and lines written without categories. Once seen it is remembered, since a
 * migration is not undone; until then it is asked again, so this process
 * picks the migration up without a restart.
 *
 * Transitional: once every environment has run the migration, this and the
 * branches on it can go.
 */
export async function priceCategoriesInstalled(client: Queryable): Promise<boolean> {
  if (!categoriesInstalled) {
    const { rows } = await client.query(
      `SELECT to_regclass('public.bb_price_category') IS NOT NULL
        AND (SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = 'public' AND column_name = 'category_id'
          AND table_name IN ('bb_article_price', 'bb_purchase_order_line')) = 2 AS installed`,
    );
    categoriesInstalled = rows[0]?.installed === true;
  }
  return categoriesInstalled;
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
  const category = (await priceCategoriesInstalled(client))
    ? `p.category_id, c.name AS category_name
                FROM bb_article_price p
                LEFT JOIN bb_price_category c ON c.id = p.category_id`
    : `NULL AS category_id, NULL AS category_name
                FROM bb_article_price p`;

  const { rows } = await client.query(
    `SELECT p.id, p.price, p.remark, p.default_, ${category}
                WHERE p.article_mapping_id = $1
                AND p.active = 1
                AND p.from_ < (now() AT TIME ZONE 'Europe/Oslo')::date + 1
                ORDER BY p.price, p.id`,
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
 * What a price is called where the citizen sees it: its category's name when
 * it has one, otherwise the remark.
 */
export function optionLabel(option: EligiblePrice): string {
  const categoryName = (option.category_name ?? '').trim();
  return categoryName !== '' ? categoryName : (option.remark ?? '').trim();
}

/**
 * The label stored with the order line: the option's label (its category, or
 * else its remark), or the amount when that is empty. Only a real choice gets one.
 */
export function priceLabel(option: EligiblePrice, optionCount: number): string | null {
  if (optionCount < 2) {
    return null;
  }
  const label = optionLabel(option);
  return label !== '' ? label : parseFloat(option.price).toFixed(2);
}
